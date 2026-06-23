<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\BalanceSheetAdjustment;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\OpeningBalance;
use App\Models\OtherIncome;
use App\Models\Payable;
use App\Models\ProfitLossAdjustment;
use App\Models\PurchaseOrder;
use App\Models\SalesActual;
use App\Models\SalesDailyClosing;
use App\Models\SalesActualItem;
use App\Models\StockOpname;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BalanceSheetService
{
    private const PAYABLE_SETTLEMENT_CATEGORY_NAME = 'Pembayaran Hutang';
    public function __construct(
        private InventoryUsageService $inventoryUsageService
    ) {
    }

    public function buildReport(Carbon $reportDate): array
    {
        $reportDate = $reportDate->copy()->endOfDay();
        $currentPeriodStart = $reportDate->copy()->startOfMonth();
        $priorPeriodEnd = $currentPeriodStart->copy()->subDay()->endOfDay();

        [$cashRows, $cashWarnings] = $this->buildCashRows($reportDate);
        [$receivableRows, $receivableWarnings] = $this->buildReceivableRows($reportDate);
        [$inventoryRows, $inventoryWarnings] = $this->buildInventoryRows($reportDate);
        [$fixedAssetRows, $fixedAssetWarnings] = $this->buildFixedAssetRows($reportDate);
        [$payableRows, $payableWarnings] = $this->buildPayableRows($reportDate);
        $cashRows = collect($cashRows);
        $receivableRows = collect($receivableRows);
        $inventoryRows = collect($inventoryRows);
        $payableRows = collect($payableRows);
        $cashAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_CASH);
        $receivableAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_RECEIVABLE);
        $inventoryAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_INVENTORY);
        $fixedAssetAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_FIXED_ASSET);
        $payableAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_PAYABLE);
        $equityAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_EQUITY);
        $wealthAdjustmentRows = $this->buildAdjustmentRows($reportDate, BalanceSheetAdjustment::GROUP_WEALTH);
        $openingCapital = $this->calculateOpeningCapital($reportDate);
        [$retainedEarnings, $retainedWarnings] = $this->calculateProfitForRange(
            $this->profitComputationStartDate(),
            $priorPeriodEnd
        );
        [$currentPeriodProfit, $currentProfitWarnings] = $this->calculateProfitForRange(
            $currentPeriodStart,
            $reportDate
        );

        $assetGroups = collect([
            [
                'title' => 'Kas',
                'rows' => $cashRows->merge($cashAdjustmentRows)->values(),
                'total' => round((float) $cashRows->merge($cashAdjustmentRows)->sum('amount'), 2),
                'empty_label' => 'Belum ada saldo kas sampai tanggal laporan.',
            ],
            [
                'title' => 'Piutang Usaha',
                'rows' => $receivableRows->merge($receivableAdjustmentRows)->values(),
                'total' => round((float) $receivableRows->merge($receivableAdjustmentRows)->sum('amount'), 2),
                'empty_label' => 'Belum ada piutang outstanding sampai tanggal laporan.',
            ],
            [
                'title' => 'Aktiva Tetap',
                'rows' => $fixedAssetRows->merge($fixedAssetAdjustmentRows)->values(),
                'total' => round((float) $fixedAssetRows->merge($fixedAssetAdjustmentRows)->sum('amount'), 2),
                'empty_label' => 'Belum ada inventaris sampai tanggal laporan.',
            ],
            [
                'title' => 'Persediaan',
                'rows' => $inventoryRows->merge($inventoryAdjustmentRows)->values(),
                'total' => round((float) $inventoryRows->merge($inventoryAdjustmentRows)->sum('amount'), 2),
                'empty_label' => 'Belum ada nilai persediaan sampai tanggal laporan.',
            ],
        ]);

        $liabilityGroups = collect([
            [
                'title' => 'Kewajiban',
                'rows' => $payableRows->merge($payableAdjustmentRows)->values(),
                'total' => round((float) $payableRows->merge($payableAdjustmentRows)->sum('amount'), 2),
                'empty_label' => 'Belum ada hutang outstanding sampai tanggal laporan.',
            ],
        ]);

        $totalAssets = round((float) $assetGroups->sum('total'), 2);
        $totalLiabilities = round((float) $liabilityGroups->sum('total'), 2);
        $equityAmount = round($totalAssets - $totalLiabilities, 2);
        $capitalRows = collect([
            [
                'label' => 'Modal Awal',
                'meta' => 'Aset setup awal dikurangi kewajiban setup awal sampai tanggal laporan.',
                'amount' => $openingCapital,
            ],
        ])->merge($equityAdjustmentRows)->values();
        $wealthRows = collect([
            [
                'label' => 'Laba Ditahan',
                'meta' => $priorPeriodEnd->lt($currentPeriodStart)
                    ? 'Akumulasi laba/rugi sampai ' . $priorPeriodEnd->format('d-m-Y')
                    : 'Belum ada periode sebelum bulan laporan.',
                'amount' => $retainedEarnings,
            ],
            [
                'label' => 'Laba Berjalan',
                'meta' => 'Akumulasi laba/rugi ' . $currentPeriodStart->format('d-m-Y') . ' - ' . $reportDate->format('d-m-Y'),
                'amount' => $currentPeriodProfit,
            ],
        ])->merge($wealthAdjustmentRows)->values();
        $calculatedEquity = round((float) $capitalRows->sum('amount') + (float) $wealthRows->sum('amount'), 2);
        $equityAdjustment = round($equityAmount - $calculatedEquity, 2);

        if (abs($equityAdjustment) >= 0.005) {
            $wealthRows->push([
                'label' => 'Penyesuaian Neraca',
                'meta' => 'Selisih pembulatan atau transaksi yang belum dipetakan penuh ke laba rugi.',
                'amount' => $equityAdjustment,
            ]);
        }

        $capitalAmount = round((float) $capitalRows->sum('amount'), 2);
        $wealthAmount = round((float) $wealthRows->sum('amount'), 2);
        $equityRows = $capitalRows->merge($wealthRows)->values();

        $warnings = collect()
            ->merge($cashWarnings)
            ->merge($receivableWarnings)
            ->merge($inventoryWarnings)
            ->merge($fixedAssetWarnings)
            ->merge($payableWarnings)
            ->merge($retainedWarnings)
            ->merge($currentProfitWarnings)
            ->filter()
            ->unique()
            ->values();

        return [
            'reportDate' => $reportDate,
            'assetGroups' => $assetGroups,
            'liabilityGroups' => $liabilityGroups,
            'capitalRows' => $capitalRows,
            'wealthRows' => $wealthRows,
            'equityRows' => $equityRows,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'capitalAmount' => $capitalAmount,
            'wealthAmount' => $wealthAmount,
            'equityAmount' => $equityAmount,
            'totalLiabilitiesAndEquity' => round($totalLiabilities + $equityAmount, 2),
            'warnings' => $warnings,
            'currentPeriodStart' => $currentPeriodStart,
            'priorPeriodEnd' => $priorPeriodEnd,
        ];
    }

    protected function buildAdjustmentRows(Carbon $reportDate, string $group): Collection
    {
        return BalanceSheetAdjustment::query()
            ->where('account_group', $group)
            ->whereDate('adjustment_date', '<=', $reportDate->toDateString())
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get(['adjustment_date', 'label', 'amount', 'notes'])
            ->map(function (BalanceSheetAdjustment $adjustment) {
                return [
                    'label' => $adjustment->label,
                    'meta' => collect([
                        'Adjustment Neraca per ' . optional($adjustment->adjustment_date)->format('d-m-Y'),
                        $adjustment->notes,
                    ])->filter()->implode(' | '),
                    'amount' => round((float) $adjustment->amount, 2),
                ];
            });
    }

    protected function buildCashRows(Carbon $reportDate): array
    {
        $cashAccounts = CashAccount::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $openingByAccount = OpeningBalance::query()
            ->where('type', 'cash')
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->selectRaw('reference_id, SUM(amount) as total_amount')
            ->groupBy('reference_id')
            ->pluck('total_amount', 'reference_id');

        // Ongkir di-book sebagai OtherIncome (Penjualan Lain-Lain) saat cash_received,
        // jadi di sini cuma ambil porsi principal supaya tidak double-count dengan OtherIncome.
        $poReceiptsByAccount = PurchaseOrder::query()
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_account_id')
            ->whereNotNull('cash_received_at')
            ->where('cash_received_at', '<=', $reportDate->copy()->endOfDay())
            ->selectRaw('cash_account_id, SUM(COALESCE(total_amount, 0) - COALESCE(shipping_cost, 0)) as total_amount')
            ->groupBy('cash_account_id')
            ->pluck('total_amount', 'cash_account_id');

        $otherIncomeByAccount = OtherIncome::query()
            ->whereDate('income_date', '<=', $reportDate->toDateString())
            ->selectRaw('cash_account_id, SUM(amount) as total_amount')
            ->groupBy('cash_account_id')
            ->pluck('total_amount', 'cash_account_id');

        $expenseByAccount = CashOut::query()
            ->whereDate('expense_date', '<=', $reportDate->toDateString())
            ->selectRaw('cash_account_id, SUM(amount) as total_amount')
            ->groupBy('cash_account_id')
            ->pluck('total_amount', 'cash_account_id');

        $rows = $cashAccounts
            ->map(function (CashAccount $account) use (
                $openingByAccount,
                $poReceiptsByAccount,
                $otherIncomeByAccount,
                $expenseByAccount
            ) {
                $opening = round((float) ($openingByAccount[$account->id] ?? 0), 2);
                $poReceipts = round((float) ($poReceiptsByAccount[$account->id] ?? 0), 2);
                $otherIncome = round((float) ($otherIncomeByAccount[$account->id] ?? 0), 2);
                $expenses = round((float) ($expenseByAccount[$account->id] ?? 0), 2);
                $amount = round($opening + $poReceipts + $otherIncome - $expenses, 2);

                if (abs($amount) < 0.005 && abs($opening) < 0.005 && abs($poReceipts) < 0.005 && abs($otherIncome) < 0.005 && abs($expenses) < 0.005) {
                    return null;
                }

                return [
                    'label' => $account->name,
                    'meta' => collect([
                        $opening !== 0.0 ? 'Saldo awal Rp ' . number_format($opening, 0, ',', '.') : null,
                        $poReceipts !== 0.0 ? 'Pelunasan piutang PO Rp ' . number_format($poReceipts, 0, ',', '.') : null,
                        $otherIncome !== 0.0 ? 'Pemasukan lain Rp ' . number_format($otherIncome, 0, ',', '.') : null,
                        $expenses !== 0.0 ? 'Cash out Rp ' . number_format($expenses, 0, ',', '.') : null,
                    ])->filter()->implode(' | '),
                    'amount' => $amount,
                ];
            })
            ->filter()
            ->values();

        return [$rows, collect()];
    }

    protected function buildReceivableRows(Carbon $reportDate): array
    {
        $openingRows = collect(OpeningBalance::query()
            ->with('customer:id,name')
            ->where('type', 'receivable')
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->orderBy('balance_date')
            ->orderBy('id')
            ->get(['id', 'balance_date', 'reference_id', 'amount', 'description'])
            ->map(function (OpeningBalance $openingBalance) {
                return [
                    'label' => $openingBalance->customer?->name
                        ?: ($openingBalance->description ?: 'Saldo awal piutang #' . $openingBalance->id),
                    'meta' => 'Saldo awal per ' . optional($openingBalance->balance_date)->format('d-m-Y'),
                    'amount' => round((float) $openingBalance->amount, 2),
                ];
            }));

        $poRows = collect(PurchaseOrder::query()
            ->with('customer:id,name')
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $reportDate->copy()->endOfDay())
            ->where(function ($query) use ($reportDate) {
                $query
                    ->whereNull('cash_received_at')
                    ->orWhere('cash_received_at', '>', $reportDate->copy()->endOfDay());
            })
            ->orderBy('completed_at')
            ->orderBy('po_number')
            ->get([
                'id',
                'po_number',
                'customer_id',
                'recipient_name',
                'completed_at',
                'due_date',
                'total_amount',
            ])
            ->map(function (PurchaseOrder $po) {
                return [
                    'label' => ($po->customer?->name ?: $po->recipient_name ?: 'Piutang PO')
                        . ' - '
                        . $po->po_number,
                    'meta' => collect([
                        $po->completed_at ? 'PO selesai ' . $po->completed_at->format('d-m-Y') : null,
                        $po->due_date ? 'Jatuh tempo ' . $po->due_date->format('d-m-Y') : null,
                    ])->filter()->implode(' | '),
                    'amount' => round((float) $po->total_amount, 2),
                ];
            }));

        $warnings = collect();

        if ($openingRows->isNotEmpty()) {
            $warnings->push('Saldo awal piutang ditampilkan penuh karena sistem belum memiliki pelunasan khusus untuk saldo awal piutang.');
        }

        return [$openingRows->merge($poRows)->values(), $warnings];
    }

    protected function buildInventoryRows(Carbon $reportDate): array
    {
        // Nilai persediaan di neraca = stok fisik yang tersisa pada tanggal laporan,
        // yaitu nilai stock opname TERAKHIR (opname_date <= report_date) per item.
        // BUKAN saldo awal + pembelian, karena itu tidak mengurangi pemakaian/HPP
        // dan akan meng-overstate persediaan.
        $itemsByCategory = InventoryItem::query()
            ->whereIn('category', InventoryItem::stockCategories())
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'category'])
            ->groupBy('category');

        $rows = collect();
        $warnings = collect();

        foreach ($itemsByCategory as $category => $categoryItems) {
            /** @var InventoryItem $firstItem */
            $firstItem = $categoryItems->first();
            $categoryLabel = $firstItem->categoryLabel();

            $categoryValue = 0.0;
            $fallbackItemNames = collect();

            foreach ($categoryItems as $item) {
                $latestOpname = StockOpname::query()
                    ->where('inventory_item_id', $item->id)
                    ->whereDate('opname_date', '<=', $reportDate->toDateString())
                    ->orderByDesc('opname_date')
                    ->orderByDesc('id')
                    ->first(['total_value']);

                if ($latestOpname) {
                    $categoryValue += (float) $latestOpname->total_value;
                    continue;
                }

                // Belum pernah ada stock opname: pakai estimasi saldo awal + pembelian
                // (belum dikurangi pemakaian) sebagai fallback terakhir, sambil kasih
                // warning supaya di-opname.
                $opening = (float) InventoryOpening::query()
                    ->where('inventory_item_id', $item->id)
                    ->whereDate('balance_date', '<=', $reportDate->toDateString())
                    ->sum('total_value');

                $purchases = (float) InventoryPurchase::query()
                    ->where('inventory_item_id', $item->id)
                    ->whereDate('transaction_date', '<=', $reportDate->toDateString())
                    ->sum('total_value');

                $itemFallback = $opening + $purchases;

                if (abs($itemFallback) >= 0.005) {
                    $categoryValue += $itemFallback;
                    $fallbackItemNames->push($item->name);
                }
            }

            if (abs($categoryValue) >= 0.005) {
                $rows->push([
                    'label' => $categoryLabel . ' - Persediaan Akhir',
                    'meta' => 'Nilai stock opname terakhir ' . $categoryLabel . ' s/d ' . $reportDate->format('d-m-Y'),
                    'amount' => round($categoryValue, 2),
                ]);
            }

            if ($fallbackItemNames->isNotEmpty()) {
                $warnings->push(sprintf(
                    'Persediaan %s belum punya stock opname s/d %s untuk: %s. Nilainya pakai estimasi saldo awal + pembelian (belum dikurangi pemakaian).',
                    $categoryLabel,
                    $reportDate->format('d-m-Y'),
                    $fallbackItemNames->unique()->values()->implode(', ')
                ));
            }
        }

        return [$rows->values(), $warnings];
    }

    protected function buildFixedAssetRows(Carbon $reportDate): array
    {
        $openingByItem = InventoryOpening::query()
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->selectRaw('inventory_item_id, SUM(total_value) as total_amount')
            ->groupBy('inventory_item_id')
            ->pluck('total_amount', 'inventory_item_id');

        $purchaseByItem = InventoryPurchase::query()
            ->whereDate('transaction_date', '<=', $reportDate->toDateString())
            ->selectRaw('inventory_item_id, SUM(total_value) as total_amount')
            ->groupBy('inventory_item_id')
            ->pluck('total_amount', 'inventory_item_id');

        $relevantItemIds = collect()
            ->merge($openingByItem->keys())
            ->merge($purchaseByItem->keys())
            ->unique()
            ->values();

        $rows = InventoryItem::query()
            ->where('category', InventoryItem::CATEGORY_FIXED_ASSET)
            ->when($relevantItemIds->isNotEmpty(), function ($query) use ($relevantItemIds) {
                $query->whereIn('id', $relevantItemIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'unit'])
            ->flatMap(function (InventoryItem $item) use ($openingByItem, $purchaseByItem, $reportDate) {
                $opening = round((float) ($openingByItem[$item->id] ?? 0), 2);
                $purchases = round((float) ($purchaseByItem[$item->id] ?? 0), 2);
                $itemLabel = $item->name . ($item->unit ? ' (' . $item->unit . ')' : '');
                $rows = collect();

                if (abs($opening) >= 0.005) {
                    $rows->push([
                        'label' => $itemLabel . ' - Inventaris Lama',
                        'meta' => 'Saldo awal inventaris s/d ' . $reportDate->format('d-m-Y'),
                        'amount' => $opening,
                    ]);
                }

                if (abs($purchases) >= 0.005) {
                    $rows->push([
                        'label' => $itemLabel . ' - Inventaris Baru',
                        'meta' => 'Pembelian inventaris s/d ' . $reportDate->format('d-m-Y'),
                        'amount' => $purchases,
                    ]);
                }

                return $rows;
            })
            ->values();

        // Pembelian aktiva tetap yang dicatat lewat Pengeluaran (cash out) dengan
        // kategori bertipe Aktiva Tetap. Ini dikapitalisasi ke aktiva tetap, bukan
        // dibebankan ke laba rugi.
        $fixedAssetExpenseRows = CashOut::query()
            ->with('category:id,name,expense_mode')
            ->whereDate('expense_date', '<=', $reportDate->toDateString())
            ->whereHas('category', function ($query) {
                $query->where('expense_mode', ExpenseCategory::MODE_FIXED_ASSET);
            })
            ->get(['expense_category_id', 'amount'])
            ->groupBy(fn (CashOut $expense) => $expense->category?->name ?: 'Aktiva Tetap')
            ->map(fn (Collection $group, string $label) => [
                'label' => $label . ' - Pembelian (Pengeluaran)',
                'meta' => 'Pembelian aktiva tetap via pengeluaran s/d ' . $reportDate->format('d-m-Y'),
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->filter(fn (array $row) => abs((float) $row['amount']) >= 0.005)
            ->values();

        $rows = $rows->merge($fixedAssetExpenseRows)->values();

        return [$rows, collect()];
    }

    protected function buildPayableRows(Carbon $reportDate): array
    {
        $payableRows = collect(Payable::query()
            ->whereDate('transaction_date', '<=', $reportDate->toDateString())
            ->where(function ($query) use ($reportDate) {
                $query
                    ->whereNull('paid_at')
                    ->orWhere('paid_at', '>', $reportDate->copy()->endOfDay());
            })
            ->orderBy('transaction_date')
            ->orderBy('supplier_name')
            ->get([
                'id',
                'opening_balance_id',
                'transaction_date',
                'due_date',
                'supplier_name',
                'description',
                'amount',
                'status',
            ])
            ->map(function (Payable $payable) {
                return [
                    'label' => $payable->supplier_name,
                    'meta' => collect([
                        $payable->opening_balance_id ? 'Saldo awal hutang' : ($payable->description ?: 'Hutang supplier'),
                        $payable->transaction_date ? 'Tanggal ' . $payable->transaction_date->format('d-m-Y') : null,
                        $payable->due_date ? 'Jatuh tempo ' . $payable->due_date->format('d-m-Y') : null,
                    ])->filter()->implode(' | '),
                    'amount' => round((float) $payable->amount, 2),
                    'is_partial' => $payable->status === 'partial',
                ];
            }));

        $openingFallbackRows = collect(OpeningBalance::query()
            ->doesntHave('payable')
            ->where('type', 'payable')
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->orderBy('balance_date')
            ->orderBy('id')
            ->get(['id', 'balance_date', 'supplier_name', 'description', 'amount'])
            ->map(function (OpeningBalance $openingBalance) {
                return [
                    'label' => $openingBalance->supplier_name ?: ($openingBalance->description ?: 'Saldo awal hutang #' . $openingBalance->id),
                    'meta' => 'Saldo awal hutang | Tanggal ' . optional($openingBalance->balance_date)->format('d-m-Y'),
                    'amount' => round((float) $openingBalance->amount, 2),
                    'is_partial' => false,
                ];
            }));

        $rows = $payableRows->merge($openingFallbackRows)->values();

        $warnings = collect();

        if ($rows->contains(fn (array $row) => $row['is_partial'] ?? false)) {
            $warnings->push('Hutang dengan status partial tetap ditampilkan dengan nominal penuh karena sistem belum menyimpan sisa hutang parsial secara terpisah.');
        }

        return [
            $rows->map(function (array $row) {
                unset($row['is_partial']);

                return $row;
            })->values(),
            $warnings,
        ];
    }

    protected function calculateOpeningCapital(Carbon $reportDate): float
    {
        $openingCash = (float) OpeningBalance::query()
            ->where('type', 'cash')
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->sum('amount');

        $openingReceivable = (float) OpeningBalance::query()
            ->where('type', 'receivable')
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->sum('amount');

        $openingInventory = (float) InventoryOpening::query()
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->sum('total_value');

        $openingPayable = (float) OpeningBalance::query()
            ->where('type', 'payable')
            ->whereDate('balance_date', '<=', $reportDate->toDateString())
            ->sum('amount');

        return round($openingCash + $openingReceivable + $openingInventory - $openingPayable, 2);
    }

    protected function profitComputationStartDate(): ?Carbon
    {
        $dates = collect([
            SalesActual::query()->whereNotNull('submitted_at')->min('submitted_at'),
            SalesDailyClosing::query()->min('closing_date'),
            OtherIncome::query()
                ->whereDoesntHave('category', function ($query) {
                    $query->whereRaw('LOWER(TRIM(name)) = ?', ['adjustment']);
                })
                ->min('income_date'),
            CashOut::query()
                ->whereDoesntHave('category', function ($query) {
                    $query->whereRaw('LOWER(TRIM(name)) = ?', ['adjustment']);
                })
                ->min('expense_date'),
            InventoryOpening::query()->min('balance_date'),
            InventoryPurchase::query()->min('transaction_date'),
            StockOpname::query()->min('opname_date'),
            ProfitLossAdjustment::query()->min('adjustment_date'),
        ])->filter();

        if ($dates->isEmpty()) {
            return null;
        }

        return $dates
            ->map(fn ($date) => Carbon::parse((string) $date)->startOfDay())
            ->sort()
            ->first();
    }

    protected function calculateProfitForRange(?Carbon $dateFrom, Carbon $dateTo): array
    {
        if (! $dateFrom || $dateFrom->gt($dateTo)) {
            return [0.0, collect()];
        }

        $salesRevenue = round((float) SalesActualItem::query()
            ->whereHas('salesActual', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'submitted')
                    ->whereBetween('submitted_at', [
                        $dateFrom->copy()->startOfDay(),
                        $dateTo->copy()->endOfDay(),
                    ]);
            })
            ->sum('subtotal_actual'), 2);
        $salesDiscountTotal = round((float) SalesDailyClosing::query()
            ->whereDate('closing_date', '>=', $dateFrom->toDateString())
            ->whereDate('closing_date', '<=', $dateTo->toDateString())
            ->sum('discount_amount'), 2);
        $salesRevenue = round($salesRevenue - $salesDiscountTotal, 2);

        $otherIncomeTotal = round((float) OtherIncome::query()
            ->with('category:id,name')
            ->whereDate('income_date', '>=', $dateFrom->toDateString())
            ->whereDate('income_date', '<=', $dateTo->toDateString())
            ->get(['income_category_id', 'source_type', 'source_id', 'amount', 'description'])
            ->reject(fn (OtherIncome $income) => $income->isGeneratedBySalesActual() || $this->isAdjustmentIncomeCategory($income))
            ->sum('amount'), 2);

        $operatingExpenseTotal = round((float) CashOut::query()
            ->with('category:id,name')
            ->whereDate('expense_date', '>=', $dateFrom->toDateString())
            ->whereDate('expense_date', '<=', $dateTo->toDateString())
            ->whereHas('category', function ($query) {
                $query->where('expense_mode', ExpenseCategory::MODE_DIRECT_EXPENSE)
                    ->where('name', '!=', self::PAYABLE_SETTLEMENT_CATEGORY_NAME);
            })
            ->get(['expense_category_id', 'amount'])
            ->reject(fn (CashOut $expense) => $this->isAdjustmentExpenseCategory($expense))
            ->sum('amount'), 2);

        $profitLossAdjustments = ProfitLossAdjustment::query()
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->get(['statement_group', 'amount'])
            ->groupBy('statement_group');

        $salesRevenue = round($salesRevenue + (float) ($profitLossAdjustments[ProfitLossAdjustment::GROUP_REVENUE] ?? collect())->sum('amount'), 2);
        $otherIncomeTotal = round($otherIncomeTotal + (float) ($profitLossAdjustments[ProfitLossAdjustment::GROUP_OTHER_INCOME] ?? collect())->sum('amount'), 2);
        $operatingExpenseTotal = round($operatingExpenseTotal + (float) ($profitLossAdjustments[ProfitLossAdjustment::GROUP_OPERATING_EXPENSE] ?? collect())->sum('amount'), 2);

        $inventoryWarnings = collect();
        $cogsTotal = round((float) InventoryItem::query()
            ->whereIn('category', InventoryItem::stockCategories())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (InventoryItem $item) use ($dateFrom, $dateTo, $inventoryWarnings) {
                $summary = $this->inventoryUsageService->calculateForItem($item->id, $dateFrom, $dateTo);
                $opening = round((float) ($summary['opening'] ?? 0), 2);
                $purchases = round((float) ($summary['purchases'] ?? 0), 2);
                $ending = round((float) ($summary['ending'] ?? 0), 2);
                $usage = round((float) ($summary['usage'] ?? 0), 2);

                if (abs($opening) < 0.005 && abs($purchases) < 0.005 && abs($ending) < 0.005 && abs($usage) < 0.005) {
                    return 0.0;
                }

                if (($opening > 0 || $purchases > 0) && ! StockOpname::query()
                    ->where('inventory_item_id', $item->id)
                    ->whereDate('opname_date', '<=', $dateTo->toDateString())
                    ->exists()) {
                    $inventoryWarnings->push(sprintf(
                        'Perhitungan laba rugi untuk %s sampai %s belum punya stock opname penutup, jadi HPP bisa belum akurat.',
                        $item->name,
                        $dateTo->format('d-m-Y')
                    ));
                }

                return $usage;
            })
            ->sum(), 2);

        $cogsTotal = round($cogsTotal + (float) ($profitLossAdjustments[ProfitLossAdjustment::GROUP_COGS] ?? collect())->sum('amount'), 2);
        $netProfit = round($salesRevenue + $otherIncomeTotal - $operatingExpenseTotal - $cogsTotal, 2);

        return [$netProfit, $inventoryWarnings->unique()->values()];
    }

    protected function isAdjustmentIncomeCategory(OtherIncome $income): bool
    {
        return strcasecmp(trim((string) ($income->category?->name ?? '')), 'Adjustment') === 0;
    }

    protected function isAdjustmentExpenseCategory(CashOut $expense): bool
    {
        return strcasecmp(trim((string) ($expense->category?->name ?? '')), 'Adjustment') === 0;
    }
}
