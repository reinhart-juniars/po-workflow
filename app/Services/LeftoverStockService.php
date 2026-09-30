<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LeftoverDisposal;
use App\Models\PurchaseOrder;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Support\Settings\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Barang Sisa: retur penjualan sebagai stok barang jadi.
 *
 * Stoknya tidak disimpan sebagai saldo, tapi dihitung dari tiga sumber:
 *
 *   masuk   = qty_return item Sales Actual yang sudah disubmit ("entri")
 *   keluar  = Penjualan Barang Sisa: item Sales Actual lain yang menunjuk
 *             entri lewat source_sales_actual_item_id (qty_delivery-nya)
 *           + pembuangan di leftover_disposals
 *
 * Penjualan Barang Sisa yang masih draft sudah mengurangi stok yang bisa
 * diambil (supaya tidak dijual dua kali), tapi baru keluar dari persediaan
 * saat Sales Actual-nya disubmit. Retur dari Penjualan Barang Sisa itu
 * sendiri menjadi entri baru, jadi rantainya tetap utuh.
 *
 * Nilainya = qty x HPP menu (snapshot bahan baku di item). Dengan begitu
 * bahan baku yang sudah terpakai untuk porsi retur tidak ikut dibebankan ke
 * bulan ini selama porsinya masih ada: persediaan akhir Barang Sisa
 * mengurangi HPP, persediaan awalnya menambah HPP bulan berikutnya.
 */
class LeftoverStockService
{
    /**
     * Stok Barang Sisa yang masih bisa diambil, satu baris per entri retur.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function available(): Collection
    {
        return $this->entryQuery()
            ->with(['salesActual.customer', 'product'])
            ->withSum('leftoverSales as taken_qty', 'qty_delivery')
            ->withSum('leftoverDisposals as disposed_qty', 'qty')
            ->get()
            ->map(fn (SalesActualItem $entry) => $this->describe($entry))
            ->filter(fn (array $row) => $row['available_qty'] > 0.00001)
            ->sortBy([
                ['returned_at', 'asc'],
                ['item_name', 'asc'],
            ])
            ->values();
    }

    /** Qty sebuah entri yang belum dijual (termasuk draft) dan belum dibuang. */
    public function availableQty(SalesActualItem $entry): float
    {
        $taken = (float) SalesActualItem::query()
            ->where('source_sales_actual_item_id', $entry->id)
            ->sum('qty_delivery');
        $disposed = (float) LeftoverDisposal::query()
            ->where('source_sales_actual_item_id', $entry->id)
            ->sum('qty');

        return round(max(0, (float) $entry->qty_return - $taken - $disposed), 2);
    }

