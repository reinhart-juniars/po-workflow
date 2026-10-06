<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LeftoverBreakdown;
use App\Models\LeftoverComponent;
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
 * Stoknya tidak disimpan sebagai saldo, tapi dihitung dari data:
 *
 *   entri (porsi utuh)
 *     masuk   = qty_return item Sales Actual yang sudah disubmit
 *     keluar  = Penjualan Barang Sisa: item Sales Actual lain yang menunjuk
 *               entri lewat source_sales_actual_item_id (qty_delivery-nya)
 *             + pembuangan di leftover_disposals
 *             + porsi yang dirinci menjadi komponen (leftover_breakdowns)
 *
 *   komponen (hasil rincian, diketik user: "telur 3 butir")
 *     masuk   = qty komponen
 *     keluar  = penjualan & pembuangan yang menunjuk leftover_component_id
 *
 * Penjualan/pembuangan komponen tetap menyimpan entri asalnya di
 * source_sales_actual_item_id (untuk rantai cara bayar & jejak retur), jadi
 * hitungan entri selalu menyaring leftover_component_id IS NULL.
 *
 * Penjualan Barang Sisa yang masih draft sudah mengurangi stok yang bisa
 * diambil (supaya tidak dijual dua kali), tapi baru keluar dari persediaan
 * saat Sales Actual-nya disubmit. Retur dari Penjualan Barang Sisa itu
 * sendiri menjadi entri baru, jadi rantainya tetap utuh.
 *
 * Nilai entri = qty x HPP menu (snapshot bahan baku di item); nilai komponen
 * diketik user dengan acuan HPP porsi yang dirinci, dan totalnya tidak boleh
 * melebihi HPP itu -- sisanya dicatat sebagai waste pada tanggal rincian.
 * Persediaan akhir Barang Sisa mengurangi HPP, persediaan awalnya menambah
 * HPP bulan berikutnya.
 */
