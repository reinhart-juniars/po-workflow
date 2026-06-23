<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\DeliveryOrder;
use App\Models\IncomeCategory;
use App\Models\AuditLog;
use App\Models\OtherIncome;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesDailyClosing;
use App\Models\SalesActualItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesActualService
{
    private const INCOME_CATEGORY_NAME = OtherIncome::CATEGORY_SALES_ACTUAL;
    private const SHIPPING_INCOME_CATEGORY_NAME = OtherIncome::CATEGORY_OTHER_SALES;

    public function createDraftFromDeliveryOrder(DeliveryOrder $deliveryOrder, ?string $ipAddress = null): Collection
    {
        $deliveryOrder->loadMissing([
            'purchaseOrders.customer',
            'purchaseOrders.items.product',
        ]);

        $salesDate = $deliveryOrder->scheduled_at
            ? Carbon::parse($deliveryOrder->scheduled_at)->toDateString()
            : now()->toDateString();

        return DB::transaction(function () use ($deliveryOrder, $salesDate, $ipAddress) {
            return $deliveryOrder->purchaseOrders
                ->filter(fn (PurchaseOrder $purchaseOrder) => $purchaseOrder->customer_id)
                ->groupBy('customer_id')
                ->map(function (Collection $orders, int|string $customerId) use ($deliveryOrder, $salesDate, $ipAddress) {
                    $salesActual = SalesActual::query()->firstOrCreate(
                        [
                            'delivery_order_id' => $deliveryOrder->id,
                            'customer_id' => (int) $customerId,
                        ],
                        [
                            'sales_date' => $salesDate,
                            'status' => 'draft',
                            'notes' => null,
                        ]
                    );
                    $draftWasCreated = $salesActual->wasRecentlyCreated;

                    if ($salesActual->isSubmitted()) {
                        return $salesActual;
                    }

                    if ($salesActual->sales_date?->toDateString() !== $salesDate) {
                        $salesActual->forceFill(['sales_date' => $salesDate])->save();
                    }

                    $orders->each(function (PurchaseOrder $purchaseOrder) use ($salesActual) {
                        $itemsGross = (float) $purchaseOrder->items->sum('subtotal');
                        $poDiscount = (float) ($purchaseOrder->discount_amount ?? 0);
                        $discountFactor = ($itemsGross > 0 && $poDiscount > 0)
                            ? max(0.0, 1 - ($poDiscount / $itemsGross))
                            : 1.0;

                        foreach ($purchaseOrder->items as $item) {
                            $this->createDraftItemFromPurchaseOrderItem($salesActual, $item, $discountFactor);
                        }
                    });

                    if ($draftWasCreated) {
                        $salesActual->refresh()->load(['customer', 'deliveryOrder', 'items']);
                        $this->writeAuditLog(
                            $salesActual,
                            'sales_actual_draft_created',
                            sprintf(
                                'Draft sales actual #%s dibuat dari DO %s untuk customer %s. Total item: %s.',
                                $salesActual->id,
                                $deliveryOrder->do_code,
                                $salesActual->customer->name ?? 'Tanpa Customer',
                                number_format($salesActual->items->count(), 0, ',', '.')
                            ),
                            null,
                            $this->salesActualAuditPayload($salesActual),
                            $ipAddress
                        );
                    }

                    return $salesActual->fresh(['items']);
                })
                ->values();
        });
    }

    public function updateActualItems(SalesActual $salesActual, array $itemsData, ?string $notes = null, ?string $ipAddress = null): SalesActual
    {
        $salesActual->load('items');
        $this->ensureDraft($salesActual);
        $notes = $this->normalizeNotes($notes);
        $beforePayload = $this->salesActualAuditPayload($salesActual);

        DB::transaction(function () use ($salesActual, $itemsData, $notes, $beforePayload, $ipAddress) {
            foreach ($itemsData as $itemId => $data) {
                /** @var SalesActualItem|null $item */
                $item = $salesActual->items->firstWhere('id', (int) $itemId);

                if (! $item) {
                    continue;
                }

                $qtyActual = (float) ($data['qty_actual'] ?? 0);
                $qtyWaste = $item->is_carry_forward
                    ? max(0, round((float) ($data['qty_waste'] ?? 0), 2))
                    : 0.0;
                $qtyReturn = max(0, round((float) $item->qty_delivery - $qtyActual - $qtyWaste, 2));

                $updateData = [
                    'qty_actual' => $qtyActual,
                    'qty_waste' => $qtyWaste,
                    'qty_return' => $qtyReturn,
                    'qty_cancel' => 0,
                    'notes' => null,
                ];

                if ($item->is_carry_forward) {
                    $updateData = [
                        ...$updateData,
                        ...$this->carryForwardItemMenuUpdateData($item, $data),
                    ];
                }

                $item->fill($updateData)->save();
            }

            $salesActual->forceFill(['notes' => $notes])->save();
            $salesActual->refresh()->load('items');
            $this->validateQuantities($salesActual);
            $this->validateRequiredReturnNote($salesActual);

            $afterPayload = $this->salesActualAuditPayload($salesActual);

            if ($beforePayload !== $afterPayload) {
                $this->writeAuditLog(
                    $salesActual,
                    'sales_actual_updated',
                    sprintf(
                        'Sales actual #%s diperbarui oleh %s. Actual Rp %s, retur %s item.',
                        $salesActual->id,
                        Auth::user()->name ?? 'Unknown',
                        number_format((float) ($afterPayload['total_actual_amount'] ?? 0), 0, ',', '.'),
                        number_format((float) ($afterPayload['total_return_qty'] ?? 0), 2, ',', '.')
                    ),
                    $beforePayload,
                    $afterPayload,
                    $ipAddress
                );
            }
        });

        return $salesActual->fresh(['customer', 'deliveryOrder', 'items']);
    }

    public function submit(SalesActual $salesActual, ?string $ipAddress = null): SalesActual
    {
        $salesActual->loadMissing(['customer', 'deliveryOrder', 'items']);
        $this->ensureDraft($salesActual);
        $beforePayload = $this->salesActualAuditPayload($salesActual);

        return DB::transaction(function () use ($salesActual, $beforePayload, $ipAddress) {
            $salesActual->items->each->save();
            $salesActual->refresh()->load(['customer', 'deliveryOrder', 'items']);
            $this->validateQuantities($salesActual);
            $this->validateRequiredReturnNote($salesActual);

            $totalActual = round((float) $salesActual->items->sum('subtotal_actual'), 2);

            $salesActual->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => Auth::id(),
            ])->save();
            $salesActual->refresh()->load(['customer', 'deliveryOrder', 'items']);

            $afterPayload = $this->salesActualAuditPayload($salesActual);
            $this->writeAuditLog(
                $salesActual,
                'sales_actual_submitted',
                sprintf(
                    'Sales actual #%s disubmit oleh %s. Actual Rp %s, retur %s item.',
                    $salesActual->id,
                    Auth::user()->name ?? 'Unknown',
                    number_format($totalActual, 0, ',', '.'),
                    number_format((float) ($afterPayload['total_return_qty'] ?? 0), 2, ',', '.')
                ),
                $beforePayload,
                $afterPayload,
                $ipAddress
            );

            $carryForwardCount = $this->createCarryForwardDraft($salesActual->fresh(['items']));

            if ($carryForwardCount > 0) {
                $this->writeAuditLog(
                    $salesActual,
                    'sales_actual_carry_forward_created',
                    sprintf(
                        'Retur Sales Actual #%s dibuat carry forward ke draft berikutnya. Total item: %s.',
                        $salesActual->id,
                        number_format($carryForwardCount, 0, ',', '.')
                    ),
                    null,
                    [
                        'sales_actual_id' => $salesActual->id,
                        'carry_forward_item_count' => $carryForwardCount,
                    ],
                    $ipAddress
                );
            }

            return $salesActual->fresh(['customer', 'deliveryOrder', 'items']);
        });
    }

    public function postDailyClosing(Carbon $closingDate, ?Carbon $cashInDate = null, float $discountAmount = 0.0, ?string $notes = null, ?string $ipAddress = null): SalesDailyClosing
    {
        $closingDate = $closingDate->copy()->startOfDay();
        $cashInDate = ($cashInDate ?? $closingDate)->copy()->startOfDay();
        $discountAmount = round(max(0, $discountAmount), 2);
        $notes = $this->normalizeNotes($notes);

        return DB::transaction(function () use ($closingDate, $cashInDate, $discountAmount, $notes, $ipAddress) {
            if (SalesDailyClosing::query()->whereDate('closing_date', $closingDate->toDateString())->exists()) {
                throw ValidationException::withMessages([
                    'closing_date' => 'Closing penjualan untuk tanggal ini sudah pernah diposting.',
                ]);
            }

            $items = $this->cashSalesActualItemsForClosing($closingDate)->get();
            $items = $items
                ->filter(fn (SalesActualItem $item) => $this->resolveCashAccountIdForSalesActualItem($item) !== null)
                ->values();

            $grossAmount = round((float) $items->sum('subtotal_actual'), 2);

            if ($grossAmount <= 0) {
                throw ValidationException::withMessages([
                    'closing_date' => 'Belum ada sales actual tunai yang bisa diclosing pada tanggal ini.',
                ]);
            }

            if ($discountAmount - $grossAmount > 0.00001) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'Diskon tidak boleh lebih besar dari total penjualan tunai.',
                ]);
            }

            $closing = SalesDailyClosing::query()->create([
                'closing_date' => $closingDate->toDateString(),
                'cash_in_date' => $cashInDate->toDateString(),
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'net_amount' => round($grossAmount - $discountAmount, 2),
                'notes' => $notes,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $cashInEntries = $this->createCashInsForDailyClosing($closing, $items);
            $shippingEntries = $this->createShippingIncomeForDailyClosing($closing, $items);

            SalesActual::query()
                ->whereIn('id', $items->pluck('sales_actual_id')->unique()->values())
                ->update([
                    'sales_daily_closing_id' => $closing->id,
                    'updated_at' => now(),
                ]);

            $shippingAmount = round((float) $shippingEntries->sum(fn (OtherIncome $row) => (float) $row->amount), 2);

            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'entity' => 'sales_daily_closing',
                'entity_id' => $closing->id,
                'purchase_order_id' => null,
                'action' => 'sales_daily_closing_posted',
                'message' => sprintf(
                    'Closing penjualan %s diposting oleh %s. Gross Rp %s, diskon Rp %s, net Rp %s, ongkir Rp %s.',
                    $closingDate->format('d-m-Y'),
                    Auth::user()->name ?? 'Unknown',
                    number_format($grossAmount, 0, ',', '.'),
                    number_format($discountAmount, 0, ',', '.'),
                    number_format((float) $closing->net_amount, 0, ',', '.'),
                    number_format($shippingAmount, 0, ',', '.')
                ),
                'before_json' => null,
                'after_json' => [
                    'closing_date' => $closing->closing_date?->toDateString(),
                    'gross_amount' => (float) $closing->gross_amount,
                    'discount_amount' => (float) $closing->discount_amount,
                    'net_amount' => (float) $closing->net_amount,
                    'shipping_amount' => $shippingAmount,
                    'sales_actual_ids' => $items->pluck('sales_actual_id')->unique()->values()->all(),
                    'entries' => $cashInEntries->map(fn (OtherIncome $otherIncome) => [
                        'other_income_id' => $otherIncome->id,
                        'amount' => (float) $otherIncome->amount,
                        'cash_account_id' => $otherIncome->cash_account_id,
                    ])->values()->all(),
                    'shipping_entries' => $shippingEntries->map(fn (OtherIncome $otherIncome) => [
                        'other_income_id' => $otherIncome->id,
                        'amount' => (float) $otherIncome->amount,
                        'cash_account_id' => $otherIncome->cash_account_id,
                    ])->values()->all(),
                ],
                'ip_address' => $ipAddress,
            ]);

            return $closing->fresh(['salesActuals.customer', 'poster']);
        });
    }

    public function validateQuantities(SalesActual $salesActual): void
    {
        $salesActual->loadMissing('items');

        foreach ($salesActual->items as $item) {
            $usedQty = (float) $item->qty_actual + (float) $item->qty_return + (float) $item->qty_waste;
            $availableQty = (float) $item->qty_delivery;

            if ($usedQty - $availableQty > 0.00001) {
                throw ValidationException::withMessages([
                    'items.' . $item->id . '.qty_actual' => "Qty actual + waste untuk {$item->item_name} tidak boleh melebihi qty delivery.",
                ]);
            }
        }

        $salesActual->items
            ->groupBy(fn (SalesActualItem $item) => $this->itemGroupKey($item))
            ->each(function (Collection $group) {
                $availableQty = round((float) $group->sum('qty_delivery'), 2);
                $usedQty = round((float) $group->sum(fn (SalesActualItem $item) => (float) $item->qty_actual + (float) $item->qty_return + (float) $item->qty_waste), 2);

                if ($usedQty - $availableQty > 0.00001) {
                    $itemName = $group->first()->item_name;

                    throw ValidationException::withMessages([
                        'items' => "Qty actual untuk {$itemName} tidak boleh melebihi qty delivery ditambah carry forward.",
                    ]);
                }
            });
    }

    private function createDraftItemFromPurchaseOrderItem(
        SalesActual $salesActual,
        PurchaseOrderItem $item,
        float $discountFactor = 1.0
    ): SalesActualItem {
        // Effective unit price = PO item subtotal (already net of item-level discount_percent)
        // proportionally reduced by the PO-level discount share, divided by qty.
        // Falls back to item->unit_price * factor when qty is missing.
        $qty = (float) $item->qty;
        $itemSubtotal = (float) $item->subtotal;
        $effectiveUnitPrice = $qty > 0
            ? round(($itemSubtotal * $discountFactor) / $qty, 2)
            : round((float) $item->unit_price * $discountFactor, 2);

        /** @var SalesActualItem $salesActualItem */
        $salesActualItem = SalesActualItem::query()->firstOrCreate(
            [
                'sales_actual_id' => $salesActual->id,
                'purchase_order_item_id' => $item->id,
            ],
            [
                'product_id' => $item->product_id,
                'item_name' => $this->itemName($item),
                'unit' => $item->unit,
                'qty_delivery' => $qty,
                'qty_actual' => $qty,
                'qty_return' => 0,
                'qty_cancel' => 0,
                'unit_price' => $effectiveUnitPrice,
                'raw_material_cost' => $item->raw_material_cost,
                'overhead_cost' => $item->overhead_cost,
                'subtotal_actual' => round($qty * $effectiveUnitPrice, 2),
                'is_carry_forward' => false,
                'source_sales_actual_item_id' => null,
                'notes' => null,
            ]
        );

        if (! $salesActualItem->wasRecentlyCreated) {
            $salesActualItem->forceFill([
                'product_id' => $item->product_id,
                'item_name' => $this->itemName($item),
                'unit' => $item->unit,
                'qty_delivery' => $qty,
                'unit_price' => $effectiveUnitPrice,
            ])->save();
        }

        return $salesActualItem;
    }

    private function carryForwardItemMenuUpdateData(SalesActualItem $item, array $data): array
    {
        $updateData = [];

        if (! blank($data['product_id'] ?? null)) {
            $product = Product::query()->findOrFail($data['product_id']);

            $updateData['product_id'] = $product->id;
            $updateData['item_name'] = $product->name;
            $updateData['unit'] = $product->unit ?: $item->unit;
        }

        if (array_key_exists('unit_price', $data) && $data['unit_price'] !== null && $data['unit_price'] !== '') {
            $updateData['unit_price'] = (float) $data['unit_price'];
        }

        return $updateData;
    }

    private function createCarryForwardDraft(SalesActual $salesActual): int
    {
        $returnItems = $salesActual->items
            ->filter(fn (SalesActualItem $item) => (float) $item->qty_return > 0)
            ->values();

        if ($returnItems->isEmpty()) {
            return 0;
        }

        $nextSalesDate = $salesActual->sales_date
            ? $salesActual->sales_date->copy()->addDay()->toDateString()
            : now()->addDay()->toDateString();

        /** @var SalesActual $nextDraft */
        $nextDraft = SalesActual::query()
            ->where('customer_id', $salesActual->customer_id)
            ->whereDate('sales_date', $nextSalesDate)
            ->where('status', 'draft')
            ->orderByRaw('CASE WHEN delivery_order_id IS NULL THEN 0 ELSE 1 END')
            ->first();

        if (! $nextDraft) {
            $nextDraft = SalesActual::query()->create([
                'delivery_order_id' => null,
                'customer_id' => $salesActual->customer_id,
                'sales_date' => $nextSalesDate,
                'status' => 'draft',
                'notes' => null,
            ]);
        }

        foreach ($returnItems as $item) {
            SalesActualItem::query()->updateOrCreate(
                [
                    'source_sales_actual_item_id' => $item->id,
                ],
                [
                    'sales_actual_id' => $nextDraft->id,
                    'purchase_order_item_id' => null,
                    'product_id' => $item->product_id,
                    'item_name' => $item->item_name,
                    'unit' => $item->unit,
                    'qty_delivery' => (float) $item->qty_return,
                    'qty_actual' => (float) $item->qty_return,
                    'qty_return' => 0,
                    'qty_cancel' => 0,
                    'unit_price' => (float) $item->unit_price,
                    'raw_material_cost' => $item->raw_material_cost,
                    'overhead_cost' => $item->overhead_cost,
                    'is_carry_forward' => true,
                    'notes' => null,
                ]
            );
        }

        return $returnItems->count();
    }

    public function cashSalesActualItemsForClosing(Carbon $closingDate)
    {
        return SalesActualItem::query()
            ->with([
                'salesActual.customer',
                'purchaseOrderItem.purchaseOrder',
                'sourceSalesActualItem.purchaseOrderItem.purchaseOrder',
                'sourceSalesActualItem.sourceSalesActualItem.purchaseOrderItem.purchaseOrder',
            ])
            ->whereHas('salesActual', function ($query) use ($closingDate) {
                $query->where('status', 'submitted')
                    ->whereDate('sales_date', $closingDate->toDateString())
                    ->whereNull('sales_daily_closing_id');
            })
            ->where('subtotal_actual', '>', 0)
            ->orderBy('sales_actual_id')
            ->orderBy('id');
    }

    public function dailyClosingPreview(Carbon $closingDate): array
    {
        $allItems = $this->cashSalesActualItemsForClosing($closingDate)->get();

        $cashItems = $allItems
            ->filter(fn (SalesActualItem $item) => $this->resolveCashAccountIdForSalesActualItem($item) !== null)
            ->values();

        $receivableItems = $allItems
            ->filter(fn (SalesActualItem $item) => $this->resolvePaymentTypeForSalesActualItem($item) === 'receivable')
            ->values();

        $rowsByCashAccount = $cashItems
            ->groupBy(fn (SalesActualItem $item) => (string) $this->resolveCashAccountIdForSalesActualItem($item))
            ->map(function (Collection $rows, string $cashAccountId) {
                $cashAccount = CashAccount::query()->find((int) $cashAccountId);

                return [
                    'cash_account_id' => (int) $cashAccountId,
                    'cash_account_name' => $cashAccount?->name ?? 'Akun Kas #' . $cashAccountId,
                    'sales_actual_count' => $rows->pluck('sales_actual_id')->unique()->count(),
                    'item_count' => $rows->count(),
                    'amount' => round((float) $rows->sum('subtotal_actual'), 2),
                ];
            })
            ->sortBy('cash_account_name')
            ->values();

        $rowsByCustomer = $cashItems
            ->groupBy(fn (SalesActualItem $item) => (string) $item->salesActual?->id)
            ->map(function (Collection $rows) {
                $salesActual = $rows->first()?->salesActual;

                return [
                    'sales_actual_id' => $salesActual?->id,
                    'customer_name' => $salesActual?->customer?->name ?? 'Tanpa Customer',
                    'item_count' => $rows->count(),
                    'amount' => round((float) $rows->sum('subtotal_actual'), 2),
                ];
            })
            ->sortBy('customer_name')
            ->values();

        $receivableRowsByCustomer = $receivableItems
            ->groupBy(fn (SalesActualItem $item) => (string) $item->salesActual?->id)
            ->map(function (Collection $rows) {
                $salesActual = $rows->first()?->salesActual;
                $purchaseOrder = $rows->first()?->purchaseOrderItem?->purchaseOrder
                    ?? $rows->first()?->sourceSalesActualItem?->purchaseOrderItem?->purchaseOrder;

                return [
                    'sales_actual_id' => $salesActual?->id,
                    'customer_name' => $salesActual?->customer?->name ?? 'Tanpa Customer',
                    'po_number' => $purchaseOrder?->po_number,
                    'due_date' => $purchaseOrder?->due_date?->toDateString(),
                    'item_count' => $rows->count(),
                    'amount' => round((float) $rows->sum('subtotal_actual'), 2),
                ];
            })
            ->sortBy('customer_name')
            ->values();

        $grossAmount = round((float) $cashItems->sum('subtotal_actual'), 2);
        $receivableAmount = round((float) $receivableItems->sum('subtotal_actual'), 2);
        $cashShippingAmount = $this->shippingAmountForItems($cashItems);
        $receivableShippingAmount = $this->shippingAmountForItems($receivableItems);
        $shippingAmount = round($cashShippingAmount + $receivableShippingAmount, 2);

        return [
            'items' => $cashItems,
            'rowsByCashAccount' => $rowsByCashAccount,
            'rowsByCustomer' => $rowsByCustomer,
            'grossAmount' => $grossAmount,
            'salesActualCount' => $cashItems->pluck('sales_actual_id')->unique()->count(),
            'itemCount' => $cashItems->count(),
            'receivableRowsByCustomer' => $receivableRowsByCustomer,
            'receivableAmount' => $receivableAmount,
            'receivableSalesActualCount' => $receivableItems->pluck('sales_actual_id')->unique()->count(),
            'receivableItemCount' => $receivableItems->count(),
            'grandTotalAmount' => round($grossAmount + $receivableAmount, 2),
            'shippingAmount' => $shippingAmount,
            'cashShippingAmount' => $cashShippingAmount,
            'receivableShippingAmount' => $receivableShippingAmount,
        ];
    }

    private function shippingAmountForItems(Collection $items): float
    {
        return round((float) $items
            ->map(fn (SalesActualItem $item) => $this->purchaseOrderForSalesActualItem($item))
            ->filter()
            ->unique('id')
            ->sum('shipping_cost'), 2);
    }

    private function shippingAmountForSubmittedActuals(Carbon $date): float
    {
        $actuals = SalesActual::query()
            ->with([
                'items.purchaseOrderItem.purchaseOrder:id,shipping_cost',
                'items.sourceSalesActualItem.purchaseOrderItem.purchaseOrder:id,shipping_cost',
                'items.sourceSalesActualItem.sourceSalesActualItem.purchaseOrderItem.purchaseOrder:id,shipping_cost',
            ])
            ->where('status', 'submitted')
            ->whereDate('sales_date', $date->toDateString())
            ->get();

        return round((float) $actuals->sum(function (SalesActual $sa) {
            return $sa->items
                ->map(fn (SalesActualItem $item) => $this->purchaseOrderForSalesActualItem($item))
                ->filter()
                ->unique('id')
                ->sum('shipping_cost');
        }), 2);
    }

    private function purchaseOrderForSalesActualItem(SalesActualItem $item): ?PurchaseOrder
    {
        return $item->purchaseOrderItem?->purchaseOrder
            ?? $item->sourceSalesActualItem?->purchaseOrderItem?->purchaseOrder
            ?? $item->sourceSalesActualItem?->sourceSalesActualItem?->purchaseOrderItem?->purchaseOrder;
    }

    private function resolvePaymentTypeForSalesActualItem(SalesActualItem $item, array $visitedItemIds = []): ?string
    {
        if (in_array($item->id, $visitedItemIds, true)) {
            return null;
        }

        $visitedItemIds[] = $item->id;

        $item->loadMissing('purchaseOrderItem.purchaseOrder', 'sourceSalesActualItem');

        $purchaseOrder = $item->purchaseOrderItem?->purchaseOrder;

        if ($purchaseOrder) {
            return $purchaseOrder->payment_type;
        }

        if ($item->sourceSalesActualItem) {
            return $this->resolvePaymentTypeForSalesActualItem($item->sourceSalesActualItem, $visitedItemIds);
        }

        return null;
    }

    private function createCashInsForDailyClosing(SalesDailyClosing $closing, Collection $items): Collection
    {
        $category = IncomeCategory::query()->firstOrCreate(
            ['name' => self::INCOME_CATEGORY_NAME],
            [
                'description' => 'Kategori sistem untuk sales actual submitted.',
                'is_active' => true,
            ]
        );

        $groupedAmounts = $items
            ->map(function (SalesActualItem $item) {
                return [
                    'cash_account_id' => $this->resolveCashAccountIdForSalesActualItem($item),
                    'amount' => round((float) $item->subtotal_actual, 2),
                ];
            })
            ->filter(fn (array $row) => ($row['cash_account_id'] ?? null) !== null && ($row['amount'] ?? 0) > 0)
            ->groupBy('cash_account_id')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2))
            ->filter(fn (float $amount) => $amount > 0);

        $grossAmount = round((float) $groupedAmounts->sum(), 2);
        $discountAmount = round((float) $closing->discount_amount, 2);
        $allocatedDiscount = 0.0;
        $lastCashAccountId = $groupedAmounts->keys()->last();

        return $groupedAmounts
            ->map(function (float $amount, int|string $cashAccountId) use ($closing, $category, $grossAmount, $discountAmount, &$allocatedDiscount, $lastCashAccountId) {
                $cashAccount = CashAccount::query()
                    ->whereKey((int) $cashAccountId)
                    ->where('is_active', true)
                    ->first();

                if (! $cashAccount) {
                    throw ValidationException::withMessages([
                        'cash_account_id' => 'Sales actual tidak bisa disubmit karena akun kas PO sumber tidak tersedia atau tidak aktif.',
                    ]);
                }

                $discountShare = 0.0;

                if ($discountAmount > 0 && $grossAmount > 0) {
                    $discountShare = (string) $cashAccountId === (string) $lastCashAccountId
                        ? round($discountAmount - $allocatedDiscount, 2)
                        : round($discountAmount * ($amount / $grossAmount), 2);
                    $allocatedDiscount = round($allocatedDiscount + $discountShare, 2);
                }

                $netAmount = round($amount - $discountShare, 2);

                if ($netAmount <= 0) {
                    return null;
                }

                return OtherIncome::query()->create([
                    'income_date' => ($closing->cash_in_date ?? $closing->closing_date)?->toDateString() ?? now()->toDateString(),
                    'income_category_id' => $category->id,
                    'cash_account_id' => $cashAccount->id,
                    'source_type' => OtherIncome::SOURCE_SALES_DAILY_CLOSING,
                    'source_id' => $closing->id,
                    'amount' => $netAmount,
                    'description' => sprintf(
                        'Closing Sales Actual %s%s',
                        $closing->closing_date?->format('d-m-Y') ?? now()->format('d-m-Y'),
                        $discountShare > 0 ? ' - diskon Rp ' . number_format($discountShare, 0, ',', '.') : ''
                    ),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            })
            ->filter()
            ->values();
    }

    private function createShippingIncomeForDailyClosing(SalesDailyClosing $closing, Collection $items): Collection
    {
        $rows = $items
            ->map(function (SalesActualItem $item) {
                $po = $this->purchaseOrderForSalesActualItem($item);
                $cashAccountId = $this->resolveCashAccountIdForSalesActualItem($item);

                return [
                    'po_id' => $po?->id,
                    'cash_account_id' => $cashAccountId,
                    'shipping_cost' => (float) ($po?->shipping_cost ?? 0),
                ];
            })
            ->filter(fn (array $row) => $row['po_id'] !== null
                && $row['cash_account_id'] !== null
                && $row['shipping_cost'] > 0)
            ->unique('po_id')
            ->groupBy('cash_account_id')
            ->map(fn (Collection $perAccount) => round((float) $perAccount->sum('shipping_cost'), 2))
            ->filter(fn (float $amount) => $amount > 0);

        if ($rows->isEmpty()) {
            return collect();
        }

        $category = IncomeCategory::query()->firstOrCreate(
            ['name' => self::SHIPPING_INCOME_CATEGORY_NAME],
            [
                'description' => 'Kategori sistem untuk ongkos kirim PO yang ditagih ke customer.',
                'is_active' => true,
            ]
        );

        $incomeDate = ($closing->cash_in_date ?? $closing->closing_date)?->toDateString() ?? now()->toDateString();

        return $rows
            ->map(function (float $amount, int|string $cashAccountId) use ($closing, $category, $incomeDate) {
                $cashAccount = CashAccount::query()
                    ->whereKey((int) $cashAccountId)
                    ->where('is_active', true)
                    ->first();

                if (! $cashAccount) {
                    throw ValidationException::withMessages([
                        'cash_account_id' => 'Ongkir tidak bisa diposting karena akun kas PO sumber tidak tersedia atau tidak aktif.',
                    ]);
                }

                return OtherIncome::query()->create([
                    'income_date' => $incomeDate,
                    'income_category_id' => $category->id,
                    'cash_account_id' => $cashAccount->id,
                    'source_type' => OtherIncome::SOURCE_SALES_DAILY_CLOSING,
                    'source_id' => $closing->id,
                    'amount' => $amount,
                    'description' => sprintf(
                        'Ongkir Closing %s',
                        $closing->closing_date?->format('d-m-Y') ?? now()->format('d-m-Y')
                    ),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            })
            ->values();
    }

    private function resolveCashAccountIdForSalesActualItem(SalesActualItem $item, array $visitedItemIds = []): ?int
    {
        if (in_array($item->id, $visitedItemIds, true)) {
            return null;
        }

        $visitedItemIds[] = $item->id;

        $item->loadMissing('purchaseOrderItem.purchaseOrder', 'sourceSalesActualItem');

        $purchaseOrder = $item->purchaseOrderItem?->purchaseOrder;

        if ($purchaseOrder) {
            if ($purchaseOrder->payment_type !== 'cash') {
                return null;
            }

            return $purchaseOrder->cash_account_id ? (int) $purchaseOrder->cash_account_id : null;
        }

        if ($item->sourceSalesActualItem) {
            return $this->resolveCashAccountIdForSalesActualItem($item->sourceSalesActualItem, $visitedItemIds);
        }

        return null;
    }

    private function ensureDraft(SalesActual $salesActual): void
    {
        if (! $salesActual->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Sales actual yang sudah submitted tidak bisa diubah.',
            ]);
        }
    }

    private function validateRequiredReturnNote(SalesActual $salesActual): void
    {
        $hasReturn = $salesActual->items->contains(fn (SalesActualItem $item) => (float) $item->qty_return > 0);

        if ($hasReturn && $this->normalizeNotes($salesActual->notes) === null) {
            throw ValidationException::withMessages([
                'notes' => 'Catatan header wajib diisi jika ada qty retur.',
            ]);
        }
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : $notes;
    }

    private function writeAuditLog(
        SalesActual $salesActual,
        string $action,
        string $message,
        ?array $before,
        ?array $after,
        ?string $ipAddress = null
    ): void {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'entity' => 'sales_actual',
            'entity_id' => $salesActual->id,
            'purchase_order_id' => null,
            'action' => $action,
            'message' => $message,
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $ipAddress,
        ]);
    }

    private function salesActualAuditPayload(SalesActual $salesActual): array
    {
        $salesActual->loadMissing(['customer', 'deliveryOrder', 'items']);

        return [
            'id' => $salesActual->id,
            'delivery_order_id' => $salesActual->delivery_order_id,
            'delivery_order_code' => $salesActual->deliveryOrder?->do_code,
            'customer_id' => $salesActual->customer_id,
            'customer_name' => $salesActual->customer?->name,
            'sales_date' => $salesActual->sales_date?->toDateString(),
            'status' => $salesActual->status,
            'submitted_at' => $salesActual->submitted_at?->toDateTimeString(),
            'submitted_by' => $salesActual->submitted_by,
            'notes' => $salesActual->notes,
            'total_delivery_qty' => round((float) $salesActual->items->sum('qty_delivery'), 2),
            'total_actual_qty' => round((float) $salesActual->items->sum('qty_actual'), 2),
            'total_return_qty' => round((float) $salesActual->items->sum('qty_return'), 2),
            'total_waste_qty' => round((float) $salesActual->items->sum('qty_waste'), 2),
            'total_delivery_amount' => round((float) $salesActual->items->sum(fn (SalesActualItem $item) => (float) $item->qty_delivery * (float) $item->unit_price), 2),
            'total_actual_amount' => round((float) $salesActual->items->sum('subtotal_actual'), 2),
            'items' => $salesActual->items
                ->sortBy('id')
                ->map(fn (SalesActualItem $item) => [
                    'id' => $item->id,
                    'purchase_order_item_id' => $item->purchase_order_item_id,
                    'product_id' => $item->product_id,
                    'item_name' => $item->item_name,
                    'unit' => $item->unit,
                    'qty_delivery' => (float) $item->qty_delivery,
                    'qty_actual' => (float) $item->qty_actual,
                    'qty_return' => (float) $item->qty_return,
                    'qty_waste' => (float) $item->qty_waste,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal_actual' => (float) $item->subtotal_actual,
                    'is_carry_forward' => (bool) $item->is_carry_forward,
                    'source_sales_actual_item_id' => $item->source_sales_actual_item_id,
                ])
                ->values()
                ->all(),
        ];
    }

    private function itemName(PurchaseOrderItem $item): string
    {
        $name = trim((string) ($item->product->name ?? $item->custom_name ?? ''));

        return $name !== '' ? $name : 'Item #' . $item->id;
    }

    private function itemGroupKey(SalesActualItem $item): string
    {
        return implode('|', [
            $item->product_id ?: 'name:' . mb_strtolower($item->item_name),
            mb_strtolower((string) $item->unit),
            number_format((float) $item->unit_price, 2, '.', ''),
        ]);
    }
}
