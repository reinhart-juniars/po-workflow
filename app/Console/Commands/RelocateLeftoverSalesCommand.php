<?php

namespace App\Console\Commands;

use App\Models\SalesActual;
use App\Services\LeftoverSaleRelocator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pindahkan Penjualan Barang Sisa yang salah masuk ke Sales Actual lama ke
 * draft customer yang sama di tanggal yang benar (lihat LeftoverSaleRelocator).
 * Tanpa --execute hanya menampilkan rencana.
 */
class RelocateLeftoverSalesCommand extends Command
{
    protected $signature = 'sales-actual:pindah-barang-sisa
        {items* : ID baris Penjualan Barang Sisa (sales_actual_items.id)}
        {--date= : Tanggal Sales Actual tujuan, Y-m-d (bawaan: hari ini)}
        {--cash-account= : Akun kas Tunai untuk baris yang ikut PO dari Sales Actual lama}
        {--execute : Benar-benar memindahkan; tanpa ini hanya uji-jalan}';

    protected $description = 'Pindahkan Penjualan Barang Sisa yang salah masuk Sales Actual lama ke tanggal yang benar';

    public function handle(LeftoverSaleRelocator $relocator): int
    {
        $itemIds = array_map('intval', (array) $this->argument('items'));
        $date = Carbon::parse($this->option('date') ?: now()->toDateString())->startOfDay();
        $cashAccountId = filled($this->option('cash-account')) ? (int) $this->option('cash-account') : null;

        try {
            $plan = $this->option('execute')
                ? $relocator->relocate($itemIds, $date, $cashAccountId)
                : $relocator->plan($itemIds, $date, $cashAccountId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Baris', 'Barang', 'Qty', 'Customer', 'Dari SA', 'Ke tgl', 'Cara bayar'], collect($plan['moves'])->map(fn (array $move) => [
            $move['item']->id,
            $move['item']->item_name,
            $move['item']->qty_actual,
            $move['customer']?->name,
            '#'.$move['from']->id.' ('.$move['from']->sales_date->format('d/m/Y').')',
            isset($plan['targets']) ? '#'.$plan['targets'][$move['from']->customer_id]->id.' ('.$date->format('d/m/Y').')' : $date->format('d/m/Y'),
            $move['cash_account_id'] ? 'Tunai kas #'.$move['cash_account_id'] : 'ikut PO #'.$move['purchase_order_id'],
        ])->all());

        foreach ($plan['reopen'] as $row) {
            /** @var SalesActual $sa */
            $sa = $row['sales_actual'];
            $this->line(sprintf('SA #%d tgl %s (disubmit %s) %s ke draft.', $sa->id, $sa->sales_date->format('d/m/Y'), $sa->submitted_at?->format('d/m/Y H:i') ?? '-', $this->option('execute') ? 'dikembalikan' : 'akan dikembalikan'));
        }

        if (! $this->option('execute')) {
            $this->warn('Uji-jalan: belum ada yang diubah. Jalankan ulang dengan --execute.');

            return self::SUCCESS;
        }

        $this->info(count($plan['moves']).' baris dipindah. Submit draft tujuannya di Sales App supaya masuk laporan dan closing harian.');

        return self::SUCCESS;
    }
}