    /**
     * Tambahkan Penjualan Barang Sisa ke Sales Actual draft.
     *
     * Cara bayarnya mengikuti PO customer pembeli di DO Sales Actual itu. Bila
     * Sales Actual-nya tanpa DO, hanya customer asal retur yang boleh -- cara
     * bayarnya diwarisi dari PO asal seperti carry forward lama.
     */
    public function addToSalesActual(SalesActual $salesActual, SalesActualItem $entry, float $qty, ?float $unitPrice = null): SalesActualItem
    {
        $qty = round($qty, 2);

        if (! $salesActual->isDraft()) {
            throw ValidationException::withMessages([
                'leftover' => 'Barang Sisa hanya bisa ditambahkan ke Sales Actual yang masih draft.',
            ]);
        }

        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'leftover_qty' => 'Qty Barang Sisa harus lebih dari 0.',
            ]);
        }

        if ($unitPrice !== null && $unitPrice < 0) {
            throw ValidationException::withMessages([
                'leftover_price' => 'Harga tidak boleh negatif.',
            ]);
        }

        return DB::transaction(function () use ($salesActual, $entry, $qty, $unitPrice) {
            // Kunci entri supaya dua penjualan bersamaan tidak mengambil stok yang sama.
            $entry = SalesActualItem::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if (! $this->isEntry($entry)) {
                throw ValidationException::withMessages([
                    'leftover' => 'Item ini bukan Barang Sisa.',
                ]);
            }

            if ((int) $entry->sales_actual_id === (int) $salesActual->id) {
                throw ValidationException::withMessages([
                    'leftover' => 'Barang Sisa tidak bisa dijual kembali ke Sales Actual asalnya.',
                ]);
            }

            $available = $this->availableQty($entry);

            if ($qty - $available > 0.00001) {
                throw ValidationException::withMessages([
                    'leftover_qty' => sprintf(
                        'Barang Sisa %s tinggal %s.',
                        $entry->item_name,
                        number_format($available, 2, ',', '.')
                    ),
                ]);
            }

            $item = SalesActualItem::query()->create([
                'sales_actual_id' => $salesActual->id,
                'purchase_order_item_id' => null,
                'purchase_order_id' => $this->paymentPurchaseOrderId($salesActual, $entry),
                'product_id' => $entry->product_id,
                'item_name' => $entry->item_name,
                'unit' => $entry->unit,
                'qty_delivery' => $qty,
                'qty_actual' => $qty,
                'qty_return' => 0,
                'qty_cancel' => 0,
                'unit_price' => $unitPrice ?? (float) $entry->unit_price,
                // Nilai Barang Sisa ikut dibawa: dipakai laporan HPP & waste.
                'raw_material_cost' => $entry->raw_material_cost ?? $entry->product?->raw_material_cost,
                'overhead_cost' => $entry->overhead_cost ?? $entry->product?->overhead_cost,
                'is_carry_forward' => true,
                'source_sales_actual_item_id' => $entry->id,
                'notes' => null,
            ]);

            $this->audit('sales_actual', $salesActual->id, 'sales_actual_leftover_added', sprintf(
                'Barang Sisa %s (%s) dijual lewat Sales Actual #%s.',
                $entry->item_name,
                number_format($qty, 2, ',', '.'),
                $salesActual->id
            ), ['entry_id' => $entry->id, 'item_id' => $item->id, 'qty' => $qty, 'unit_price' => (float) $item->unit_price]);

            return $item;
        });
    }

    /** Batalkan Penjualan Barang Sisa di draft: qty-nya kembali ke stok. */
    public function removeFromSalesActual(SalesActualItem $item): void
    {
        $item->loadMissing('salesActual');

        if (! $item->is_carry_forward || ! $item->source_sales_actual_item_id) {
            throw ValidationException::withMessages([
                'leftover' => 'Hanya baris Penjualan Barang Sisa yang bisa dilepas.',
            ]);
        }

        if (! $item->salesActual?->isDraft()) {
            throw ValidationException::withMessages([
                'leftover' => 'Sales Actual sudah disubmit; Penjualan Barang Sisa tidak bisa dilepas lagi.',
            ]);
        }

        $this->audit('sales_actual', $item->sales_actual_id, 'sales_actual_leftover_removed', sprintf(
            'Penjualan Barang Sisa %s (%s) dilepas dari Sales Actual #%s; qty kembali ke stok Barang Sisa.',
            $item->item_name,
            number_format((float) $item->qty_delivery, 2, ',', '.'),
            $item->sales_actual_id
        ), ['entry_id' => $item->source_sales_actual_item_id, 'qty' => (float) $item->qty_delivery]);

        $item->delete();
    }

    /** Buang Barang Sisa yang tidak layak jual; nilainya masuk HPP tanggal itu. */
    public function dispose(SalesActualItem $entry, float $qty, CarbonInterface $date, ?string $reason = null, ?int $userId = null): LeftoverDisposal
    {
        $qty = round($qty, 2);

        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'dispose_qty' => 'Qty yang dibuang harus lebih dari 0.',
            ]);
        }

        return DB::transaction(function () use ($entry, $qty, $date, $reason, $userId) {
            $entry = SalesActualItem::query()->with('salesActual')->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if (! $this->isEntry($entry)) {
                throw ValidationException::withMessages([
                    'dispose_qty' => 'Item ini bukan Barang Sisa.',
                ]);
            }

            if ($date->copy()->startOfDay()->lt($entry->salesActual->submitted_at->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'disposed_at' => 'Tanggal buang tidak boleh sebelum barangnya diretur.',
                ]);
            }

            $available = $this->availableQty($entry);

            if ($qty - $available > 0.00001) {
                throw ValidationException::withMessages([
                    'dispose_qty' => sprintf(
                        'Barang Sisa %s tinggal %s.',
                        $entry->item_name,
                        number_format($available, 2, ',', '.')
                    ),
                ]);
            }

            $disposal = LeftoverDisposal::query()->create([
                'source_sales_actual_item_id' => $entry->id,
                'disposed_at' => $date->toDateString(),
                'qty' => $qty,
                'reason' => filled($reason) ? trim((string) $reason) : null,
                'created_by' => $userId ?? Auth::id(),
            ]);

            $this->audit('leftover_disposal', $disposal->id, 'leftover_disposed', sprintf(
                'Barang Sisa %s dibuang %s pada %s.%s',
                $entry->item_name,
                number_format($qty, 2, ',', '.'),
                $date->format('d-m-Y'),
                $disposal->reason ? ' Alasan: '.$disposal->reason : ''
            ), ['entry_id' => $entry->id, 'qty' => $qty, 'value' => round($qty * $entry->leftoverUnitCost(), 2)]);

            return $disposal;
        });
    }

    /** Nilai persediaan Barang Sisa pada akhir tanggal. */
    public function valueAt(CarbonInterface $date): float
    {
        return round((float) $this->onHandAt($date)->sum('value'), 2);
    }

    /**
     * Rincian persediaan Barang Sisa pada akhir tanggal: hanya entri yang
     * masuk sejak tanggal mulai (pengaturan leftover.accounting_start).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function onHandAt(CarbonInterface $date): Collection
    {
        $end = Carbon::parse($date)->endOfDay();
        $start = $this->accountingStart();

        if ($end->lt($start)) {
            return collect();
        }

        $entries = SalesActualItem::query()
            ->with(['salesActual.customer', 'product'])
            ->where('qty_return', '>', 0)
            ->whereHas('salesActual', fn ($query) => $query
                ->where('status', 'submitted')
                ->whereBetween('submitted_at', [$start, $end]))
            ->get();

        if ($entries->isEmpty()) {
            return collect();
        }

        $ids = $entries->pluck('id');

        // Yang keluar s/d tanggal: penjualan yang sudah disubmit + pembuangan.
        $sold = SalesActualItem::query()
            ->whereIn('source_sales_actual_item_id', $ids)
            ->whereHas('salesActual', fn ($query) => $query
                ->where('status', 'submitted')
                ->where('submitted_at', '<=', $end))
            ->selectRaw('source_sales_actual_item_id, SUM(qty_delivery) as qty')
            ->groupBy('source_sales_actual_item_id')
            ->pluck('qty', 'source_sales_actual_item_id');

        $disposed = LeftoverDisposal::query()
            ->whereIn('source_sales_actual_item_id', $ids)
            ->whereDate('disposed_at', '<=', $end->toDateString())
            ->selectRaw('source_sales_actual_item_id, SUM(qty) as qty')
            ->groupBy('source_sales_actual_item_id')
            ->pluck('qty', 'source_sales_actual_item_id');

        return $entries
            ->map(function (SalesActualItem $entry) use ($sold, $disposed) {
                $qty = round((float) $entry->qty_return - (float) ($sold[$entry->id] ?? 0) - (float) ($disposed[$entry->id] ?? 0), 2);
                $unitCost = $entry->leftoverUnitCost();

                return [
                    'entry_id' => $entry->id,
                    'item_name' => $entry->item_name,
                    'unit' => $entry->unit,
                    'customer_name' => $entry->salesActual?->customer?->name,
                    'returned_at' => $entry->salesActual?->submitted_at?->toDateString(),
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'value' => round($qty * $unitCost, 2),
                ];
            })
            ->filter(fn (array $row) => $row['qty'] > 0.00001)
            ->sortBy('item_name')
            ->values();
    }

    /**
     * Nilai Barang Sisa yang dibuang dalam rentang: dibuang langsung dari stok
     * atau ketahuan tidak layak jual saat Penjualan Barang Sisa (qty_waste).
     */
    public function wasteValueBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $start = $this->accountingStart();
        $fromDay = Carbon::parse($from)->startOfDay()->max($start);
        $toDay = Carbon::parse($to)->endOfDay();

        if ($toDay->lt($fromDay)) {
            return 0.0;
        }

        $inScope = fn ($query) => $query->where('status', 'submitted')->where('submitted_at', '>=', $start);

        $disposals = LeftoverDisposal::query()
            ->with('sourceItem.product')
            ->whereHas('sourceItem.salesActual', $inScope)
            ->whereDate('disposed_at', '>=', $fromDay->toDateString())
            ->whereDate('disposed_at', '<=', $toDay->toDateString())
            ->get()
            ->sum(fn (LeftoverDisposal $disposal) => (float) $disposal->qty * $disposal->sourceItem->leftoverUnitCost());

        $salesWaste = SalesActualItem::query()
            ->with(['sourceSalesActualItem.product'])
            ->where('is_carry_forward', true)
            ->where('qty_waste', '>', 0)
            ->whereHas('sourceSalesActualItem.salesActual', $inScope)
            ->whereHas('salesActual', fn ($query) => $query
                ->where('status', 'submitted')
                ->whereBetween('submitted_at', [$fromDay, $toDay]))
            ->get()
            ->sum(fn (SalesActualItem $item) => (float) $item->qty_waste
                * ($item->sourceSalesActualItem?->leftoverUnitCost() ?? $item->leftoverUnitCost()));

        return round((float) $disposals + (float) $salesWaste, 2);
    }

    public function accountingStart(): Carbon
    {
        return Carbon::parse((string) app(Settings::class)->get('leftover.accounting_start'))->startOfDay();
    }

    /** Entri Barang Sisa = item bersisa retur dari Sales Actual yang sudah disubmit. */
    public function isEntry(SalesActualItem $item): bool
    {
        $item->loadMissing('salesActual');

        return (float) $item->qty_return > 0 && $item->salesActual?->isSubmitted();
    }

    /** @param  array<string, mixed>  $after */
    protected function audit(string $entity, int $entityId, string $action, string $message, array $after): void
    {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'purchase_order_id' => null,
            'action' => $action,
            'message' => $message,
            'before_json' => null,
            'after_json' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }

    protected function entryQuery()
    {
        return SalesActualItem::query()
            ->where('qty_return', '>', 0)
            ->whereHas('salesActual', fn ($query) => $query->where('status', 'submitted'));
    }

    /** @return array<string, mixed> */
    protected function describe(SalesActualItem $entry): array
    {
        $available = round(max(0, (float) $entry->qty_return - (float) ($entry->taken_qty ?? 0) - (float) ($entry->disposed_qty ?? 0)), 2);
        $unitCost = $entry->leftoverUnitCost();

        return [
            'entry_id' => $entry->id,
            'item_name' => $entry->item_name,
            'unit' => $entry->unit,
            'product_id' => $entry->product_id,
            'customer_name' => $entry->salesActual?->customer?->name,
            'sales_actual_id' => $entry->sales_actual_id,
            'returned_at' => $entry->salesActual?->submitted_at?->toDateString(),
            'qty_return' => (float) $entry->qty_return,
            'available_qty' => $available,
            'unit_price' => (float) $entry->unit_price,
            'unit_cost' => $unitCost,
            'value' => round($available * $unitCost, 2),
        ];
    }

    /**
     * PO yang menentukan cara bayar Penjualan Barang Sisa.
     *
     * Sales Actual dari DO: PO customer itu di DO tersebut (id terkecil bila
     * lebih dari satu). Tanpa DO: hanya customer asal, dan null berarti cara
     * bayar diwarisi lewat rantai retur (perilaku carry forward lama).
     */
    protected function paymentPurchaseOrderId(SalesActual $salesActual, SalesActualItem $entry): ?int
    {
        $entry->loadMissing('salesActual');

        if ($salesActual->delivery_order_id) {
            $purchaseOrderId = PurchaseOrder::query()
                ->where('customer_id', $salesActual->customer_id)
                ->whereHas('deliveryOrders', fn ($query) => $query->whereKey($salesActual->delivery_order_id))
                ->orderBy('id')
                ->value('id');

            if ($purchaseOrderId) {
                return (int) $purchaseOrderId;
            }
        }

        if ((int) $salesActual->customer_id === (int) $entry->salesActual?->customer_id) {
            return null;
        }

        throw ValidationException::withMessages([
            'leftover' => 'Sales Actual ini tidak punya PO untuk menentukan cara bayar. Tambahkan Barang Sisa ke Sales Actual dari DO customer pembeli.',
        ]);
    }
}
