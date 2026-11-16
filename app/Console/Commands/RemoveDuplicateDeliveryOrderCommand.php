<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DeliveryOrder;
use App\Models\OtherIncome;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hapus DO yang terbentuk dobel karena klik ganda "Teruskan ke Delivery",
 * beserta Sales Actual-nya.
 *
 * Kasus 14 Nov 2026: DO-20261114-0002 dan -0003 dibuat selisih 1 detik dengan
 * PO yang sama, keduanya dikirim, dan tiap DO menghasilkan Sales Actual sendiri,
 * sehingga penjualan 4 customer lapak tercatat dua kali (pembuatan DO sudah
 * dicegah sejak v3.2.8).
 *
 * Tanpa argumen: daftar PO yang ada di lebih dari satu DO. Dengan ID DO: rencana
 * penghapusan DO itu; DO kembarannya (yang berisi PO yang sama) tetap. Ditolak
 * bila Sales Actual-nya sudah closing, berisi baris di luar PO DO itu (Barang
 * Sisa / Porsi Tambahan), retur-nya sudah dipakai sebagai Barang Sisa, atau
 * isinya berbeda dengan Sales Actual kembarannya. Tanpa --execute hanya
 * uji-jalan. Isi lengkap yang dihapus disimpan di audit log.
 */
class RemoveDuplicateDeliveryOrderCommand extends Command
{
    protected $signature = 'delivery:hapus-do-ganda
        {do?* : ID DO ganda yang dihapus (delivery_orders.id); kosongkan untuk mencari}
        {--execute : Benar-benar menghapus; tanpa ini hanya uji-jalan}';