class LeftoverStockService
{
    /**
     * Stok Barang Sisa (porsi utuh) yang masih bisa diambil, satu baris per entri retur.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function available(): Collection
    {
        return $this->entryQuery()
            ->with(['salesActual.customer', 'product'])
            ->withSum(['leftoverSales as taken_qty' => fn ($query) => $query->whereNull('leftover_component_id')], 'qty_delivery')
            ->withSum(['leftoverDisposals as disposed_qty' => fn ($query) => $query->whereNull('leftover_component_id')], 'qty')
            ->withSum('leftoverBreakdowns as broken_qty', 'portion_qty')
            ->get()
            ->map(fn (SalesActualItem $entry) => $this->describe($entry))
            ->filter(fn (array $row) => $row['available_qty'] > 0.00001)
            ->sortBy([
                ['returned_at', 'asc'],
                ['item_name', 'asc'],
            ])
            ->values();
    }

    /**
     * Komponen Barang Sisa yang masih bisa diambil, satu baris per komponen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function availableComponents(): Collection
    {
        return $this->breakdownRows()
            ->flatMap(fn (array $row) => $row['components'])
            ->filter(fn (array $component) => $component['available_qty'] > 0.00001)
            ->values();
    }

    /**
     * Rincian Barang Sisa yang masih punya komponen tersedia (atau belum
     * tersentuh sama sekali sehingga masih bisa diubah/dibatalkan).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function breakdownRows(): Collection
    {
        $breakdowns = LeftoverBreakdown::query()
            ->with(['sourceItem.salesActual.customer', 'components'])
            ->withCount('components')
            ->whereHas('sourceItem.salesActual', fn ($query) => $query->where('status', 'submitted'))
            ->orderBy('broken_at')
            ->orderBy('id')
            ->get();

        if ($breakdowns->isEmpty()) {
            return collect();
        }

        $componentIds = $breakdowns->flatMap(fn (LeftoverBreakdown $breakdown) => $breakdown->components->pluck('id'))->all();
        $taken = $this->componentSalesQty($componentIds);
        $disposed = $this->componentDisposalQty($componentIds);

        return $breakdowns
            ->map(function (LeftoverBreakdown $breakdown) use ($taken, $disposed) {
                $entry = $breakdown->sourceItem;
                $components = $breakdown->components->map(function (LeftoverComponent $component) use ($breakdown, $entry, $taken, $disposed) {
                    $used = (float) ($taken[$component->id] ?? 0) + (float) ($disposed[$component->id] ?? 0);
                    $available = round(max(0, (float) $component->qty - $used), 2);

                    return [
                        'component_id' => $component->id,
                        'breakdown_id' => $breakdown->id,
                        'entry_id' => $entry?->id,
                        'sales_actual_id' => $entry?->sales_actual_id,
                        'name' => $component->name,
                        'unit' => $component->unit,
                        'origin_name' => $entry?->item_name,
                        'customer_name' => $entry?->salesActual?->customer?->name,
                        'broken_at' => $breakdown->broken_at?->toDateString(),
                        'qty' => (float) $component->qty,
                        'available_qty' => $available,
                        'unit_cost' => $component->unitCost(),
                        'value' => round($available * $component->unitCost(), 2),
                        'used' => $used > 0.00001,
                    ];
                });

                return [
                    'breakdown' => $breakdown,
                    'entry_id' => $entry?->id,
                    'sales_actual_id' => $entry?->sales_actual_id,
                    'origin_name' => $entry?->item_name,
                    'unit' => $entry?->unit,
                    'customer_name' => $entry?->salesActual?->customer?->name,
                    'returned_at' => $entry?->salesActual?->submitted_at?->toDateString(),
                    'unit_hpp' => $entry?->leftoverUnitCost() ?? 0.0,
                    'components' => $components,
                    'editable' => ! $components->contains('used', true),
                    'available_value' => round((float) $components->sum('value'), 2),
                ];
            })
            ->filter(fn (array $row) => $row['editable'] || $row['components']->contains(fn (array $component) => $component['available_qty'] > 0.00001))
            ->values();
    }

    /** Qty sebuah entri yang belum dijual (termasuk draft), dibuang, atau dirinci. */
    public function availableQty(SalesActualItem $entry): float
    {
        $taken = (float) SalesActualItem::query()
            ->where('source_sales_actual_item_id', $entry->id)
            ->whereNull('leftover_component_id')
            ->sum('qty_delivery');
        $disposed = (float) LeftoverDisposal::query()
            ->where('source_sales_actual_item_id', $entry->id)
            ->whereNull('leftover_component_id')
            ->sum('qty');
        $broken = (float) LeftoverBreakdown::query()
            ->where('source_sales_actual_item_id', $entry->id)
            ->sum('portion_qty');

        return round(max(0, (float) $entry->qty_return - $taken - $disposed - $broken), 2);
    }

    /** Qty sebuah komponen yang belum dijual (termasuk draft) dan belum dibuang. */
    public function componentAvailableQty(LeftoverComponent $component): float
    {
        $taken = (float) ($this->componentSalesQty([$component->id])[$component->id] ?? 0);
        $disposed = (float) ($this->componentDisposalQty([$component->id])[$component->id] ?? 0);

        return round(max(0, (float) $component->qty - $taken - $disposed), 2);
    }

    /**
     * Tambahkan Penjualan Barang Sisa (porsi utuh) ke Sales Actual draft.
     *
     * Cara bayarnya mengikuti PO customer pembeli di DO Sales Actual itu. Bila
     * Sales Actual-nya tanpa DO, hanya customer asal retur yang boleh -- cara
     * bayarnya diwarisi dari PO asal seperti carry forward lama.
     */
    public function addToSalesActual(SalesActual $salesActual, SalesActualItem $entry, float $qty, ?float $unitPrice = null): SalesActualItem
    {
        return $this->addSale($salesActual, $entry->id, null, $qty, $unitPrice);
    }

