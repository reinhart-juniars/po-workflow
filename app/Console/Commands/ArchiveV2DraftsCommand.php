<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nonaktifkan draft Sales Actual V2 (bertanggal sebelum 1 Nov 2026) yang tidak
 * pernah disubmit. Draft seperti itu sempat menampung Penjualan Barang Sisa
 * November dan ikut tersubmit (kasus Rice Bowl Dori, 12 Nov 2026).
 *
 * Draft yang berisi Penjualan Barang Sisa dari V3 dilewati: pindahkan dulu
 * dengan sales-actual:pindah-barang-sisa supaya penjualannya tidak ikut
 * nonaktif. Tanpa --execute hanya menampilkan daftar.
 */
class ArchiveV2DraftsCommand extends Command
{
    protected $signature = 'sales-actual:nonaktifkan-draft-v2 {--execute : Benar-benar menonaktifkan; tanpa ini hanya uji-jalan}';

    protected $description = 'Nonaktifkan draft Sales Actual V2 (sebelum 1 Nov 2026) yang tidak pernah disubmit';

    public function handle(): int
    {
        $drafts = SalesActual::query()
            ->with('customer:id,name')
            ->withCount('items')
            ->where('status', SalesActual::STATUS_DRAFT)
            ->whereDate('sales_date', '<', SalesActual::V3_START)
            ->orderBy('sales_date')
            ->orderBy('id')
            ->get();

        $blocked = $this->draftsWithV3LeftoverSales($drafts->pluck('id')->all());
        [$skip, $archive] = $drafts->partition(fn (SalesActual $sa) => isset($blocked[$sa->id]));

        if ($drafts->isEmpty()) {
            $this->info('Tidak ada draft V2 yang masih aktif.');

            return self::SUCCESS;
        }

        $this->table(['SA', 'Tanggal', 'Customer', 'Item', 'Tindakan'], $drafts->map(fn (SalesActual $sa) => [
            '#'.$sa->id,
            $sa->sales_date->format('d/m/Y'),
            $sa->customer?->name,
            $sa->items_count,
            isset($blocked[$sa->id])
                ? 'DILEWATI: berisi Penjualan Barang Sisa V3 (baris '.implode(', ', $blocked[$sa->id]).')'
                : 'nonaktifkan',
        ])->all());

        if ($skip->isNotEmpty()) {
            $this->warn('Pindahkan dulu baris di atas dengan sales-actual:pindah-barang-sisa, lalu jalankan ulang.');
        }

        if (! $this->option('execute')) {
            $this->warn('Uji-jalan: '.$archive->count().' draft akan dinonaktifkan, belum ada yang diubah. Jalankan ulang dengan --execute.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($archive) {
            foreach ($archive as $sa) {
                $sa->forceFill(['status' => SalesActual::STATUS_ARCHIVED])->save();

                AuditLog::query()->create([
                    'user_id' => null,
                    'entity' => 'sales_actual',
                    'entity_id' => $sa->id,
                    'purchase_order_id' => null,
                    'action' => 'sales_actual_archived',
                    'message' => sprintf(
                        'Draft Sales Actual #%d tgl %s (%s) dinonaktifkan: draft V2 yang tidak pernah disubmit.',
                        $sa->id, $sa->sales_date->format('d/m/Y'), $sa->customer?->name ?? '-'
                    ),
                    'before_json' => ['status' => SalesActual::STATUS_DRAFT],
                    'after_json' => ['status' => SalesActual::STATUS_ARCHIVED],
                    'ip_address' => null,
                ]);
            }
        });

        $this->info($archive->count().' draft V2 dinonaktifkan.');

        return $skip->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Baris Penjualan Barang Sisa yang sisanya berasal dari V3, per draft.
     *
     * @param  array<int, int>  $draftIds
     * @return array<int, list<int>>
     */
    protected function draftsWithV3LeftoverSales(array $draftIds): array
    {
        if ($draftIds === []) {
            return [];
        }

        return SalesActualItem::query()
            ->whereIn('sales_actual_id', $draftIds)
            ->whereHas('sourceSalesActualItem.salesActual', fn ($query) => $query->whereDate('sales_date', '>=', Carbon::parse(SalesActual::V3_START)))
            ->get(['id', 'sales_actual_id'])
            ->groupBy('sales_actual_id')
            ->map(fn ($rows) => $rows->pluck('id')->all())
            ->all();
    }
}
