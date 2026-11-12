<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SalesActualItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi harga Penjualan Barang Sisa di Sales Actual yang sudah disubmit
 * tetapi belum masuk closing harian.
 *
 * Kasus 12 Nov 2026: harga diketik 15.000 lalu langsung Submit tanpa Simpan
 * Perubahan, sehingga yang tersubmit harga lama 17.500 (tombol Submit sudah
 * diperbaiki di v3.2.6). Hanya baris Barang Sisa (is_carry_forward) yang
 * harganya memang boleh diubah; harga menu PO tetap dari PO. Sesudah closing
 * tidak bisa lagi karena kas sudah diposting. Tanpa --execute hanya uji-jalan.
 */
class CorrectLeftoverSalePriceCommand extends Command
{
    protected $signature = 'sales-actual:koreksi-harga-barang-sisa
        {items* : ID baris Penjualan Barang Sisa (sales_actual_items.id)}
        {--harga= : Harga satuan yang benar}
        {--alasan= : Alasan koreksi, dicatat di audit log}
        {--execute : Benar-benar mengubah; tanpa ini hanya uji-jalan}';

    protected $description = 'Koreksi harga Penjualan Barang Sisa di Sales Actual tersubmit yang belum closing';

    public function handle(): int
    {
        $price = $this->option('harga');

        if (! is_numeric($price) || (float) $price < 0) {
            $this->error('Isi --harga dengan angka 0 atau lebih.');

            return self::FAILURE;
        }

        $price = round((float) $price, 2);
        $ids = array_map('intval', (array) $this->argument('items'));
        $items = SalesActualItem::query()->with('salesActual.customer')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $error = $this->refusal($id, $items->get($id));

            if ($error !== null) {
                $this->error($error);

                return self::FAILURE;
            }
        }

        $this->table(['Baris', 'Barang', 'Customer', 'SA', 'Qty', 'Harga lama', 'Harga baru', 'Subtotal baru'], $items->map(fn (SalesActualItem $item) => [
            $item->id,
            $item->item_name,
            $item->salesActual->customer?->name,
            '#'.$item->sales_actual_id.' ('.$item->salesActual->sales_date->format('d/m/Y').')',
            $item->qty_actual,
            number_format((float) $item->unit_price, 0, ',', '.'),
            number_format($price, 0, ',', '.'),
            number_format(round((float) $item->qty_actual * $price, 2), 0, ',', '.'),
        ])->values()->all());

        if (! $this->option('execute')) {
            $this->warn('Uji-jalan: belum ada yang diubah. Jalankan ulang dengan --execute.');

            return self::SUCCESS;
        }

        $reason = trim((string) $this->option('alasan')) ?: 'harga yang diketik saat submit tidak tersimpan';

        DB::transaction(function () use ($items, $price, $reason) {
            foreach ($items as $item) {
                $before = ['unit_price' => (float) $item->unit_price, 'subtotal_actual' => (float) $item->subtotal_actual];

                // Lewat save(): subtotal_actual dihitung ulang oleh hook model.
                $item->forceFill(['unit_price' => $price])->save();

                AuditLog::query()->create([
                    'user_id' => null,
                    'entity' => 'sales_actual',
                    'entity_id' => $item->sales_actual_id,
                    'purchase_order_id' => null,
                    'action' => 'sales_actual_item_price_corrected',
                    'message' => sprintf(
                        'Harga Barang Sisa %s (%s) di Sales Actual #%d dikoreksi dari Rp %s ke Rp %s: %s.',
                        $item->item_name,
                        number_format((float) $item->qty_actual, 2, ',', '.'),
                        $item->sales_actual_id,
                        number_format($before['unit_price'], 0, ',', '.'),
                        number_format($price, 0, ',', '.'),
                        $reason,
                    ),
                    'before_json' => $before + ['item_id' => $item->id],
                    'after_json' => ['unit_price' => $price, 'subtotal_actual' => (float) $item->subtotal_actual, 'item_id' => $item->id],
                    'ip_address' => null,
                ]);
            }
        });

        $this->info($items->count().' baris dikoreksi.');

        return self::SUCCESS;
    }

    protected function refusal(int $id, ?SalesActualItem $item): ?string
    {
        if (! $item) {
            return "Baris #{$id} tidak ditemukan.";
        }

        if (! $item->is_carry_forward) {
            return "Baris #{$id} ({$item->item_name}) bukan Penjualan Barang Sisa; harganya mengikuti PO.";
        }

        $salesActual = $item->salesActual;

        if (! $salesActual->isSubmitted()) {
            return "Sales Actual #{$salesActual->id} belum disubmit; ubah harganya lewat halaman edit di Sales App.";
        }

        if ($salesActual->sales_daily_closing_id !== null) {
            return "Sales Actual #{$salesActual->id} sudah masuk closing harian; kasnya sudah diposting, koreksi lewat Accounting.";
        }

        return null;
    }
}