    protected $description = 'Cari / hapus DO dobel (PO sama di dua DO) beserta Sales Actual-nya';

    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->argument('do'));

        if ($ids === []) {
            return $this->listDuplicates();
        }

        $plans = [];

        foreach ($ids as $id) {
            $plan = $this->plan($id);

            if (is_string($plan)) {
                $this->error($plan);

                return self::FAILURE;
            }

            $plans[] = $plan;
        }

        // Jangan sampai DO dan kembarannya ikut dihapus bersamaan.
        foreach ($plans as $plan) {
            $kembaranIkutDihapus = $plan['twins']->first(fn (DeliveryOrder $twin) => in_array($twin->id, $ids, true));

            if ($kembaranIkutDihapus) {
                $this->error("DO {$plan['do']->do_code} dan kembarannya {$kembaranIkutDihapus->do_code} sama-sama diminta dihapus; sebutkan satu saja.");

                return self::FAILURE;
            }
        }

        foreach ($plans as $plan) {
            $this->showPlan($plan);
        }

        if (! $this->option('execute')) {
            $this->warn('Uji-jalan: belum ada yang dihapus. Jalankan ulang dengan --execute.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plans) {
            foreach ($plans as $plan) {
                $this->remove($plan);
            }
        });

        $this->info(count($plans).' DO ganda dihapus beserta '.collect($plans)->sum(fn ($plan) => $plan['sales_actuals']->count()).' Sales Actual-nya.');

        return self::SUCCESS;
    }

    private function listDuplicates(): int
    {
        $rows = DB::table('delivery_order_purchase_orders as pivot')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'pivot.purchase_order_id')
            ->join('delivery_orders', 'delivery_orders.id', '=', 'pivot.delivery_order_id')
            ->whereIn('pivot.purchase_order_id', function ($query) {
                $query->select('purchase_order_id')
                    ->from('delivery_order_purchase_orders')
                    ->groupBy('purchase_order_id')
                    ->havingRaw('COUNT(DISTINCT delivery_order_id) > 1');
            })
            ->orderBy('purchase_orders.po_number')
            ->orderBy('delivery_orders.id')
            ->get(['purchase_orders.po_number', 'delivery_orders.id as do_id', 'delivery_orders.do_code', 'delivery_orders.status', 'delivery_orders.created_at']);

        if ($rows->isEmpty()) {
            $this->info('Tidak ada PO yang ada di lebih dari satu DO.');

            return self::SUCCESS;
        }

        $this->table(['PO', 'ID DO', 'Kode DO', 'Status DO', 'DO dibuat'], $rows->map(fn ($row) => [
            $row->po_number, $row->do_id, $row->do_code, $row->status, $row->created_at,
        ])->all());

        return self::SUCCESS;
    }

    /**
     * @return array{do: DeliveryOrder, po_ids: list<int>, twins: Collection<int, DeliveryOrder>, sales_actuals: Collection<int, SalesActual>, twin_sales_actuals: array<int, SalesActual>}|string
     */
    private function plan(int $id): array|string
    {
        $do = DeliveryOrder::query()->with(['purchaseOrders:id,po_number', 'salesActuals.items', 'salesActuals.customer:id,name'])->find($id);

        if (! $do) {
            return "DO #{$id} tidak ditemukan.";
        }

        $poIds = $do->purchaseOrders->pluck('id')->map(fn ($poId) => (int) $poId)->sort()->values()->all();

        if ($poIds === []) {
            return "DO {$do->do_code} tidak berisi PO; ini bukan DO dobel.";
        }

        $twinIds = DB::table('delivery_order_purchase_orders')
            ->whereIn('purchase_order_id', $poIds)
            ->where('delivery_order_id', '!=', $do->id)
            ->distinct()
            ->pluck('delivery_order_id');
        $twins = DeliveryOrder::query()->with(['purchaseOrders:id', 'salesActuals.items'])->whereIn('id', $twinIds)->get();

        foreach ($do->purchaseOrders as $po) {
            if (! $twins->contains(fn (DeliveryOrder $twin) => $twin->purchaseOrders->contains('id', $po->id))) {
                return "PO {$po->po_number} hanya ada di DO {$do->do_code}; DO ini bukan dobel, jangan dihapus.";
            }
        }

        $twinSalesActuals = [];

        foreach ($do->salesActuals as $salesActual) {
            $refusal = $this->salesActualRefusal($salesActual, $poIds);

            if ($refusal !== null) {
                return $refusal;
            }

            $twin = $twins->flatMap->salesActuals
                ->first(fn (SalesActual $candidate) => (int) $candidate->customer_id === (int) $salesActual->customer_id
                    && $candidate->status !== 'archived'
                    && $this->itemSignature($candidate) === $this->itemSignature($salesActual));

            if (! $twin) {
                return "Sales Actual #{$salesActual->id} ({$salesActual->customer?->name}) tidak punya kembaran dengan isi sama di DO lain. "
                    .'Cocokkan dulu isinya (qty actual/retur/harga) atau pilih DO yang lain untuk dihapus.';
            }

            $twinSalesActuals[$salesActual->id] = $twin;
        }

        return [
            'do' => $do,
            'po_ids' => $poIds,
            'twins' => $twins,
            'sales_actuals' => $do->salesActuals,
            'twin_sales_actuals' => $twinSalesActuals,
        ];
    }

    /** @param list<int> $poIds */
    private function salesActualRefusal(SalesActual $salesActual, array $poIds): ?string
    {
        $label = "Sales Actual #{$salesActual->id} ({$salesActual->customer?->name})";

        if ($salesActual->sales_daily_closing_id !== null) {
            return "{$label} sudah masuk closing harian; kasnya sudah diposting, koreksi lewat Accounting.";
        }

        $hasOtherIncome = OtherIncome::query()
            ->where('source_type', OtherIncome::SOURCE_SALES_ACTUAL)
            ->where('source_id', $salesActual->id)
            ->exists();

        if ($hasOtherIncome || DB::table('ready_stock_closings')->where('sales_actual_id', $salesActual->id)->exists()) {
            return "{$label} sudah dipakai sebagai sumber pendapatan / closing stok siap; tidak dihapus.";
        }

        $poItemIds = DB::table('purchase_order_items')->whereIn('purchase_order_id', $poIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $foreign = $salesActual->items->first(fn (SalesActualItem $item) => ! in_array((int) $item->purchase_order_item_id, $poItemIds, true));

        if ($foreign) {
            return "{$label} berisi baris {$foreign->item_name} di luar PO DO ini (Barang Sisa / Porsi Tambahan); pindahkan dulu, lalu ulangi.";
        }

        $itemIds = $salesActual->items->pluck('id')->all();
        $usedAsLeftover = SalesActualItem::query()->whereIn('source_sales_actual_item_id', $itemIds)->exists()
            || DB::table('leftover_disposals')->whereIn('source_sales_actual_item_id', $itemIds)->exists()
            || DB::table('leftover_breakdowns')->whereIn('source_sales_actual_item_id', $itemIds)->exists();

        if ($usedAsLeftover) {
            return "Retur {$label} sudah dijual / dibuang / dirinci sebagai Barang Sisa; selesaikan itu dulu.";
        }

        return null;
    }

    /** Isi yang harus sama persis dengan kembarannya: qty dan harga tiap baris PO. */
    private function itemSignature(SalesActual $salesActual): string
    {
        return $salesActual->items
            ->map(fn (SalesActualItem $item) => implode('|', [
                (int) $item->purchase_order_item_id,
                number_format((float) $item->qty_actual, 2, '.', ''),
                number_format((float) $item->qty_return, 2, '.', ''),
                number_format((float) $item->qty_waste, 2, '.', ''),
                number_format((float) $item->unit_price, 2, '.', ''),
            ]))
            ->sort()
            ->implode(';');
    }

    private function showPlan(array $plan): void
    {
        $do = $plan['do'];

        $this->line('');
        $this->line(sprintf(
            'Hapus DO %s (#%d, %s, dibuat %s). Tetap: %s.',
            $do->do_code,
            $do->id,
            $do->status,
            $do->created_at,
            $plan['twins']->map(fn (DeliveryOrder $twin) => $twin->do_code.' (#'.$twin->id.')')->implode(', ')
        ));
        $this->line('PO: '.$do->purchaseOrders->pluck('po_number')->implode(', ').'; bukti kirim: '.DB::table('proof_of_delivery')->where('delivery_order_id', $do->id)->count().' baris.');

        $this->table(['SA dihapus', 'Customer', 'Status', 'Tgl', 'Total', 'Kembaran tetap'], $plan['sales_actuals']->map(fn (SalesActual $sa) => [
            '#'.$sa->id,
            $sa->customer?->name,
            $sa->status,
            $sa->sales_date?->toDateString(),
            number_format((float) $sa->items->sum('subtotal_actual'), 0, ',', '.'),
            '#'.$plan['twin_sales_actuals'][$sa->id]->id,
        ])->all());
    }

    private function remove(array $plan): void
    {
        $do = $plan['do'];

        foreach ($plan['sales_actuals'] as $salesActual) {
            $twin = $plan['twin_sales_actuals'][$salesActual->id];

            AuditLog::query()->create([
                'user_id' => null,
                'entity' => 'sales_actual',
                'entity_id' => $salesActual->id,
                'purchase_order_id' => null,
                'action' => 'sales_actual_duplicate_deleted',
                'message' => sprintf(
                    'Sales Actual #%d (%s, Rp %s) dihapus karena dobel dengan #%d: DO %s terbentuk dari klik ganda.',
                    $salesActual->id,
                    $salesActual->customer?->name ?? 'Tanpa Customer',
                    number_format((float) $salesActual->items->sum('subtotal_actual'), 0, ',', '.'),
                    $twin->id,
                    $do->do_code,
                ),
                'before_json' => [
                    'sales_actual' => $salesActual->withoutRelations()->toArray(),
                    'items' => $salesActual->items->map->toArray()->all(),
                ],
                'after_json' => ['kept_sales_actual_id' => $twin->id],
                'ip_address' => null,
            ]);

            $salesActual->items()->delete();
            $salesActual->delete();
        }

        AuditLog::query()->create([
            'user_id' => null,
            'entity' => 'delivery_orders',
            'entity_id' => $do->id,
            'purchase_order_id' => null,
            'action' => 'deleted_duplicate',
            'message' => sprintf(
                'DO %s dihapus karena dobel (klik ganda) dengan %s; %d PO tetap di DO itu.',
                $do->do_code,
                $plan['twins']->pluck('do_code')->implode(', '),
                count($plan['po_ids']),
            ),
            'before_json' => [
                'delivery_order' => $do->withoutRelations()->toArray(),
                'purchase_order_ids' => $plan['po_ids'],
                'proofs' => DB::table('proof_of_delivery')->where('delivery_order_id', $do->id)->get()->map(fn ($row) => (array) $row)->all(),
            ],
            'after_json' => ['kept_delivery_order_ids' => $plan['twins']->pluck('id')->all()],
            'ip_address' => null,
        ]);

        // Model ProofOfDelivery menunjuk tabel yang salah (proof_of_deliveries), jadi lewat query builder.
        DB::table('proof_of_delivery')->where('delivery_order_id', $do->id)->delete();
        $do->purchaseOrders()->detach();
        $do->delete();
    }
}