    /** Jual komponen Barang Sisa sesuai yang diambil; harganya diisi Sales. */
    public function addComponentToSalesActual(SalesActual $salesActual, LeftoverComponent $component, float $qty, float $unitPrice): SalesActualItem
    {
        $entryId = (int) $component->breakdown()->value('source_sales_actual_item_id');

        return $this->addSale($salesActual, $entryId, $component->id, $qty, $unitPrice);
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
        ), ['entry_id' => $item->source_sales_actual_item_id, 'component_id' => $item->leftover_component_id, 'qty' => (float) $item->qty_delivery]);

        $item->delete();
    }

    /** Buang Barang Sisa (porsi utuh) yang tidak layak jual; nilainya masuk HPP tanggal itu. */
    public function dispose(SalesActualItem $entry, float $qty, CarbonInterface $date, ?string $reason = null, ?int $userId = null): LeftoverDisposal
    {
        return $this->addDisposal($entry->id, null, $qty, $date, $reason, $userId);
    }

    /** Buang (waste) sebagian atau seluruh komponen Barang Sisa. */
    public function disposeComponent(LeftoverComponent $component, float $qty, CarbonInterface $date, ?string $reason = null, ?int $userId = null): LeftoverDisposal
    {
        $entryId = (int) $component->breakdown()->value('source_sales_actual_item_id');

        return $this->addDisposal($entryId, $component->id, $qty, $date, $reason, $userId);
    }

    /**
     * Rinci sejumlah porsi Barang Sisa menjadi komponen yang diketik user.
     *
     * @param  array<int, array<string, mixed>>  $components  baris {name, qty, unit, value}; baris kosong diabaikan
     */
    public function breakDown(SalesActualItem $entry, float $portionQty, array $components, CarbonInterface $date, ?string $notes = null, ?int $userId = null): LeftoverBreakdown
    {
        $portionQty = round($portionQty, 2);
        $rows = $this->normalizeComponents($components);

        return DB::transaction(function () use ($entry, $portionQty, $rows, $date, $notes, $userId) {
            $entry = $this->lockEntry($entry->id, 'portion_qty');
            $this->assertDateNotBeforeReturn($entry, $date, 'broken_at');

            if ($date->copy()->startOfDay()->gt(now()->startOfDay())) {
                throw ValidationException::withMessages(['broken_at' => 'Tanggal rincian tidak boleh di masa depan.']);
            }

            $this->assertPortionAvailable($entry, $portionQty, $this->availableQty($entry));
            $portionValue = round($portionQty * $entry->leftoverUnitCost(), 2);
            $this->assertValueWithinHpp($rows, $portionValue);

            $breakdown = LeftoverBreakdown::query()->create([
                'source_sales_actual_item_id' => $entry->id,
                'broken_at' => $date->toDateString(),
                'portion_qty' => $portionQty,
                'portion_value' => $portionValue,
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'created_by' => $userId ?? Auth::id(),
            ]);
            $breakdown->components()->createMany($rows);

            $this->audit('leftover_breakdown', $breakdown->id, 'leftover_broken_down', sprintf(
                'Barang Sisa %s %s dirinci menjadi %s komponen (nilai Rp %s dari HPP Rp %s).',
                $entry->item_name,
                number_format($portionQty, 2, ',', '.'),
                count($rows),
                number_format(array_sum(array_column($rows, 'value')), 0, ',', '.'),
                number_format($portionValue, 0, ',', '.')
            ), ['entry_id' => $entry->id, 'portion_qty' => $portionQty, 'portion_value' => $portionValue, 'components' => $rows]);

            return $breakdown->load('components');
        });
    }

    /**
     * Ubah rincian selama belum ada komponen yang dijual atau dibuang.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public function updateBreakdown(LeftoverBreakdown $breakdown, float $portionQty, array $components, ?string $notes = null): LeftoverBreakdown
    {
        $portionQty = round($portionQty, 2);
        $rows = $this->normalizeComponents($components);

        return DB::transaction(function () use ($breakdown, $portionQty, $rows, $notes) {
            $entry = $this->lockEntry($breakdown->source_sales_actual_item_id, 'portion_qty');
            $breakdown = LeftoverBreakdown::query()->with('components')->findOrFail($breakdown->id);
            $this->assertBreakdownUntouched($breakdown);

            // Porsi rincian ini sendiri boleh dipakai lagi.
            $this->assertPortionAvailable($entry, $portionQty, $this->availableQty($entry) + (float) $breakdown->portion_qty);
            $portionValue = round($portionQty * $entry->leftoverUnitCost(), 2);
            $this->assertValueWithinHpp($rows, $portionValue);

            $before = ['portion_qty' => (float) $breakdown->portion_qty, 'components' => $breakdown->components->map->only(['name', 'qty', 'unit', 'value'])->all()];

            $breakdown->update([
                'portion_qty' => $portionQty,
                'portion_value' => $portionValue,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ]);
            $breakdown->components()->delete();
            $breakdown->components()->createMany($rows);

            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'entity' => 'leftover_breakdown',
                'entity_id' => $breakdown->id,
                'purchase_order_id' => null,
                'action' => 'leftover_breakdown_updated',
                'message' => sprintf('Rincian Barang Sisa %s diubah.', $entry->item_name),
                'before_json' => $before,
                'after_json' => ['portion_qty' => $portionQty, 'components' => $rows],
                'ip_address' => request()?->ip(),
            ]);

            return $breakdown->fresh('components');
        });
    }

    /** Batalkan rincian yang belum tersentuh: porsinya kembali ke stok entri. */
    public function cancelBreakdown(LeftoverBreakdown $breakdown): void
    {
        DB::transaction(function () use ($breakdown) {
            $entry = $this->lockEntry($breakdown->source_sales_actual_item_id, 'portion_qty');
            $breakdown = LeftoverBreakdown::query()->with('components')->findOrFail($breakdown->id);
            $this->assertBreakdownUntouched($breakdown);

            $this->audit('leftover_breakdown', $breakdown->id, 'leftover_breakdown_cancelled', sprintf(
                'Rincian Barang Sisa %s (%s) dibatalkan; porsinya kembali ke stok.',
                $entry->item_name,
                number_format((float) $breakdown->portion_qty, 2, ',', '.')
            ), ['entry_id' => $entry->id, 'portion_qty' => (float) $breakdown->portion_qty]);

            $breakdown->delete();
        });
    }

    /** Nilai persediaan Barang Sisa pada akhir tanggal. */
    public function valueAt(CarbonInterface $date): float
    {
        return round((float) $this->onHandAt($date)->sum('value'), 2);
    }

    /**
     * Rincian persediaan Barang Sisa pada akhir tanggal: porsi utuh dan
     * komponen, hanya dari entri yang masuk sejak tanggal mulai (pengaturan
     * leftover.accounting_start).
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
        $submittedBy = fn ($query) => $query->where('status', 'submitted')->where('submitted_at', '<=', $end);

        // Yang keluar s/d tanggal: penjualan yang sudah disubmit + pembuangan + rincian.
        $sold = SalesActualItem::query()
            ->whereIn('source_sales_actual_item_id', $ids)
            ->whereNull('leftover_component_id')
            ->whereHas('salesActual', $submittedBy)
            ->selectRaw('source_sales_actual_item_id, SUM(qty_delivery) as qty')
            ->groupBy('source_sales_actual_item_id')
            ->pluck('qty', 'source_sales_actual_item_id');

        $disposed = LeftoverDisposal::query()
            ->whereIn('source_sales_actual_item_id', $ids)
            ->whereNull('leftover_component_id')
            ->whereDate('disposed_at', '<=', $end->toDateString())
            ->selectRaw('source_sales_actual_item_id, SUM(qty) as qty')
            ->groupBy('source_sales_actual_item_id')
            ->pluck('qty', 'source_sales_actual_item_id');

        $breakdowns = LeftoverBreakdown::query()
            ->with('components')
            ->whereIn('source_sales_actual_item_id', $ids)
            ->whereDate('broken_at', '<=', $end->toDateString())
            ->get();
        $broken = $breakdowns->groupBy('source_sales_actual_item_id')->map(fn (Collection $rows) => (float) $rows->sum('portion_qty'));

        $rows = $entries->map(function (SalesActualItem $entry) use ($sold, $disposed, $broken) {
            $qty = round((float) $entry->qty_return
                - (float) ($sold[$entry->id] ?? 0)
                - (float) ($disposed[$entry->id] ?? 0)
                - (float) ($broken[$entry->id] ?? 0), 2);
            $unitCost = $entry->leftoverUnitCost();

            return [
                'entry_id' => $entry->id,
                'component_id' => null,
                'item_name' => $entry->item_name,
                'unit' => $entry->unit,
                'customer_name' => $entry->salesActual?->customer?->name,
                'returned_at' => $entry->salesActual?->submitted_at?->toDateString(),
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'value' => round($qty * $unitCost, 2),
            ];
        });

        $components = $breakdowns->flatMap(fn (LeftoverBreakdown $breakdown) => $breakdown->components);

        if ($components->isNotEmpty()) {
            $componentIds = $components->pluck('id');
            $componentSold = SalesActualItem::query()
                ->whereIn('leftover_component_id', $componentIds)
                ->whereHas('salesActual', $submittedBy)
                ->selectRaw('leftover_component_id, SUM(qty_delivery) as qty')
                ->groupBy('leftover_component_id')
                ->pluck('qty', 'leftover_component_id');
            $componentDisposed = LeftoverDisposal::query()
                ->whereIn('leftover_component_id', $componentIds)
                ->whereDate('disposed_at', '<=', $end->toDateString())
                ->selectRaw('leftover_component_id, SUM(qty) as qty')
                ->groupBy('leftover_component_id')
                ->pluck('qty', 'leftover_component_id');
            $entriesById = $entries->keyBy('id');

            $rows = $rows->concat($breakdowns->flatMap(fn (LeftoverBreakdown $breakdown) => $breakdown->components->map(
                function (LeftoverComponent $component) use ($breakdown, $entriesById, $componentSold, $componentDisposed) {
                    $entry = $entriesById->get($breakdown->source_sales_actual_item_id);
                    $qty = round((float) $component->qty
                        - (float) ($componentSold[$component->id] ?? 0)
                        - (float) ($componentDisposed[$component->id] ?? 0), 2);

                    return [
                        'entry_id' => $entry?->id,
                        'component_id' => $component->id,
                        'item_name' => $component->name.' (rincian '.$entry?->item_name.')',
                        'unit' => $component->unit,
                        'customer_name' => $entry?->salesActual?->customer?->name,
                        'returned_at' => $entry?->salesActual?->submitted_at?->toDateString(),
                        'qty' => $qty,
                        'unit_cost' => $component->unitCost(),
                        'value' => round($qty * $component->unitCost(), 2),
                    ];
                }
            )));
        }

        return $rows
            ->filter(fn (array $row) => $row['qty'] > 0.00001)
            ->sortBy('item_name')
            ->values();
    }

    /**
     * Nilai Barang Sisa yang dibuang dalam rentang: dibuang langsung dari stok
     * (porsi utuh atau komponen), nilai porsi yang tidak terinci ke komponen,
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
            ->with(['sourceItem.product', 'component'])
            ->whereHas('sourceItem.salesActual', $inScope)
            ->whereDate('disposed_at', '>=', $fromDay->toDateString())
            ->whereDate('disposed_at', '<=', $toDay->toDateString())
            ->get()
            ->sum(fn (LeftoverDisposal $disposal) => $disposal->value());

        $unallocated = LeftoverBreakdown::query()
            ->with('components')
            ->whereHas('sourceItem.salesActual', $inScope)
            ->whereDate('broken_at', '>=', $fromDay->toDateString())
            ->whereDate('broken_at', '<=', $toDay->toDateString())
            ->get()
            ->sum(fn (LeftoverBreakdown $breakdown) => $breakdown->unallocatedValue());

        $salesWaste = SalesActualItem::query()
            ->with(['sourceSalesActualItem.product', 'leftoverComponent'])
            ->where('is_carry_forward', true)
            ->where('qty_waste', '>', 0)
            ->whereHas('sourceSalesActualItem.salesActual', $inScope)
            ->whereHas('salesActual', fn ($query) => $query
                ->where('status', 'submitted')
                ->whereBetween('submitted_at', [$fromDay, $toDay]))
            ->get()
            ->sum(fn (SalesActualItem $item) => (float) $item->qty_waste * $this->saleUnitCost($item));

        return round((float) $disposals + (float) $unallocated + (float) $salesWaste, 2);
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

    /** HPP per satuan sebuah Penjualan Barang Sisa: komponen, atau HPP menu entri asalnya. */
    protected function saleUnitCost(SalesActualItem $item): float
    {
        if ($item->leftover_component_id && $item->leftoverComponent) {
            return $item->leftoverComponent->unitCost();
        }

        return $item->sourceSalesActualItem?->leftoverUnitCost() ?? $item->leftoverUnitCost();
    }

    protected function addSale(SalesActual $salesActual, int $entryId, ?int $componentId, float $qty, ?float $unitPrice): SalesActualItem
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

        return DB::transaction(function () use ($salesActual, $entryId, $componentId, $qty, $unitPrice) {
            // Kunci entri supaya dua penjualan bersamaan tidak mengambil stok yang sama.
            $entry = $this->lockEntry($entryId, 'leftover');

            if ((int) $entry->sales_actual_id === (int) $salesActual->id) {
                throw ValidationException::withMessages([
                    'leftover' => 'Barang Sisa tidak bisa dijual kembali ke Sales Actual asalnya.',
                ]);
            }

            $component = $componentId ? LeftoverComponent::query()->findOrFail($componentId) : null;
            $available = $component ? $this->componentAvailableQty($component) : $this->availableQty($entry);
            $name = $component ? $component->name : $entry->item_name;

            if ($qty - $available > 0.00001) {
                throw ValidationException::withMessages([
                    'leftover_qty' => sprintf('Barang Sisa %s tinggal %s.', $name, number_format($available, 2, ',', '.')),
                ]);
            }

            $attributes = $component
                ? [
                    // Komponen bukan menu: tanpa product_id supaya tidak terhitung
                    // sebagai penjualan menu asal di laporan per menu.
                    'product_id' => null,
                    'item_name' => $component->name,
                    'unit' => $component->unit,
                    'unit_price' => (float) $unitPrice,
                    'raw_material_cost' => round($component->unitCost(), 2),
                    'overhead_cost' => 0,
                    'leftover_component_id' => $component->id,
                ]
                : [
                    'product_id' => $entry->product_id,
                    'item_name' => $entry->item_name,
                    'unit' => $entry->unit,
                    'unit_price' => $unitPrice ?? (float) $entry->unit_price,
                    // Nilai Barang Sisa ikut dibawa: dipakai laporan HPP & waste.
                    'raw_material_cost' => $entry->raw_material_cost ?? $entry->product?->raw_material_cost,
                    'overhead_cost' => $entry->overhead_cost ?? $entry->product?->overhead_cost,
                    'leftover_component_id' => null,
                ];

            $item = SalesActualItem::query()->create($attributes + [
                'sales_actual_id' => $salesActual->id,
                'purchase_order_item_id' => null,
                'purchase_order_id' => $this->paymentPurchaseOrderId($salesActual, $entry),
                'qty_delivery' => $qty,
                'qty_actual' => $qty,
                'qty_return' => 0,
                'qty_cancel' => 0,
                'is_carry_forward' => true,
                'source_sales_actual_item_id' => $entry->id,
                'notes' => null,
            ]);

            $this->audit('sales_actual', $salesActual->id, 'sales_actual_leftover_added', sprintf(
                'Barang Sisa %s (%s) dijual lewat Sales Actual #%s.',
                $component ? $component->name.' (rincian '.$entry->item_name.')' : $entry->item_name,
                number_format($qty, 2, ',', '.'),
                $salesActual->id
            ), ['entry_id' => $entry->id, 'component_id' => $component?->id, 'item_id' => $item->id, 'qty' => $qty, 'unit_price' => (float) $item->unit_price]);

            return $item;
        });
    }

    protected function addDisposal(int $entryId, ?int $componentId, float $qty, CarbonInterface $date, ?string $reason, ?int $userId): LeftoverDisposal
    {
        $qty = round($qty, 2);

        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'dispose_qty' => 'Qty yang dibuang harus lebih dari 0.',
            ]);
        }

        return DB::transaction(function () use ($entryId, $componentId, $qty, $date, $reason, $userId) {
            $entry = $this->lockEntry($entryId, 'dispose_qty');
            $this->assertDateNotBeforeReturn($entry, $date, 'disposed_at');

            $component = $componentId ? LeftoverComponent::query()->with('breakdown')->findOrFail($componentId) : null;

            if ($component && $date->copy()->startOfDay()->lt($component->breakdown->broken_at->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'disposed_at' => 'Tanggal buang tidak boleh sebelum barangnya dirinci.',
                ]);
            }

            $available = $component ? $this->componentAvailableQty($component) : $this->availableQty($entry);
            $name = $component ? $component->name : $entry->item_name;

            if ($qty - $available > 0.00001) {
                throw ValidationException::withMessages([
                    'dispose_qty' => sprintf('Barang Sisa %s tinggal %s.', $name, number_format($available, 2, ',', '.')),
                ]);
            }

            $disposal = LeftoverDisposal::query()->create([
                'source_sales_actual_item_id' => $entry->id,
                'leftover_component_id' => $component?->id,
                'disposed_at' => $date->toDateString(),
                'qty' => $qty,
                'reason' => filled($reason) ? trim((string) $reason) : null,
                'created_by' => $userId ?? Auth::id(),
            ]);

            $this->audit('leftover_disposal', $disposal->id, 'leftover_disposed', sprintf(
                'Barang Sisa %s dibuang %s pada %s.%s',
                $component ? $component->name.' (rincian '.$entry->item_name.')' : $entry->item_name,
                number_format($qty, 2, ',', '.'),
                $date->format('d-m-Y'),
                $disposal->reason ? ' Alasan: '.$disposal->reason : ''
            ), ['entry_id' => $entry->id, 'component_id' => $component?->id, 'qty' => $qty, 'value' => $disposal->value()]);

            return $disposal;
        });
    }

    /** Kunci baris entri (serialisasi semua gerakan stoknya) dan pastikan memang Barang Sisa. */
    protected function lockEntry(int $entryId, string $errorKey): SalesActualItem
    {
        $entry = SalesActualItem::query()->with('salesActual')->whereKey($entryId)->lockForUpdate()->first();

        if (! $entry || ! $this->isEntry($entry)) {
            throw ValidationException::withMessages([$errorKey => 'Item ini bukan Barang Sisa.']);
        }

        return $entry;
    }

    protected function assertDateNotBeforeReturn(SalesActualItem $entry, CarbonInterface $date, string $errorKey): void
    {
        if ($date->copy()->startOfDay()->lt($entry->salesActual->submitted_at->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                $errorKey => 'Tanggal tidak boleh sebelum barangnya diretur.',
            ]);
        }
    }

    protected function assertPortionAvailable(SalesActualItem $entry, float $portionQty, float $available): void
    {
        if ($portionQty <= 0) {
            throw ValidationException::withMessages(['portion_qty' => 'Jumlah porsi yang dirinci harus lebih dari 0.']);
        }

        if ($portionQty - $available > 0.00001) {
            throw ValidationException::withMessages([
                'portion_qty' => sprintf('Barang Sisa %s tinggal %s.', $entry->item_name, number_format($available, 2, ',', '.')),
            ]);
        }
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function assertValueWithinHpp(array $rows, float $portionValue): void
    {
        $total = round(array_sum(array_column($rows, 'value')), 2);

        if ($total - $portionValue > 0.005) {
            throw ValidationException::withMessages([
                'components' => sprintf(
                    'Total nilai komponen (Rp %s) melebihi HPP porsi yang dirinci (Rp %s).',
                    number_format($total, 0, ',', '.'),
                    number_format($portionValue, 0, ',', '.')
                ),
            ]);
        }
    }

    protected function assertBreakdownUntouched(LeftoverBreakdown $breakdown): void
    {
        $ids = $breakdown->components->pluck('id');
        $touched = SalesActualItem::query()->whereIn('leftover_component_id', $ids)->exists()
            || LeftoverDisposal::query()->whereIn('leftover_component_id', $ids)->exists();

        if ($touched) {
            throw ValidationException::withMessages([
                'components' => 'Komponen rincian ini sudah dijual atau dibuang, jadi rinciannya tidak bisa diubah lagi.',
            ]);
        }
    }

    /**
     * Rapikan baris komponen dari form: baris yang seluruhnya kosong dibuang,
     * sisanya wajib lengkap.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return list<array{name: string, qty: float, unit: string, value: float}>
     */
    protected function normalizeComponents(array $components): array
    {
        $rows = [];
        $errors = [];

        foreach (array_values($components) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $qtyRaw = $row['qty'] ?? null;
            $valueRaw = $row['value'] ?? null;

            if ($name === '' && $unit === '' && blank($qtyRaw) && blank($valueRaw)) {
                continue;
            }

            $qty = round((float) $qtyRaw, 2);
            $value = round((float) $valueRaw, 2);
            $line = $index + 1;

            if ($name === '' || mb_strlen($name) > 100) {
                $errors["components.$index.name"] = "Baris $line: nama komponen wajib diisi (maks. 100 huruf).";
            }

            if ($unit === '' || mb_strlen($unit) > 30) {
                $errors["components.$index.unit"] = "Baris $line: satuan wajib diisi (maks. 30 huruf).";
            }

            if (! is_numeric($qtyRaw) || $qty <= 0) {
                $errors["components.$index.qty"] = "Baris $line: jumlah harus lebih dari 0.";
            }

            if (! is_numeric($valueRaw) || $value < 0) {
                $errors["components.$index.value"] = "Baris $line: nilai HPP wajib diisi dan tidak boleh negatif.";
            }

            $rows[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'value' => $value];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['components' => 'Isi minimal satu komponen.']);
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $componentIds
     * @return array<int, float>
     */
    protected function componentSalesQty(array $componentIds): array
    {
        if ($componentIds === []) {
            return [];
        }

        return SalesActualItem::query()
            ->whereIn('leftover_component_id', $componentIds)
            ->selectRaw('leftover_component_id, SUM(qty_delivery) as qty')
            ->groupBy('leftover_component_id')
            ->pluck('qty', 'leftover_component_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /**
     * @param  array<int, int>  $componentIds
     * @return array<int, float>
     */
    protected function componentDisposalQty(array $componentIds): array
    {
        if ($componentIds === []) {
            return [];
        }

        return LeftoverDisposal::query()
            ->whereIn('leftover_component_id', $componentIds)
            ->selectRaw('leftover_component_id, SUM(qty) as qty')
            ->groupBy('leftover_component_id')
            ->pluck('qty', 'leftover_component_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
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
        $available = round(max(0, (float) $entry->qty_return
            - (float) ($entry->taken_qty ?? 0)
            - (float) ($entry->disposed_qty ?? 0)
            - (float) ($entry->broken_qty ?? 0)), 2);
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
