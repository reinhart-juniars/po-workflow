<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Http\Controllers\Concerns\ChecksPeriodClosing;
use App\Http\Controllers\Concerns\ResolvesCentralExpenseLocation;
use App\Models\CashOut;
use App\Models\AuditLog;
use App\Models\BalanceSheetAdjustment;
use App\Models\CashAccount;
use App\Models\CashAccountTransfer;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\OpeningBalance;
use App\Models\OtherIncome;
use App\Models\Payable;
use App\Models\PeriodClosing;
use App\Models\ProfitLossAdjustment;
use App\Models\PurchaseOrder;
use App\Models\SalesActualItem;
use App\Models\SalesDailyClosing;
use App\Models\User;
use App\Services\BalanceSheetService;
use App\Services\SalesActualService;
use App\Services\SalesReportService;
use App\Support\UiLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class AccountingAppController extends Controller
{
    use ChecksPeriodClosing;
    use ResolvesCentralExpenseLocation;

    protected const PAYABLE_SETTLEMENT_CATEGORY_NAME = 'Pembayaran Hutang';

    public function dashboard(Request $request, BalanceSheetService $balanceSheetService)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $reportPeriodMonth = (int) $dateTo->month;
        $reportPeriodYear = (int) $dateTo->year;
        $rangePeriodStatus = $this->periodStatusForRange($dateFrom, $dateTo);
        $balanceSheetSummary = $balanceSheetService->buildReport($dateTo->copy()->endOfDay());

        $expenseQuery = CashOut::query()
            ->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]);

        // Saldo Awal Kas = saldo carry-forward sebelum periode (sama logika dengan Cashflow).
        // Termasuk OpeningBalance baseline + PO cash received + OtherIncome - CashOut yang
        // terjadi sebelum $dateFrom.
        $openingCash = $this->cashCarryForwardBefore($dateFrom);

        // Pendapatan/Penerimaan PO = PO yang cash-received di periode (cash basis), match
        // dengan "Penerimaan PO" di Cashflow. Bukan sales actual revenue (yang accrual basis).
        // Ongkir dipisah ke OtherIncome (Penjualan Lain-Lain) supaya tidak double-count.
        $totalCashIn = (float) $this->cashInQuery($dateFrom, $dateTo)
            ->sum(DB::raw($this->poCashInPrincipalExpression()));

        $otherIncomeTotal = OtherIncome::query()
            ->whereBetween('income_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->sum('amount');

        $totalCashInAll = $totalCashIn + $otherIncomeTotal;
        $totalExpense = (clone $expenseQuery)->sum('amount');
        $endingCash = $openingCash + $totalCashInAll - $totalExpense;

        $openingReceivable = OpeningBalance::query()
            ->where('type', 'receivable')
            ->whereDate('balance_date', '<=', $dateTo->toDateString())
            ->sum('amount');

        $newReceivables = PurchaseOrder::query()
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereBetween('completed_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ], 'and')
            ->sum('total_amount');

        $receivableCollections = PurchaseOrder::query()
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at', 'and')
            ->whereBetween('cash_received_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ], 'and')
            ->sum('total_amount');

        $outstandingReceivable = $openingReceivable + $newReceivables - $receivableCollections;
        $today = Carbon::today();
        $openReceivablesBaseQuery = PurchaseOrder::query()->openReceivable();
        $overdueReceivablesCount = (clone $openReceivablesBaseQuery)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->count();
        $dueTodayReceivablesCount = (clone $openReceivablesBaseQuery)
            ->whereDate('due_date', $today->toDateString())
            ->count();
        $dueSoonReceivablesCount = (clone $openReceivablesBaseQuery)
            ->whereBetween('due_date', [
                $today->copy()->addDay()->toDateString(),
                $today->copy()->addDays(7)->toDateString(),
            ])
            ->count();
        $openReceivablesWithoutDueDateCount = (clone $openReceivablesBaseQuery)
            ->whereNull('due_date')
            ->count();
        $openPayablesBaseQuery = Payable::query()
            ->whereIn('status', ['unpaid', 'partial']);
        $outstandingPayable = (clone $openPayablesBaseQuery)->sum('amount');
        $overduePayablesCount = (clone $openPayablesBaseQuery)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->count();
        $dueTodayPayablesCount = (clone $openPayablesBaseQuery)
            ->whereDate('due_date', $today->toDateString())
            ->count();
        $dueSoonPayablesCount = (clone $openPayablesBaseQuery)
            ->whereBetween('due_date', [
                $today->copy()->addDay()->toDateString(),
                $today->copy()->addDays(7)->toDateString(),
            ])
            ->count();
        $openPayablesWithoutDueDateCount = (clone $openPayablesBaseQuery)
            ->whereNull('due_date')
            ->count();

        $salesPerMonth = PurchaseOrder::query()
            ->selectRaw($this->yearMonthSelectSql('completed_at') . ', SUM(total_amount) as total')
            ->where('status', 'completed')
            ->whereNotNull('completed_at', 'and')
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        $expensePerMonth = CashOut::query()
            ->selectRaw($this->yearMonthSelectSql('expense_date') . ', SUM(amount) as total')
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        $recentExpenses = CashOut::with(['category', 'creator'])
            ->latest('expense_date')
            ->take(10)
            ->get();

        $reportPeriodClosing = PeriodClosing::query()
            ->where('period_month', $reportPeriodMonth)
            ->where('period_year', $reportPeriodYear)
            ->first();

        $latestClosedPeriod = PeriodClosing::query()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        $reportPeriodLabel = $this->monthLabel($reportPeriodMonth) . ' ' . $reportPeriodYear;
        $latestClosedPeriodLabel = $latestClosedPeriod
            ? $this->monthLabel((int) $latestClosedPeriod->period_month) . ' ' . $latestClosedPeriod->period_year
            : null;
        $closedPeriodsThisYear = PeriodClosing::query()
            ->where('period_year', $reportPeriodYear)
            ->count();
        $previousOpenPeriodWarning = $this->previousOpenPeriodWarning();
        return view('accountingapp.dashboard', compact(
            'openingCash',
            'totalCashIn',
            'otherIncomeTotal',
            'totalCashInAll',
            'totalExpense',
            'endingCash',
            'balanceSheetSummary',
            'openingReceivable',
            'newReceivables',
            'receivableCollections',
            'outstandingReceivable',
            'overdueReceivablesCount',
            'dueTodayReceivablesCount',
            'dueSoonReceivablesCount',
            'openReceivablesWithoutDueDateCount',
            'outstandingPayable',
            'overduePayablesCount',
            'dueTodayPayablesCount',
            'dueSoonPayablesCount',
            'openPayablesWithoutDueDateCount',
            'salesPerMonth',
            'expensePerMonth',
            'recentExpenses',
            'dateFrom',
            'dateTo',
            'reportPeriodClosing',
            'reportPeriodLabel',
            'latestClosedPeriodLabel',
            'closedPeriodsThisYear',
            'reportPeriodYear',
            'rangePeriodStatus',
            'previousOpenPeriodWarning'
        ));
    }

    public function payablesIndex(Request $request)
    {
        $today = Carbon::today();
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $supplierName = trim((string) $request->input('supplier_name', ''));
        $source = $request->input('source');
        $urgency = $request->input('urgency');

        $baseQuery = Payable::query()
            ->with(['openingBalance', 'inventoryPurchases.item'])
            ->whereIn('status', ['unpaid', 'partial']);

        $filteredQuery = (clone $baseQuery)
            ->when($supplierName !== '', function ($query) use ($supplierName) {
                $query->where('supplier_name', 'like', '%' . $supplierName . '%');
            })
            ->when($source === 'inventory_purchase', function ($query) {
                $query->whereHas('inventoryPurchases');
            })
            ->when($source === 'opening_balance', function ($query) {
                $query->whereNotNull('opening_balance_id');
            })
            ->when($source === 'other', function ($query) {
                $query->whereNull('opening_balance_id')
                    ->whereDoesntHave('inventoryPurchases');
            })
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('due_date', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('due_date', '<=', $dateTo);
            });

        if ($urgency === 'overdue') {
            $filteredQuery->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today->toDateString());
        } elseif ($urgency === 'today') {
            $filteredQuery->whereDate('due_date', $today->toDateString());
        } elseif ($urgency === 'next_7_days') {
            $filteredQuery->whereBetween('due_date', [
                $today->copy()->addDay()->toDateString(),
                $today->copy()->addDays(7)->toDateString(),
            ]);
        } elseif ($urgency === 'no_due_date') {
            $filteredQuery->whereNull('due_date');
        }

        $payables = $filteredQuery
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('transaction_date')
            ->paginate(20)
            ->withQueryString();

        $payables->getCollection()->transform(function (Payable $payable) use ($today) {
            $dueDate = $payable->due_date;
            $daysRemaining = $dueDate ? $today->diffInDays($dueDate, false) : null;
            $sourceLabel = 'Hutang Lain';
            $sourceDetail = $payable->description ?: '-';

            if ($payable->opening_balance_id) {
                $sourceLabel = 'Saldo Awal';
                $sourceDetail = $payable->openingBalance?->description ?: 'Saldo awal hutang';
            } elseif ($payable->inventoryPurchases->isNotEmpty()) {
                $sourceLabel = 'Pembelian Stok';
                $purchase = $payable->inventoryPurchases->first();
                $itemName = $purchase?->item?->name ?? 'Item inventory';
                $sourceDetail = $itemName . ' x' . number_format((float) ($purchase?->qty ?? 0), 2, ',', '.');
            }

            $payable->setAttribute('days_remaining', $daysRemaining);
            $payable->setAttribute('source_label', $sourceLabel);
            $payable->setAttribute('source_detail', $sourceDetail);

            return $payable;
        });

        $overduePayablesCount = (clone $baseQuery)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->count();
        $dueTodayPayablesCount = (clone $baseQuery)
            ->whereDate('due_date', $today->toDateString())
            ->count();
        $dueSoonPayablesCount = (clone $baseQuery)
            ->whereBetween('due_date', [
                $today->copy()->addDay()->toDateString(),
                $today->copy()->addDays(7)->toDateString(),
            ])
            ->count();
        $openPayablesWithoutDueDateCount = (clone $baseQuery)
            ->whereNull('due_date')
            ->count();
        $outstandingPayable = (clone $baseQuery)->sum('amount');

        return view('accountingapp.payables.index', compact(
            'payables',
            'today',
            'dateFrom',
            'dateTo',
            'supplierName',
            'source',
            'urgency',
            'overduePayablesCount',
            'dueTodayPayablesCount',
            'dueSoonPayablesCount',
            'openPayablesWithoutDueDateCount',
            'outstandingPayable'
        ));
    }

    public function expensesIndex(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $rangePeriodStatus = $this->periodStatusForRange($dateFrom, $dateTo);

        $query = CashOut::with(['category', 'cashAccount', 'creator', 'updater', 'payable', 'inventoryPurchase.item'])
            ->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]);

        $categoryId = $request->input('expense_category_id');
        if ($categoryId) {
            $query->where('expense_category_id', $categoryId);
        }

        $cashAccountId = $request->input('cash_account_id');
        if ($cashAccountId) {
            $query->where('cash_account_id', $cashAccountId);
        }

        $expenses = $query->orderByDesc('expense_date')
            ->paginate(20)
            ->withQueryString();
        $expenses->getCollection()->transform(function (CashOut $expense) {
            $expense->setAttribute('period_closed', $this->isPeriodClosed($expense->expense_date->toDateString()));

            return $expense;
        });

        $categories = ExpenseCategory::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $expenseEntryCategories = $categories
            ->reject(fn (ExpenseCategory $category) => strcasecmp($category->name, self::PAYABLE_SETTLEMENT_CATEGORY_NAME) === 0)
            ->values();
        $cashAccounts = CashAccount::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $inventoryItems = InventoryItem::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $openPayables = Payable::whereIn('status', ['unpaid', 'partial'])
            ->orderBy('transaction_date')
            ->orderBy('supplier_name')
            ->get();

        return view('accountingapp.expenses.index', compact(
            'expenses',
            'categories',
            'expenseEntryCategories',
            'cashAccounts',
            'inventoryItems',
            'openPayables',
            'dateFrom',
            'dateTo',
            'categoryId',
            'cashAccountId',
            'rangePeriodStatus'
        ));
    }

    public function expensesStore(Request $request)
    {
        $data = $request->validate([
            'expense_flow' => ['nullable', Rule::in(['expense', 'payable_settlement'])],
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'cash_account_id' => ['nullable', 'exists:cash_accounts,id'],
            'amount' => ['nullable', 'numeric', 'min:1'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'adjustment_note' => ['nullable', 'string'],
            'payable_id' => ['nullable', 'exists:payables,id'],
            'inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'inventory_unit_cost' => ['nullable', 'numeric', 'gt:0'],
            'payment_type' => ['nullable', Rule::in(['cash', 'payable'])],
            'due_date' => ['nullable', 'date'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
        ]);

        $data['inventory_qty'] = 1;
        $expenseFlow = $data['expense_flow'] ?? 'expense';
        $this->normalizeExpenseFlowData($data, $expenseFlow);
        $isClosedPeriod = $this->isPeriodClosed($data['expense_date']);
        $category = $this->resolveExpenseCategoryForFlow($data, $expenseFlow);

        if ($isClosedPeriod) {
            if (! $this->currentUser()->hasRole('owner')) {
                return back()->withErrors([
                    'expense_date' => 'Periode ini sudah ditutup. Hanya owner yang dapat melakukan koreksi.',
                ])->withInput();
            }

            if (blank($request->input('adjustment_note'))) {
                return back()->withErrors([
                    'adjustment_note' => 'Catatan koreksi wajib diisi untuk periode yang sudah ditutup.',
                ])->withInput();
            }
        }

        $inventoryData = $this->extractInventoryPurchaseData($request, $category, $data);
        $payableSettlement = $this->extractPayableSettlementData($category, $data, $expenseFlow);
        $this->ensureExpenseCashAccountForFlow($category, $data, $inventoryData, $payableSettlement);
        $this->ensureExpenseAmountForCategory($category, $data, $payableSettlement);

        if ($inventoryData !== null && $inventoryData['payment_type'] === 'payable') {
            $inventoryPurchase = DB::transaction(function () use ($data, $inventoryData, $isClosedPeriod, $request) {
                return $this->createInventoryPurchasePayableFromExpense(
                    $data,
                    $inventoryData,
                    $isClosedPeriod,
                    $request->input('adjustment_note')
                );
            });

            AuditLog::create([
                'user_id' => $this->currentUserId(),
                'entity' => 'inventory_purchase',
                'entity_id' => $inventoryPurchase->id,
                'purchase_order_id' => null,
                'action' => 'created',
                'message' => sprintf(
                    'User %s menambahkan pembelian stok hutang ID %s pada %s',
                    $this->currentUser()->name,
                    $inventoryPurchase->id,
                    now()->format('d-m-Y H:i')
                ),
                'before_json' => null,
                'after_json' => $this->inventoryPurchaseAuditSnapshot($inventoryPurchase),
                'ip_address' => $request->ip(),
            ]);

            return redirect()
                ->route('accountingapp.expenses.index')
                ->with('success', 'Pembelian stok hutang berhasil ditambahkan. Cek detailnya di Monitoring Pembelian Stok.');
        }

        $cashOut = DB::transaction(function () use ($data, $inventoryData, $isClosedPeriod, $payableSettlement) {
            $cashOut = CashOut::create([
                'expense_category_id' => $data['expense_category_id'],
                'expense_location_id' => $this->centralExpenseLocationId(),
                'cash_account_id' => $data['cash_account_id'],
                'payable_id' => $data['payable_id'] ?? null,
                'amount' => $payableSettlement['amount'] ?? $inventoryData['amount'] ?? $data['amount'],
                'expense_date' => $data['expense_date'],
                'description' => $data['description'] ?? null,
                'is_adjustment' => $isClosedPeriod,
                'adjustment_note' => request()->input('adjustment_note'),
                'adjusted_by' => $isClosedPeriod ? $this->currentUserId() : null,
                'created_by' => $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]);

            $this->syncInventoryPurchaseForCashOut($cashOut, $inventoryData, $data['description'] ?? null);
            $this->syncPayableSettlementForCashOut($cashOut, null, $payableSettlement['payable_id'] ?? null);

            return $cashOut;
        });

        $cashOut->load(['category', 'cashAccount', 'payable', 'inventoryPurchase.item']);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'cash_out',
            'entity_id' => $cashOut->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan pengeluaran ID %s pada %s',
                $this->currentUser()->name,
                $cashOut->id,
                now()->format('d-m-Y H:i')
            ),
            'before_json' => null,
            'after_json' => [
                'id' => $cashOut->id,
                'category' => $cashOut->category->name ?? null,
                'cash_account' => $cashOut->cashAccount->name ?? null,
                'amount' => $cashOut->amount,
                'expense_date' => $cashOut->expense_date?->toDateString(),
                'description' => $cashOut->description,
                'is_adjustment' => $cashOut->is_adjustment,
                'adjustment_note' => $cashOut->adjustment_note,
                'adjusted_by' => $cashOut->adjusted_by,
                'payable' => $cashOut->payable?->supplier_name,
                'inventory_item' => $cashOut->inventoryPurchase?->item?->name,
                'inventory_qty' => $cashOut->inventoryPurchase?->qty,
                'inventory_unit_cost' => $cashOut->inventoryPurchase?->unit_cost,
            ],
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.expenses.index')
            ->with('success', 'Pengeluaran berhasil ditambahkan.');
    }

    public function expensesEdit(CashOut $cashOut)
    {
        $categories = ExpenseCategory::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $cashAccounts = CashAccount::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $inventoryItems = InventoryItem::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $cashOut->loadMissing('inventoryPurchase.item');
        $openPayables = Payable::query()
            ->where(function ($query) use ($cashOut) {
                $query->whereIn('status', ['unpaid', 'partial']);

                if ($cashOut->payable_id) {
                    $query->orWhere('id', '=', $cashOut->payable_id);
                }
            })
            ->orderBy('transaction_date')
            ->orderBy('supplier_name')
            ->get();
        $isClosedPeriod = $this->isPeriodClosed($cashOut->expense_date->toDateString());
        $closedPeriodLabel = $this->periodLabel((int) $cashOut->expense_date->month, (int) $cashOut->expense_date->year);

        return view('accountingapp.expenses.edit', compact(
            'cashOut',
            'categories',
            'cashAccounts',
            'inventoryItems',
            'openPayables',
            'isClosedPeriod',
            'closedPeriodLabel'
        ));
    }

    public function expensesUpdate(Request $request, CashOut $cashOut)
    {
        $data = $request->validate([
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount' => ['nullable', 'numeric', 'min:1'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'adjustment_note' => ['nullable', 'string'],
            'payable_id' => ['nullable', 'exists:payables,id'],
            'inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'inventory_unit_cost' => ['nullable', 'numeric', 'gt:0'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
        ]);

        $data['inventory_qty'] = 1;
        $isClosedPeriod = $this->isPeriodClosed($data['expense_date']);
        $category = ExpenseCategory::findOrFail($data['expense_category_id']);

        if ($isClosedPeriod) {
            if (! $this->currentUser()->hasRole('owner')) {
                return back()->withErrors([
                    'expense_date' => 'Periode ini sudah ditutup. Hanya owner yang dapat melakukan koreksi.',
                ])->withInput();
            }

            if (blank($request->input('adjustment_note'))) {
                return back()->withErrors([
                    'adjustment_note' => 'Catatan koreksi wajib diisi untuk periode yang sudah ditutup.',
                ])->withInput();
            }
        }

        $cashOut->load(['category', 'cashAccount', 'payable', 'inventoryPurchase.item']);
        $inventoryData = $this->extractInventoryPurchaseData($request, $category, $data);
        $payableSettlement = $this->extractPayableSettlementData($category, $data);
        $this->ensureExpenseAmountForCategory($category, $data, $payableSettlement);
        $oldPayableId = $cashOut->payable_id;

        $before = [
            'id' => $cashOut->id,
            'category' => $cashOut->category->name ?? null,
            'cash_account' => $cashOut->cashAccount->name ?? null,
            'amount' => $cashOut->amount,
            'expense_date' => $cashOut->expense_date?->toDateString(),
            'description' => $cashOut->description,
            'is_adjustment' => $cashOut->is_adjustment,
            'adjustment_note' => $cashOut->adjustment_note,
            'adjusted_by' => $cashOut->adjusted_by,
            'payable' => $cashOut->payable?->supplier_name,
            'inventory_item' => $cashOut->inventoryPurchase?->item?->name,
            'inventory_qty' => $cashOut->inventoryPurchase?->qty,
            'inventory_unit_cost' => $cashOut->inventoryPurchase?->unit_cost,
        ];

        DB::transaction(function () use ($cashOut, $data, $inventoryData, $isClosedPeriod, $request, $oldPayableId, $payableSettlement) {
            $cashOut->update([
                'expense_category_id' => $data['expense_category_id'],
                'expense_location_id' => $this->centralExpenseLocationId(),
                'cash_account_id' => $data['cash_account_id'],
                'payable_id' => $data['payable_id'] ?? null,
                'amount' => $payableSettlement['amount'] ?? $inventoryData['amount'] ?? $data['amount'],
                'expense_date' => $data['expense_date'],
                'description' => $data['description'] ?? null,
                'is_adjustment' => $isClosedPeriod,
                'adjustment_note' => $request->input('adjustment_note'),
                'adjusted_by' => $isClosedPeriod ? $this->currentUserId() : null,
                'updated_by' => $this->currentUserId(),
            ]);

            $this->syncInventoryPurchaseForCashOut($cashOut, $inventoryData, $data['description'] ?? null);
            $this->syncPayableSettlementForCashOut($cashOut, $oldPayableId, $payableSettlement['payable_id'] ?? null);
        });

        $cashOut->refresh()->load(['category', 'cashAccount', 'payable', 'inventoryPurchase.item']);

        $after = [
            'id' => $cashOut->id,
            'category' => $cashOut->category->name ?? null,
            'cash_account' => $cashOut->cashAccount->name ?? null,
            'amount' => $cashOut->amount,
            'expense_date' => $cashOut->expense_date?->toDateString(),
            'description' => $cashOut->description,
            'is_adjustment' => $cashOut->is_adjustment,
            'adjustment_note' => $cashOut->adjustment_note,
            'adjusted_by' => $cashOut->adjusted_by,
            'payable' => $cashOut->payable?->supplier_name,
            'inventory_item' => $cashOut->inventoryPurchase?->item?->name,
            'inventory_qty' => $cashOut->inventoryPurchase?->qty,
            'inventory_unit_cost' => $cashOut->inventoryPurchase?->unit_cost,
        ];

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'cash_out',
            'entity_id' => $cashOut->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah pengeluaran ID %s pada %s',
                $this->currentUser()->name,
                $cashOut->id,
                now()->format('d-m-Y H:i')
            ),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.expenses.index')
            ->with('success', 'Pengeluaran berhasil diperbarui.');
    }

    public function expensesDestroy(Request $request, CashOut $cashOut)
    {
        if ($this->isPeriodClosed($cashOut->expense_date->toDateString())) {
            return redirect()
                ->route('accountingapp.expenses.index')
                ->with('error', 'Periode ini sudah ditutup. Hapus transaksi tidak diizinkan, lakukan koreksi dengan adjustment.');
        }

        $cashOut->load(['category', 'cashAccount', 'payable', 'inventoryPurchase.item']);

        $before = [
            'id' => $cashOut->id,
            'category' => $cashOut->category->name ?? null,
            'cash_account' => $cashOut->cashAccount->name ?? null,
            'amount' => $cashOut->amount,
            'expense_date' => $cashOut->expense_date?->toDateString(),
            'description' => $cashOut->description,
            'is_adjustment' => $cashOut->is_adjustment,
            'adjustment_note' => $cashOut->adjustment_note,
            'adjusted_by' => $cashOut->adjusted_by,
            'payable' => $cashOut->payable?->supplier_name,
            'inventory_item' => $cashOut->inventoryPurchase?->item?->name,
            'inventory_qty' => $cashOut->inventoryPurchase?->qty,
            'inventory_unit_cost' => $cashOut->inventoryPurchase?->unit_cost,
        ];

        $cashOutId = $cashOut->id;
        $oldPayableId = $cashOut->payable_id;

        DB::transaction(function () use ($cashOut, $oldPayableId) {
            $this->syncPayableSettlementForCashOut($cashOut, $oldPayableId, null);
            $cashOut->inventoryPurchase()?->delete();
            $cashOut->delete();
        });

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'cash_out',
            'entity_id' => $cashOutId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus pengeluaran ID %s pada %s',
                $this->currentUser()->name,
                $cashOutId,
                now()->format('d-m-Y H:i')
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.expenses.index')
            ->with('success', 'Pengeluaran berhasil dihapus.');
    }

    public function otherIncomesIndex(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $rangePeriodStatus = $this->periodStatusForRange($dateFrom, $dateTo);

        $query = OtherIncome::with(['category', 'cashAccount', 'creator', 'updater'])
            ->whereBetween('income_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]);

        $categoryId = $request->input('income_category_id');
        if ($categoryId) {
            $query->where('income_category_id', $categoryId);
        }

        $cashAccountId = $request->input('cash_account_id');
        if ($cashAccountId) {
            $query->where('cash_account_id', $cashAccountId);
        }

        $totalOtherIncome = (clone $query)->sum('amount');
        $otherIncomeCount = (clone $query)->count();

        $otherIncomes = $query->orderByDesc('income_date')
            ->paginate(20)
            ->withQueryString();
        $otherIncomes->getCollection()->transform(function (OtherIncome $otherIncome) {
            $otherIncome->setAttribute('period_closed', $this->isPeriodClosed($otherIncome->income_date->toDateString()));

            return $otherIncome;
        });

        $incomeCategories = IncomeCategory::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();

        $cashAccounts = CashAccount::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();

        return view('accountingapp.other-incomes.index', compact(
            'otherIncomes',
            'incomeCategories',
            'cashAccounts',
            'dateFrom',
            'dateTo',
            'categoryId',
            'cashAccountId',
            'totalOtherIncome',
            'otherIncomeCount',
            'rangePeriodStatus'
        ));
    }

    public function salesClosingsIndex(Request $request, SalesActualService $salesActualService)
    {
        try {
            $closingDate = $request->input('closing_date')
                ? Carbon::parse($request->input('closing_date'))->startOfDay()
                : now()->startOfDay();
        } catch (\Throwable) {
            $closingDate = now()->startOfDay();
        }

        $preview = $salesActualService->dailyClosingPreview($closingDate);
        $existingClosing = SalesDailyClosing::query()
            ->with('poster:id,name')
            ->whereDate('closing_date', $closingDate->toDateString())
            ->first();
        $recentClosings = SalesDailyClosing::query()
            ->with('poster:id,name')
            ->orderByDesc('closing_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();
        $isClosedPeriod = $this->isPeriodClosed($closingDate->toDateString());
        $closedPeriodLabel = $this->periodLabel((int) $closingDate->month, (int) $closingDate->year);

        return view('accountingapp.sales-closings.index', compact(
            'closingDate',
            'preview',
            'existingClosing',
            'recentClosings',
            'isClosedPeriod',
            'closedPeriodLabel'
        ));
    }

    public function salesClosingsStore(Request $request, SalesActualService $salesActualService)
    {
        $data = $request->validate([
            'closing_date' => ['required', 'date'],
            'cash_in_date' => ['required', 'date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($this->isPeriodClosed($data['closing_date'])) {
            return back()
                ->withErrors(['closing_date' => 'Periode tanggal penjualan sudah ditutup. Buka periode dulu atau buat adjustment yang sesuai.'])
                ->withInput();
        }

        if ($this->isPeriodClosed($data['cash_in_date'])) {
            return back()
                ->withErrors(['cash_in_date' => 'Periode tanggal closing sudah ditutup. Pilih tanggal lain atau buka periode dulu.'])
                ->withInput();
        }

        $salesActualService->postDailyClosing(
            Carbon::parse($data['closing_date']),
            Carbon::parse($data['cash_in_date']),
            (float) ($data['discount_amount'] ?? 0),
            $data['notes'] ?? null,
            $request->ip()
        );

        return redirect()
            ->route('accountingapp.sales-closings.index', ['closing_date' => $data['closing_date']])
            ->with('success', 'Closing penjualan harian berhasil diposting ke cash in Accounting.');
    }

    public function otherIncomesStore(Request $request)
    {
        $data = $request->validate([
            'income_date' => ['required', 'date'],
            'income_category_id' => ['required', 'exists:income_categories,id'],
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string'],
            'adjustment_note' => ['nullable', 'string'],
        ]);

        $isClosedPeriod = $this->isPeriodClosed($data['income_date']);

        if ($isClosedPeriod) {
            if (! $this->currentUser()->hasRole('owner')) {
                return back()->withErrors([
                    'income_date' => 'Periode ini sudah ditutup. Hanya owner yang dapat melakukan koreksi.',
                ])->withInput();
            }

            if (blank($request->input('adjustment_note'))) {
                return back()->withErrors([
                    'adjustment_note' => 'Catatan koreksi wajib diisi untuk periode yang sudah ditutup.',
                ])->withInput();
            }
        }

        $otherIncome = OtherIncome::create([
            'income_date' => $data['income_date'],
            'income_category_id' => $data['income_category_id'],
            'cash_account_id' => $data['cash_account_id'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'is_adjustment' => $isClosedPeriod,
            'adjustment_note' => $request->input('adjustment_note'),
            'adjusted_by' => $isClosedPeriod ? $this->currentUserId() : null,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);

        $otherIncome->load(['category', 'cashAccount']);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'other_income',
            'entity_id' => $otherIncome->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan pemasukan %s sebesar Rp %s',
                $this->currentUser()->name,
                $otherIncome->category->name ?? '-',
                number_format((float) $otherIncome->amount, 0, ',', '.')
            ),
            'before_json' => null,
            'after_json' => [
                'income_date' => $otherIncome->income_date?->toDateString(),
                'category' => $otherIncome->category->name ?? null,
                'cash_account' => $otherIncome->cashAccount->name ?? null,
                'amount' => $otherIncome->amount,
                'description' => $otherIncome->description,
                'is_adjustment' => $otherIncome->is_adjustment,
                'adjustment_note' => $otherIncome->adjustment_note,
                'adjusted_by' => $otherIncome->adjusted_by,
            ],
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.other-incomes.index')
            ->with('success', 'Pemasukan berhasil ditambahkan.');
    }

    public function otherIncomesEdit(OtherIncome $otherIncome)
    {
        $incomeCategories = IncomeCategory::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();

        $cashAccounts = CashAccount::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();
        $isClosedPeriod = $this->isPeriodClosed($otherIncome->income_date->toDateString());
        $closedPeriodLabel = $this->periodLabel((int) $otherIncome->income_date->month, (int) $otherIncome->income_date->year);

        return view('accountingapp.other-incomes.edit', compact(
            'otherIncome',
            'incomeCategories',
            'cashAccounts',
            'isClosedPeriod',
            'closedPeriodLabel'
        ));
    }

    public function otherIncomesUpdate(Request $request, OtherIncome $otherIncome)
    {
        $data = $request->validate([
            'income_date' => ['required', 'date'],
            'income_category_id' => ['required', 'exists:income_categories,id'],
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string'],
            'adjustment_note' => ['nullable', 'string'],
        ]);

        $isClosedPeriod = $this->isPeriodClosed($data['income_date']);

        if ($isClosedPeriod) {
            if (! $this->currentUser()->hasRole('owner')) {
                return back()->withErrors([
                    'income_date' => 'Periode ini sudah ditutup. Hanya owner yang dapat melakukan koreksi.',
                ])->withInput();
            }

            if (blank($request->input('adjustment_note'))) {
                return back()->withErrors([
                    'adjustment_note' => 'Catatan koreksi wajib diisi untuk periode yang sudah ditutup.',
                ])->withInput();
            }
        }

        $otherIncome->load(['category', 'cashAccount']);

        $before = [
            'income_date' => $otherIncome->income_date?->toDateString(),
            'category' => $otherIncome->category->name ?? null,
            'cash_account' => $otherIncome->cashAccount->name ?? null,
            'amount' => $otherIncome->amount,
            'description' => $otherIncome->description,
            'is_adjustment' => $otherIncome->is_adjustment,
            'adjustment_note' => $otherIncome->adjustment_note,
            'adjusted_by' => $otherIncome->adjusted_by,
        ];

        $otherIncome->update([
            'income_date' => $data['income_date'],
            'income_category_id' => $data['income_category_id'],
            'cash_account_id' => $data['cash_account_id'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'is_adjustment' => $isClosedPeriod,
            'adjustment_note' => $request->input('adjustment_note'),
            'adjusted_by' => $isClosedPeriod ? $this->currentUserId() : null,
            'updated_by' => $this->currentUserId(),
        ]);

        $otherIncome->refresh()->load(['category', 'cashAccount']);

        $after = [
            'income_date' => $otherIncome->income_date?->toDateString(),
            'category' => $otherIncome->category->name ?? null,
            'cash_account' => $otherIncome->cashAccount->name ?? null,
            'amount' => $otherIncome->amount,
            'description' => $otherIncome->description,
            'is_adjustment' => $otherIncome->is_adjustment,
            'adjustment_note' => $otherIncome->adjustment_note,
            'adjusted_by' => $otherIncome->adjusted_by,
        ];

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'other_income',
            'entity_id' => $otherIncome->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah pemasukan ID %s',
                $this->currentUser()->name,
                $otherIncome->id
            ),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.other-incomes.index')
            ->with('success', 'Pemasukan berhasil diperbarui.');
    }

    public function otherIncomesDestroy(Request $request, OtherIncome $otherIncome)
    {
        if ($this->isPeriodClosed($otherIncome->income_date->toDateString())) {
            return redirect()
                ->route('accountingapp.other-incomes.index')
                ->with('error', 'Periode ini sudah ditutup. Hapus transaksi tidak diizinkan, lakukan koreksi dengan adjustment.');
        }

        $otherIncome->load(['category', 'cashAccount']);

        $before = [
            'income_date' => $otherIncome->income_date?->toDateString(),
            'category' => $otherIncome->category->name ?? null,
            'cash_account' => $otherIncome->cashAccount->name ?? null,
            'amount' => $otherIncome->amount,
            'description' => $otherIncome->description,
        ];

        $otherIncomeId = $otherIncome->id;
        $otherIncome->delete();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'other_income',
            'entity_id' => $otherIncomeId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus pemasukan ID %s pada %s',
                $this->currentUser()->name,
                $otherIncomeId,
                now()->format('d-m-Y H:i')
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.other-incomes.index')
            ->with('success', 'Pemasukan berhasil dihapus.');
    }

    public function openingBalancesIndex(Request $request)
    {
        $openingBalances = OpeningBalance::query()
            ->with(['creator', 'updater', 'cashAccount', 'customer', 'payable'])
            ->orderByDesc('balance_date')
            ->orderByDesc('id')
            ->paginate(20);

        $customers = Customer::query()
            ->where('active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();

        $cashAccounts = CashAccount::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get();

        return view('accountingapp.opening-balances.index', compact(
            'openingBalances',
            'customers',
            'cashAccounts'
        ));
    }

    public function openingBalancesStore(Request $request)
    {
        $data = $this->validateOpeningBalanceData($request);

        $openingBalance = DB::transaction(function () use ($data) {
            $openingBalance = OpeningBalance::create([
                'balance_date' => $data['balance_date'],
                'type' => $data['type'],
                'reference_id' => $data['reference_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'created_by' => $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]);

            $this->syncPayableForOpeningBalance($openingBalance);

            return $openingBalance->fresh()->load(['cashAccount', 'customer', 'payable']);
        });

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'opening_balance',
            'entity_id' => $openingBalance->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan saldo awal %s sebesar Rp %s',
                $this->currentUser()->name,
                $this->openingBalanceTypeLabel($openingBalance->type),
                number_format((float) $openingBalance->amount, 0, ',', '.')
            ),
            'before_json' => null,
            'after_json' => $this->openingBalanceAuditSnapshot($openingBalance),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.opening-balances.index')
            ->with('success', 'Saldo awal berhasil ditambahkan.');
    }

    public function balanceSheetAdjustmentsIndex(Request $request)
    {
        $this->ensureBalanceSheetAdjustmentAccess();

        $selectedGroup = $request->input('group');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $adjustments = BalanceSheetAdjustment::query()
            ->with(['creator', 'updater'])
            ->when(in_array($selectedGroup, BalanceSheetAdjustment::GROUPS, true), function ($query) use ($selectedGroup) {
                $query->where('account_group', $selectedGroup);
            })
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('adjustment_date', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('adjustment_date', '<=', $dateTo);
            })
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $groupOptions = $this->balanceSheetAdjustmentGroupOptions();

        return view('accountingapp.balance-sheet-adjustments.index', compact(
            'adjustments',
            'groupOptions',
            'selectedGroup',
            'dateFrom',
            'dateTo'
        ));
    }

    public function balanceSheetAdjustmentsStore(Request $request)
    {
        $this->ensureBalanceSheetAdjustmentAccess();

        $data = $request->validate([
            'adjustment_date' => ['required', 'date'],
            'group' => ['required', Rule::in(BalanceSheetAdjustment::GROUPS)],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $adjustment = BalanceSheetAdjustment::query()->create([
            'adjustment_date' => $data['adjustment_date'],
            'account_group' => $data['group'],
            'label' => $data['label'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'balance_sheet_adjustment',
            'entity_id' => $adjustment->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan adjustment neraca %s sebesar Rp %s',
                $this->currentUser()->name,
                $this->balanceSheetAdjustmentGroupLabel($adjustment->account_group),
                number_format((float) $adjustment->amount, 0, ',', '.')
            ),
            'before_json' => null,
            'after_json' => $this->balanceSheetAdjustmentAuditSnapshot($adjustment),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.balance-sheet-adjustments.index')
            ->with('success', 'Adjustment neraca berhasil ditambahkan.');
    }

    public function balanceSheetAdjustmentsEdit(BalanceSheetAdjustment $balanceSheetAdjustment)
    {
        $this->ensureBalanceSheetAdjustmentAccess();

        $groupOptions = $this->balanceSheetAdjustmentGroupOptions();

        return view('accountingapp.balance-sheet-adjustments.edit', compact(
            'balanceSheetAdjustment',
            'groupOptions'
        ));
    }

    public function balanceSheetAdjustmentsUpdate(Request $request, BalanceSheetAdjustment $balanceSheetAdjustment)
    {
        $this->ensureBalanceSheetAdjustmentAccess();

        $data = $request->validate([
            'adjustment_date' => ['required', 'date'],
            'group' => ['required', Rule::in(BalanceSheetAdjustment::GROUPS)],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $before = $this->balanceSheetAdjustmentAuditSnapshot($balanceSheetAdjustment);

        $balanceSheetAdjustment->update([
            'adjustment_date' => $data['adjustment_date'],
            'account_group' => $data['group'],
            'label' => $data['label'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'updated_by' => $this->currentUserId(),
        ]);

        $balanceSheetAdjustment->refresh();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'balance_sheet_adjustment',
            'entity_id' => $balanceSheetAdjustment->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah adjustment neraca ID %s',
                $this->currentUser()->name,
                $balanceSheetAdjustment->id
            ),
            'before_json' => $before,
            'after_json' => $this->balanceSheetAdjustmentAuditSnapshot($balanceSheetAdjustment),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.balance-sheet-adjustments.index')
            ->with('success', 'Adjustment neraca berhasil diperbarui.');
    }

    public function balanceSheetAdjustmentsDestroy(Request $request, BalanceSheetAdjustment $balanceSheetAdjustment)
    {
        $this->ensureBalanceSheetAdjustmentAccess();

        $before = $this->balanceSheetAdjustmentAuditSnapshot($balanceSheetAdjustment);
        $adjustmentId = $balanceSheetAdjustment->id;
        $balanceSheetAdjustment->delete();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'balance_sheet_adjustment',
            'entity_id' => $adjustmentId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus adjustment neraca ID %s',
                $this->currentUser()->name,
                $adjustmentId
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.balance-sheet-adjustments.index')
            ->with('success', 'Adjustment neraca berhasil dihapus.');
    }

    public function profitLossAdjustmentsIndex(Request $request)
    {
        $this->ensureProfitLossAdjustmentAccess();

        $selectedGroup = $request->input('group');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $adjustments = ProfitLossAdjustment::query()
            ->with(['creator', 'updater', 'expenseCategory:id,name'])
            ->when(in_array($selectedGroup, ProfitLossAdjustment::GROUPS, true), function ($query) use ($selectedGroup) {
                $query->where('statement_group', $selectedGroup);
            })
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('adjustment_date', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('adjustment_date', '<=', $dateTo);
            })
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $groupOptions = $this->profitLossAdjustmentGroupOptions();
        $expenseCategories = $this->profitLossAdjustmentExpenseCategories();
        $maxAdjustmentDate = now()->startOfMonth()->subDay()->toDateString();

        return view('accountingapp.profit-loss-adjustments.index', compact(
            'adjustments',
            'groupOptions',
            'expenseCategories',
            'selectedGroup',
            'dateFrom',
            'dateTo',
            'maxAdjustmentDate'
        ));
    }

    public function profitLossAdjustmentsStore(Request $request)
    {
        $this->ensureProfitLossAdjustmentAccess();

        $historicalCutoff = now()->startOfMonth()->toDateString();
        $data = $request->validate([
            'adjustment_date' => ['required', 'date', 'before:' . $historicalCutoff],
            'group' => ['required', Rule::in(ProfitLossAdjustment::GROUPS)],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ], [
            'adjustment_date.before' => 'Adjustment laba rugi hanya untuk bulan historis sebelum bulan berjalan.',
        ]);

        $expenseCategoryId = $data['group'] === ProfitLossAdjustment::GROUP_OPERATING_EXPENSE
            ? ($data['expense_category_id'] ?? null)
            : null;

        $adjustment = ProfitLossAdjustment::query()->create([
            'adjustment_date' => $data['adjustment_date'],
            'statement_group' => $data['group'],
            'expense_category_id' => $expenseCategoryId,
            'label' => $data['label'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'profit_loss_adjustment',
            'entity_id' => $adjustment->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan adjustment laba rugi %s sebesar Rp %s',
                $this->currentUser()->name,
                $this->profitLossAdjustmentGroupLabel($adjustment->statement_group),
                number_format((float) $adjustment->amount, 0, ',', '.')
            ),
            'before_json' => null,
            'after_json' => $this->profitLossAdjustmentAuditSnapshot($adjustment),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.profit-loss-adjustments.index')
            ->with('success', 'Adjustment laba rugi berhasil ditambahkan.');
    }

    public function profitLossAdjustmentsEdit(ProfitLossAdjustment $profitLossAdjustment)
    {
        $this->ensureProfitLossAdjustmentAccess();

        $groupOptions = $this->profitLossAdjustmentGroupOptions();
        $expenseCategories = $this->profitLossAdjustmentExpenseCategories();
        $maxAdjustmentDate = now()->startOfMonth()->subDay()->toDateString();

        return view('accountingapp.profit-loss-adjustments.edit', compact(
            'profitLossAdjustment',
            'groupOptions',
            'expenseCategories',
            'maxAdjustmentDate'
        ));
    }

    public function profitLossAdjustmentsUpdate(Request $request, ProfitLossAdjustment $profitLossAdjustment)
    {
        $this->ensureProfitLossAdjustmentAccess();

        $historicalCutoff = now()->startOfMonth()->toDateString();
        $data = $request->validate([
            'adjustment_date' => ['required', 'date', 'before:' . $historicalCutoff],
            'group' => ['required', Rule::in(ProfitLossAdjustment::GROUPS)],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ], [
            'adjustment_date.before' => 'Adjustment laba rugi hanya untuk bulan historis sebelum bulan berjalan.',
        ]);

        $before = $this->profitLossAdjustmentAuditSnapshot($profitLossAdjustment);

        $expenseCategoryId = $data['group'] === ProfitLossAdjustment::GROUP_OPERATING_EXPENSE
            ? ($data['expense_category_id'] ?? null)
            : null;

        $profitLossAdjustment->update([
            'adjustment_date' => $data['adjustment_date'],
            'statement_group' => $data['group'],
            'expense_category_id' => $expenseCategoryId,
            'label' => $data['label'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'updated_by' => $this->currentUserId(),
        ]);

        $profitLossAdjustment->refresh();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'profit_loss_adjustment',
            'entity_id' => $profitLossAdjustment->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah adjustment laba rugi ID %s',
                $this->currentUser()->name,
                $profitLossAdjustment->id
            ),
            'before_json' => $before,
            'after_json' => $this->profitLossAdjustmentAuditSnapshot($profitLossAdjustment),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.profit-loss-adjustments.index')
            ->with('success', 'Adjustment laba rugi berhasil diperbarui.');
    }

    public function profitLossAdjustmentsDestroy(Request $request, ProfitLossAdjustment $profitLossAdjustment)
    {
        $this->ensureProfitLossAdjustmentAccess();

        $before = $this->profitLossAdjustmentAuditSnapshot($profitLossAdjustment);
        $adjustmentId = $profitLossAdjustment->id;
        $profitLossAdjustment->delete();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'profit_loss_adjustment',
            'entity_id' => $adjustmentId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus adjustment laba rugi ID %s',
                $this->currentUser()->name,
                $adjustmentId
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.profit-loss-adjustments.index')
            ->with('success', 'Adjustment laba rugi berhasil dihapus.');
    }

    public function openingBalancesEdit(OpeningBalance $openingBalance)
    {
        $this->ensureOpeningBalanceEditAccess();
        $openingBalance->loadMissing('payable');

        $customers = Customer::query()
            ->where(function ($query) use ($openingBalance) {
                $query->where('active', '=', true, 'and');

                if ($openingBalance->type === 'receivable' && $openingBalance->reference_id) {
                    $query->orWhere('id', '=', $openingBalance->reference_id);
                }
            })
            ->orderBy('name', 'asc')
            ->get();

        $cashAccounts = CashAccount::query()
            ->where(function ($query) use ($openingBalance) {
                $query->where('is_active', '=', true, 'and');

                if ($openingBalance->type === 'cash' && $openingBalance->reference_id) {
                    $query->orWhere('id', '=', $openingBalance->reference_id);
                }
            })
            ->orderBy('name', 'asc')
            ->get();

        return view('accountingapp.opening-balances.edit', compact(
            'openingBalance',
            'customers',
            'cashAccounts'
        ));
    }

    public function openingBalancesUpdate(Request $request, OpeningBalance $openingBalance)
    {
        $this->ensureOpeningBalanceEditAccess();

        $data = $this->validateOpeningBalanceData($request);

        $openingBalance->load(['cashAccount', 'customer', 'payable.cashOuts']);
        $this->ensureOpeningBalancePayableCanBeUpdated($openingBalance, $data);
        $before = $this->openingBalanceAuditSnapshot($openingBalance);

        DB::transaction(function () use ($openingBalance, $data) {
            $openingBalance->update([
                'balance_date' => $data['balance_date'],
                'type' => $data['type'],
                'reference_id' => $data['reference_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'updated_by' => $this->currentUserId(),
            ]);

            $openingBalance->refresh()->load('payable.cashOuts');
            $this->syncPayableForOpeningBalance($openingBalance);
        });

        $openingBalance->refresh()->load(['cashAccount', 'customer', 'payable']);
        $after = $this->openingBalanceAuditSnapshot($openingBalance);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'opening_balance',
            'entity_id' => $openingBalance->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah saldo awal ID %s',
                $this->currentUser()->name,
                $openingBalance->id
            ),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.opening-balances.index')
            ->with('success', 'Saldo awal berhasil diperbarui.');
    }

    public function cashAccountsIndex()
    {
        $cashAccounts = CashAccount::query()
            ->orderByDesc('is_active')
            ->orderBy('name', 'asc')
            ->paginate(20);

        return view('accountingapp.cash_accounts.index', compact('cashAccounts'));
    }

    public function cashAccountsStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:cash_accounts,name'],
            'type' => ['required', 'in:cash,bank'],
            'description' => ['nullable', 'string'],
        ]);

        CashAccount::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('accountingapp.cashaccounts.index')
            ->with('success', 'Cash account berhasil ditambahkan.');
    }

    public function cashAccountsUpdate(Request $request, CashAccount $cashAccount)
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('cash_accounts', 'name')->ignore($cashAccount->id),
            ],
            'type' => ['required', 'in:cash,bank'],
            'description' => ['nullable', 'string'],
        ]);

        $cashAccount->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('accountingapp.cashaccounts.index')
            ->with('success', 'Cash account berhasil diperbarui.');
    }

    public function cashAccountsDestroy(CashAccount $cashAccount)
    {
        if (
            $cashAccount->cashOuts()->exists()
            || $cashAccount->purchaseOrders()->exists()
            || $cashAccount->otherIncomes()->exists()
            || $cashAccount->openingBalances()->exists()
        ) {
            return redirect()
                ->route('accountingapp.cashaccounts.index')
                ->with('error', 'Cash account tidak bisa dihapus karena sudah dipakai transaksi.');
        }

        $cashAccount->delete();

        return redirect()
            ->route('accountingapp.cashaccounts.index')
            ->with('success', 'Cash account berhasil dihapus.');
    }

    public function categoriesIndex()
    {
        $categories = ExpenseCategory::query()
            ->whereRaw('LOWER(name) <> ?', [strtolower(self::PAYABLE_SETTLEMENT_CATEGORY_NAME)])
            ->orderByDesc('is_active')
            ->orderBy('name', 'asc')
            ->paginate(20);

        return view('accountingapp.master-categories.index', compact('categories'));
    }

    public function categoriesStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:expense_categories,name'],
            'description' => ['nullable', 'string'],
            'expense_mode' => ['required', Rule::in([
                ExpenseCategory::MODE_DIRECT_EXPENSE,
                ExpenseCategory::MODE_INVENTORY_PURCHASE,
                ExpenseCategory::MODE_FIXED_ASSET,
            ])],
        ]);

        if ($this->isReservedExpenseCategoryName($data['name'])) {
            return back()
                ->withErrors([
                    'name' => 'Kategori ini dipakai sistem dan tidak bisa dibuat manual.',
                ])
                ->withInput();
        }

        ExpenseCategory::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'expense_mode' => $data['expense_mode'],
            'include_hpp' => $request->boolean('include_hpp'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('accountingapp.categories.index')
            ->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function categoriesUpdate(Request $request, ExpenseCategory $category)
    {
        if ($this->isProtectedExpenseCategory($category)) {
            return redirect()
                ->route('accountingapp.categories.index')
                ->with('error', 'Kategori sistem tidak bisa diubah.');
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('expense_categories', 'name')->ignore($category->id),
            ],
            'description' => ['nullable', 'string'],
            'expense_mode' => ['required', Rule::in([
                ExpenseCategory::MODE_DIRECT_EXPENSE,
                ExpenseCategory::MODE_INVENTORY_PURCHASE,
                ExpenseCategory::MODE_FIXED_ASSET,
            ])],
        ]);

        if ($this->isReservedExpenseCategoryName($data['name'])) {
            return back()
                ->withErrors([
                    'name' => 'Kategori ini dipakai sistem dan tidak bisa digunakan.',
                ])
                ->withInput();
        }

        $category->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'expense_mode' => $data['expense_mode'],
            'include_hpp' => $request->boolean('include_hpp'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('accountingapp.categories.index')
            ->with('success', 'Kategori berhasil diperbarui.');
    }

    public function categoriesDestroy(ExpenseCategory $category)
    {
        if ($this->isProtectedExpenseCategory($category)) {
            return redirect()
                ->route('accountingapp.categories.index')
                ->with('error', 'Kategori sistem tidak bisa dihapus.');
        }

        if ($category->cashOuts()->exists()) {
            return redirect()
                ->route('accountingapp.categories.index')
                ->with('error', 'Kategori tidak bisa dihapus karena sudah dipakai di data pengeluaran.');
        }

        $category->delete();

        return redirect()
            ->route('accountingapp.categories.index')
            ->with('success', 'Kategori berhasil dihapus.');
    }

    public function periodClosingsIndex(Request $request)
    {
        $selectedYear = (int) $request->input('year', now()->year);
        $selectedMonth = $request->filled('month') ? (int) $request->input('month') : null;
        $yearOptions = PeriodClosing::query()
            ->select('period_year')
            ->distinct()
            ->orderByDesc('period_year')
            ->pluck('period_year')
            ->all();

        if (! in_array($selectedYear, $yearOptions, true)) {
            $yearOptions[] = $selectedYear;
            rsort($yearOptions);
            $yearOptions = array_values(array_unique($yearOptions));
        }

        $periodClosings = PeriodClosing::query()
            ->with('closer:id,name')
            ->when($selectedYear, fn ($query) => $query->where('period_year', $selectedYear))
            ->when($selectedMonth, fn ($query) => $query->where('period_month', $selectedMonth))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate(20)
            ->withQueryString();

        $closedPeriodsByYear = $this->closedPeriodsByYearMap();
        $previousOpenPeriodWarning = $this->previousOpenPeriodWarning();

        return view('accountingapp.period-closings.index', compact(
            'periodClosings',
            'selectedYear',
            'yearOptions',
            'closedPeriodsByYear',
            'selectedMonth',
            'previousOpenPeriodWarning'
        ));
    }

    public function periodClosingsStore(Request $request)
    {
        $this->ensurePeriodClosingAccess();

        $data = $request->validate([
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'notes' => ['nullable', 'string'],
        ]);

        $exists = PeriodClosing::query()
            ->where('period_month', $data['period_month'])
            ->where('period_year', $data['period_year'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'period_month' => 'Periode bulan dan tahun ini sudah ditutup.',
            ])->withInput();
        }

        $periodClosing = PeriodClosing::create([
            'period_month' => $data['period_month'],
            'period_year' => $data['period_year'],
            'closed_at' => now(),
            'closed_by' => $this->currentUserId(),
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'period-closing',
            'entity_id' => $periodClosing->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'Periode %s %s ditutup oleh %s pada %s',
                $this->monthLabel((int) $periodClosing->period_month),
                $periodClosing->period_year,
                $this->currentUser()->name,
                optional($periodClosing->closed_at)->format('d-m-Y H:i')
            ),
            'before_json' => null,
            'after_json' => [
                'period_month' => $periodClosing->period_month,
                'period_year' => $periodClosing->period_year,
                'closed_at' => optional($periodClosing->closed_at)->toDateTimeString(),
                'closed_by' => $periodClosing->closed_by,
                'notes' => $periodClosing->notes,
            ],
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.period-closings.index', ['year' => $periodClosing->period_year])
            ->with('success', 'Periode berhasil ditutup.');
    }

    public function periodClosingsDestroy(Request $request, PeriodClosing $periodClosing)
    {
        $this->ensurePeriodClosingAccess();

        $before = [
            'period_month' => $periodClosing->period_month,
            'period_year' => $periodClosing->period_year,
            'closed_at' => optional($periodClosing->closed_at)->toDateTimeString(),
            'closed_by' => $periodClosing->closed_by,
            'notes' => $periodClosing->notes,
        ];

        $periodClosingId = $periodClosing->id;
        $year = $periodClosing->period_year;
        $periodLabel = $this->monthLabel((int) $periodClosing->period_month) . ' ' . $periodClosing->period_year;

        $periodClosing->delete();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'period-closing',
            'entity_id' => $periodClosingId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'Penutupan periode %s dibuka kembali oleh %s pada %s',
                $periodLabel,
                $this->currentUser()->name,
                now()->format('d-m-Y H:i')
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.period-closings.index', ['year' => $year])
            ->with('success', 'Periode berhasil dibuka kembali.');
    }

    public function periodsIndex(Request $request)
    {
        $viewMode = $request->input('view') === 'paid' ? 'paid' : 'outstanding';

        $cashAccounts = CashAccount::query()
            ->where('is_active', '=', true, 'and')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'type']);

        if ($viewMode === 'paid') {
            $reportData = $this->buildPaidReceivablesData($request, true);
            $reportData['viewMode'] = 'paid';
            $reportData['cashAccounts'] = $cashAccounts;

            return view('accountingapp.periods.index', $reportData);
        }

        $reportData = $this->buildReceivablesMonitoringData($request, true);
        $reportData['viewMode'] = 'outstanding';

        return view('accountingapp.periods.index', array_merge($reportData, compact('cashAccounts')));
    }

    public function exportReceivablesExcel(Request $request)
    {
        if ($request->input('view') === 'paid') {
            $reportData = $this->buildPaidReceivablesData($request, false);
            $reportData['viewMode'] = 'paid';
            $fileName = 'piutang_terlunasi_' . now()->format('Ymd_His') . '.xlsx';

            return Excel::download(
                new ViewExcelExport('accountingapp.periods.exports.index', $reportData),
                $fileName
            );
        }

        $reportData = $this->buildReceivablesMonitoringData($request, false);
        $reportData['viewMode'] = 'outstanding';
        $fileName = 'monitoring_piutang_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.periods.exports.index', $reportData),
            $fileName
        );
    }

    public function exportReceivablesPdf(Request $request)
    {
        if ($request->input('view') === 'paid') {
            $reportData = $this->buildPaidReceivablesData($request, false);
            $reportData['viewMode'] = 'paid';
            $fileName = 'piutang_terlunasi_' . now()->format('Ymd_His') . '.pdf';

            return Pdf::loadView('accountingapp.periods.exports.index', $reportData)
                ->setPaper('a4', 'landscape')
                ->download($fileName);
        }

        $reportData = $this->buildReceivablesMonitoringData($request, false);
        $reportData['viewMode'] = 'outstanding';
        $fileName = 'monitoring_piutang_' . now()->format('Ymd_His') . '.pdf';

        return Pdf::loadView('accountingapp.periods.exports.index', $reportData)
            ->setPaper('a4', 'landscape')
            ->download($fileName);
    }

    protected function buildPaidReceivablesData(Request $request, bool $paginate): array
    {
        $today = Carbon::today();
        $dateFrom = trim((string) $request->input('date_from', ''));
        $dateTo = trim((string) $request->input('date_to', ''));
        $cashAccountId = $request->input('cash_account_id');
        $customerSearch = trim((string) $request->input('customer', ''));

        $baseQuery = PurchaseOrder::query()
            ->with(['customer:id,name', 'items.product:id,name', 'cashAccount:id,name,type'])
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at')
            ->where(function ($query) {
                $query->where('receivable_status', 'paid')
                    ->orWhereNull('receivable_status');
            });

        $filteredQuery = (clone $baseQuery)
            ->when($dateFrom !== '', function ($query) use ($dateFrom) {
                $query->whereDate('cash_received_at', '>=', $dateFrom);
            })
            ->when($dateTo !== '', function ($query) use ($dateTo) {
                $query->whereDate('cash_received_at', '<=', $dateTo);
            })
            ->when(is_numeric($cashAccountId), function ($query) use ($cashAccountId) {
                $query->where('cash_account_id', (int) $cashAccountId);
            })
            ->when($customerSearch !== '', function ($query) use ($customerSearch) {
                $query->whereHas('customer', function ($q) use ($customerSearch) {
                    $q->where('name', 'like', '%' . $customerSearch . '%');
                });
            });

        $orderedQuery = (clone $filteredQuery)->orderByDesc('cash_received_at');

        $periods = $paginate
            ? $orderedQuery->paginate(20)->withQueryString()
            : $orderedQuery->get();

        $rows = $paginate ? $periods->getCollection() : $periods;
        $rows->transform(function (PurchaseOrder $po) {
            $itemsSummary = $po->items
                ->map(function ($item) {
                    $productName = $item->product->name ?? 'Produk';
                    return $productName . ' x' . (int) $item->qty;
                })
                ->implode(', ');
            $po->setAttribute('items_summary', $itemsSummary);
            return $po;
        });

        $totalPaid = (clone $filteredQuery)->sum('total_amount');
        $countPaid = (clone $filteredQuery)->count();

        return [
            'periods' => $periods,
            'today' => $today,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'cashAccountId' => $cashAccountId,
            'customerSearch' => $customerSearch,
            'totalPaid' => (float) $totalPaid,
            'countPaid' => (int) $countPaid,
            'exportedAt' => now(),
        ];
    }

    public function salesReport(Request $request, SalesReportService $salesReportService)
    {
        $reportData = $salesReportService->buildReportData($request);
        $reportData['useSectionLayout'] = true;

        return view('reports.sales', array_merge($reportData, [
            'pageLayout' => $reportData['viewMode'] === 'full' ? 'layouts.accounting-report' : 'layouts.accountingapp',
            'reportRouteName' => 'accountingapp.reports.sales',
            'reportExcelRouteName' => 'accountingapp.reports.sales.export.excel',
            'reportPdfRouteName' => 'accountingapp.reports.sales.export.pdf',
            'dashboardRouteName' => 'accountingapp.dashboard',
            'dashboardLabel' => 'Dashboard Accounting',
        ]));
    }

    public function exportSalesExcel(Request $request, SalesReportService $salesReportService)
    {
        $reportData = $salesReportService->buildReportData($request);
        $reportData['useSectionLayout'] = true;
        $fileName = 'laporan_penjualan_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('reports.exports.sales', $reportData),
            $fileName
        );
    }

    public function exportSalesPdf(Request $request, SalesReportService $salesReportService)
    {
        $reportData = $salesReportService->buildReportData($request);
        $reportData['useSectionLayout'] = true;
        $fileName = 'laporan_penjualan_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.pdf';

        $paper = $salesReportService->pdfPaperSize($reportData, true);
        $orientation = is_array($paper) ? 'portrait' : 'landscape';

        return Pdf::loadView('reports.exports.sales', $reportData)
            ->setPaper($paper, $orientation)
            ->download($fileName);
    }

    public function periodsComplete(Request $request, PurchaseOrder $po)
    {
        if ($po->status !== 'completed' || $po->payment_type !== 'receivable') {
            return redirect()
                ->route('accountingapp.periods.index')
                ->with('error', 'PO ini bukan piutang ' . UiLabel::purchaseOrderStatus('completed') . '.');
        }

        if ($po->cash_received_at) {
            return redirect()
                ->route('accountingapp.periods.index')
                ->with('warning', 'Piutang sudah ditandai ' . UiLabel::purchaseOrderStatus('completed') . ' sebelumnya.');
        }

        $data = $request->validate([
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'cash_received_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $cashReceivedAt = ! empty($data['cash_received_at'])
            ? Carbon::parse($data['cash_received_at'])->setTimeFrom(now())
            : now();

        $before = [
            'po_number' => $po->po_number,
            'payment_type' => $po->payment_type,
            'total_amount' => $po->total_amount,
            'cash_received_at' => null,
            'cash_received_by' => null,
            'cash_account_id' => $po->cash_account_id,
            'receivable_status' => $po->receivable_status,
        ];

        $po->cash_received_at = $cashReceivedAt;
        $po->cash_received_by = $this->currentUserId();
        $po->cash_account_id = $data['cash_account_id'];
        $po->receivable_status = 'paid';
        $po->updated_by = $this->currentUserId();
        $po->save();

        $this->syncShippingIncomeForReceivablePo($po);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'purchase_order',
            'entity_id' => $po->id,
            'purchase_order_id' => $po->id,
            'action' => 'receivable_settled',
            'message' => sprintf(
                'Piutang PO %s ditandai %s oleh %s pada %s',
                $po->po_number,
                UiLabel::purchaseOrderStatus('completed'),
                $this->currentUser()->name,
                now()->format('d-m-Y H:i')
            ),
            'before_json' => $before,
            'after_json' => [
                'po_number' => $po->po_number,
                'payment_type' => $po->payment_type,
                'total_amount' => $po->total_amount,
                'cash_received_at' => optional($po->cash_received_at)?->toDateTimeString(),
                'cash_received_by' => $po->cash_received_by,
                'cash_account_id' => $po->cash_account_id,
                'receivable_status' => $po->receivable_status,
            ],
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.periods.index')
            ->with('success', "Piutang PO {$po->po_number} selesai dan masuk cash in.");
    }

    protected function buildReceivablesMonitoringData(Request $request, bool $paginate): array
    {
        $today = Carbon::today();
        $dateFrom = trim((string) $request->input('date_from', ''));
        $dateTo = trim((string) $request->input('date_to', ''));
        $urgency = $request->input('urgency');
        $customerSearch = trim((string) $request->input('customer', ''));
        $poNumberSearch = trim((string) $request->input('po_number', ''));

        $baseQuery = PurchaseOrder::query()
            ->with(['customer:id,name', 'items.product:id,name'])
            ->openReceivable();

        $filteredQuery = $this->applyReceivablesFilters(
            clone $baseQuery,
            $today,
            $dateFrom,
            $dateTo,
            is_string($urgency) ? $urgency : null,
            $customerSearch,
            $poNumberSearch
        );

        $orderedQuery = (clone $filteredQuery)
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date', 'asc')
            ->orderByDesc('completed_at');

        $periods = $paginate
            ? $orderedQuery->paginate(20)->withQueryString()
            : $orderedQuery->get();

        $this->decorateReceivableRows(
            $paginate ? $periods->getCollection() : $periods,
            $today
        );

        return [
            'periods' => $periods,
            'today' => $today,
            'dueReceivablesCount' => (clone $baseQuery)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', $today->toDateString())
                ->count(),
            'overdueReceivablesCount' => (clone $baseQuery)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today->toDateString())
                ->count(),
            'dueTodayReceivablesCount' => (clone $baseQuery)
                ->whereDate('due_date', $today->toDateString())
                ->count(),
            'dueSoonReceivablesCount' => (clone $baseQuery)
                ->whereBetween('due_date', [
                    $today->copy()->addDay()->toDateString(),
                    $today->copy()->addDays(7)->toDateString(),
                ])
                ->count(),
            'openReceivablesWithoutDueDateCount' => (clone $baseQuery)
                ->whereNull('due_date')
                ->count(),
            'legacyReceivablesCount' => (clone $baseQuery)
                ->whereNull('receivable_status')
                ->whereNull('cash_received_at')
                ->count(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'urgency' => $urgency,
            'customerSearch' => $customerSearch,
            'poNumberSearch' => $poNumberSearch,
            'exportedAt' => now(),
        ];
    }

    protected function applyReceivablesFilters(
        $query,
        Carbon $today,
        string $dateFrom,
        string $dateTo,
        ?string $urgency,
        string $customerSearch = '',
        string $poNumberSearch = ''
    ) {
        $query
            ->when($urgency !== 'no_due_date' && $dateFrom !== '', function ($query) use ($dateFrom) {
                $query->whereDate('due_date', '>=', $dateFrom);
            })
            ->when($urgency !== 'no_due_date' && $dateTo !== '', function ($query) use ($dateTo) {
                $query->whereDate('due_date', '<=', $dateTo);
            })
            ->when($customerSearch !== '', function ($query) use ($customerSearch) {
                $query->whereHas('customer', function ($q) use ($customerSearch) {
                    $q->where('name', 'like', '%' . $customerSearch . '%');
                });
            })
            ->when($poNumberSearch !== '', function ($query) use ($poNumberSearch) {
                $query->where('po_number', 'like', '%' . $poNumberSearch . '%');
            });

        if ($urgency === 'overdue') {
            $query->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today->toDateString());
        } elseif ($urgency === 'today') {
            $query->whereDate('due_date', $today->toDateString());
        } elseif ($urgency === 'next_7_days') {
            $query->whereBetween('due_date', [
                $today->copy()->addDay()->toDateString(),
                $today->copy()->addDays(7)->toDateString(),
            ]);
        } elseif ($urgency === 'no_due_date') {
            $query->whereNull('due_date');
        }

        return $query;
    }

    protected function decorateReceivableRows(Collection $periods, Carbon $today): void
    {
        $periods->transform(function (PurchaseOrder $po) use ($today) {
            $dueDate = $po->due_date;
            $daysRemaining = $dueDate ? $today->diffInDays($dueDate, false) : null;

            $itemsSummary = $po->items
                ->map(function ($item) {
                    $productName = $item->product->name ?? 'Produk';
                    return $productName . ' x' . (int) $item->qty;
                })
                ->implode(', ');

            $po->setAttribute('days_remaining', $daysRemaining);
            $po->setAttribute('items_summary', $itemsSummary);
            $po->setAttribute('is_legacy_receivable', is_null($po->receivable_status) && is_null($po->cash_received_at));

            return $po;
        });
    }

    public function cashflowReport(Request $request)
    {
        return view('accountingapp.reports.cashflow', $this->buildCashflowReportData($request));
    }

    public function exportCashflowExcel(Request $request)
    {
        $reportData = $this->buildCashflowReportData($request);
        $fileName = 'laporan_cashflow_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.reports.exports.cashflow', $reportData),
            $fileName
        );
    }

    public function exportCashflowPdf(Request $request)
    {
        $reportData = $this->buildCashflowReportData($request);
        $fileName = 'laporan_cashflow_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.pdf';

        return Pdf::loadView('accountingapp.reports.exports.cashflow', $reportData)
            ->setPaper('a4', 'landscape')
            ->download($fileName);
    }

    protected function buildCashflowReportData(Request $request): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $chartGranularity = (string) $request->input('chart_granularity', 'day');

        if (! in_array($chartGranularity, ['day', 'week', 'month'], true)) {
            $chartGranularity = 'day';
        }

        $openingBalances = OpeningBalance::query()
            ->where('type', 'cash')
            ->whereDate('balance_date', '<=', $dateTo->toDateString())
            ->get(['balance_date', 'amount']);

        $cashInEntries = $this->cashInQuery($dateFrom, $dateTo)
            ->get(['id', 'po_number', 'cash_received_at', 'total_amount', 'shipping_cost']);

        $otherIncomeEntries = OtherIncome::query()
            ->with('category:id,name')
            ->whereBetween('income_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->get(['id', 'income_date', 'income_category_id', 'amount']);

        $expenseEntries = CashOut::query()
            ->with('category:id,name')
            ->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->get(['id', 'expense_date', 'expense_category_id', 'amount']);

        $carryForwardOpening = $this->cashCarryForwardBefore($dateFrom);

        $cashflowSummary = $this->buildCashflowSnapshot(
            $dateFrom->copy()->startOfDay(),
            $dateTo->copy()->endOfDay(),
            $openingBalances,
            $cashInEntries,
            $otherIncomeEntries,
            $expenseEntries,
            $carryForwardOpening
        );

        $cashflowPeriods = $this->buildCashflowPeriods(
            $dateFrom->copy()->startOfDay(),
            $dateTo->copy()->endOfDay(),
            $chartGranularity,
            $openingBalances,
            $cashInEntries,
            $otherIncomeEntries,
            $expenseEntries,
            $carryForwardOpening
        );

        $activeWeekNumber = null;
        $cashflowWeekTabs = [];

        if ($chartGranularity === 'week') {
            $availableWeekNumbers = collect($cashflowPeriods)
                ->pluck('week_number')
                ->filter()
                ->map(fn ($weekNumber) => (int) $weekNumber)
                ->unique()
                ->sort()
                ->values();

            if ($availableWeekNumbers->isNotEmpty()) {
                $requestedWeekNumber = (int) $request->input('week_number', $availableWeekNumbers->first());
                $activeWeekNumber = $availableWeekNumbers->contains($requestedWeekNumber)
                    ? $requestedWeekNumber
                    : (int) $availableWeekNumbers->first();

                $cashflowWeekTabs = $this->buildCashflowWeekTabs($cashflowPeriods, $availableWeekNumbers);
                $cashflowPeriods = collect($cashflowPeriods)
                    ->where('week_number', $activeWeekNumber)
                    ->values()
                    ->all();
            }
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'chartGranularity' => $chartGranularity,
            'cashflowSummary' => $cashflowSummary,
            'cashflowPeriods' => $cashflowPeriods,
            'cashflowWeekTabs' => $cashflowWeekTabs,
            'activeWeekNumber' => $activeWeekNumber,
        ];
    }

    protected function buildCashflowSnapshot(
        Carbon $periodStart,
        Carbon $periodEnd,
        Collection $openingBalances,
        Collection $cashInEntries,
        Collection $otherIncomeEntries,
        Collection $expenseEntries,
        ?float $openingBalanceOverride = null
    ): array
    {
        $openingBalance = $openingBalanceOverride !== null
            ? round($openingBalanceOverride, 2)
            : round((float) $openingBalances
                ->filter(fn ($row) => $row->balance_date && $row->balance_date->lte($periodStart))
                ->sum('amount'), 2);

        $poCashIn = round((float) $this->filterEntriesByPeriod(
            $cashInEntries,
            'cash_received_at',
            $periodStart,
            $periodEnd
        )->sum(fn ($entry) => (float) $entry->total_amount - (float) ($entry->shipping_cost ?? 0)), 2);
        $poCashInRows = $this->buildPoCashInRows(
            $this->filterEntriesByPeriod($cashInEntries, 'cash_received_at', $periodStart, $periodEnd)
        );

        $otherIncomeRows = $this->groupEntriesByLabel(
            $this->filterEntriesByPeriod($otherIncomeEntries, 'income_date', $periodStart, $periodEnd),
            'category.name',
            'Pemasukan Lain'
        );

        $expenseRows = $this->groupEntriesByLabel(
            $this->filterEntriesByPeriod($expenseEntries, 'expense_date', $periodStart, $periodEnd),
            'category.name',
            'Pengeluaran Lain'
        );

        $otherIncomeTotal = round((float) collect($otherIncomeRows)->sum('amount'), 2);
        $totalIncome = round($poCashIn + $otherIncomeTotal, 2);
        $totalExpense = round((float) collect($expenseRows)->sum('amount'), 2);
        $endingBalance = round($openingBalance + $totalIncome - $totalExpense, 2);
        $grandTotal = round($openingBalance + $totalIncome, 2);

        $incomeRows = collect([
            ['label' => 'Saldo Awal', 'amount' => $openingBalance],
            ['label' => 'Penerimaan PO', 'amount' => $poCashIn],
        ])
            ->merge($otherIncomeRows)
            ->filter(fn ($row) => (float) $row['amount'] !== 0.0 || $row['label'] === 'Saldo Awal')
            ->values()
            ->all();

        return [
            'period_start' => $periodStart->copy(),
            'period_end' => $periodEnd->copy(),
            'opening_balance' => $openingBalance,
            'income_rows' => $incomeRows,
            'expense_rows' => $expenseRows,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'ending_balance' => $endingBalance,
            'grand_total' => $grandTotal,
            'income_breakdown' => collect($this->rowsToBreakdown($poCashInRows))
                ->merge($this->rowsToBreakdown($otherIncomeRows))
                ->values()
                ->all(),
            'expense_breakdown' => $this->rowsToBreakdown($expenseRows),
        ];
    }

    protected function buildCashflowPeriods(
        Carbon $dateFrom,
        Carbon $dateTo,
        string $granularity,
        Collection $openingBalances,
        Collection $cashInEntries,
        Collection $otherIncomeEntries,
        Collection $expenseEntries,
        ?float $initialOpeningBalance = null
    ): array {
        $periods = [];
        $cursor = $dateFrom->copy()->startOfDay();
        $runningOpeningBalance = $initialOpeningBalance !== null
            ? round($initialOpeningBalance, 2)
            : round((float) $openingBalances
                ->filter(fn ($row) => $row->balance_date && $row->balance_date->lte($dateFrom))
                ->sum('amount'), 2);

        while ($cursor->lte($dateTo)) {
            [$periodStart, $periodEnd, $label, $nextCursor, $meta] = $this->resolveCashflowPeriod(
                $cursor,
                $dateFrom,
                $dateTo,
                $granularity
            );

            $snapshot = $this->buildCashflowSnapshot(
                $periodStart,
                $periodEnd,
                $openingBalances,
                $cashInEntries,
                $otherIncomeEntries,
                $expenseEntries,
                $runningOpeningBalance
            );

            $snapshot['label'] = $label;
            $snapshot['group_label'] = $meta['group_label'] ?? null;
            $snapshot['sub_label'] = $meta['sub_label'] ?? null;
            $snapshot['week_number'] = $meta['week_number'] ?? null;
            $runningOpeningBalance = (float) $snapshot['ending_balance'];

            $hasTransactions = (float) $snapshot['total_income'] !== 0.0
                || (float) $snapshot['total_expense'] !== 0.0;

            if ($granularity !== 'day' || $hasTransactions) {
                $periods[] = $snapshot;
            }

            $cursor = $nextCursor->copy()->startOfDay();
        }

        return $periods;
    }

    protected function resolveCashflowPeriod(
        Carbon $cursor,
        Carbon $dateFrom,
        Carbon $dateTo,
        string $granularity
    ): array {
        if ($granularity === 'week') {
            $weekOfMonth = (int) ceil($cursor->day / 7);
            $periodStart = $cursor->copy()->startOfMonth()->addDays(($weekOfMonth - 1) * 7);
            $periodEnd = $weekOfMonth >= 5
                ? $cursor->copy()->endOfMonth()
                : $periodStart->copy()->addDays(6);
            $visibleStart = $periodStart->lt($dateFrom) ? $dateFrom->copy() : $periodStart;
            $visibleEnd = $periodEnd->gt($dateTo) ? $dateTo->copy() : $periodEnd;
            $groupLabel = $this->monthLabel((int) $periodStart->month) . ' ' . $periodStart->year;

            return [
                $visibleStart->copy()->startOfDay(),
                $visibleEnd->copy()->endOfDay(),
                'Week ' . $weekOfMonth,
                $periodEnd->copy()->addDay(),
                [
                    'group_label' => $groupLabel,
                    'sub_label' => $visibleStart->format('d M Y') . ' - ' . $visibleEnd->format('d M Y'),
                    'week_number' => $weekOfMonth,
                ],
            ];
        }

        if ($granularity === 'month') {
            $periodStart = $cursor->copy()->startOfMonth();
            $periodEnd = $cursor->copy()->endOfMonth();
            $visibleStart = $periodStart->lt($dateFrom) ? $dateFrom->copy() : $periodStart;
            $visibleEnd = $periodEnd->gt($dateTo) ? $dateTo->copy() : $periodEnd;
            $isFullMonth = $visibleStart->isSameDay($periodStart) && $visibleEnd->isSameDay($periodEnd);
            $label = $isFullMonth
                ? $this->monthLabel((int) $periodStart->month) . ' ' . $periodStart->year
                : $visibleStart->format('d M Y') . ' - ' . $visibleEnd->format('d M Y');

            return [
                $visibleStart->copy()->startOfDay(),
                $visibleEnd->copy()->endOfDay(),
                $label,
                $periodEnd->copy()->addDay(),
                [],
            ];
        }

        return [
            $cursor->copy()->startOfDay(),
            $cursor->copy()->endOfDay(),
            $cursor->format('d M Y'),
            $cursor->copy()->addDay(),
            [],
        ];
    }

    protected function filterEntriesByPeriod(Collection $entries, string $dateField, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return $entries->filter(function ($entry) use ($dateField, $periodStart, $periodEnd) {
            $value = data_get($entry, $dateField);

            if (! $value) {
                return false;
            }

            $timestamp = $value instanceof Carbon
                ? $value->copy()
                : Carbon::parse((string) $value);

            return $timestamp->betweenIncluded($periodStart, $periodEnd);
        });
    }

    protected function groupEntriesByLabel(Collection $entries, string $labelField, string $fallbackLabel): array
    {
        return $entries->groupBy(function ($entry) use ($labelField, $fallbackLabel) {
            $label = (string) data_get($entry, $labelField, '');

            return trim($label) !== '' ? $label : $fallbackLabel;
        })
            ->map(function (Collection $rows, string $label) {
                return [
                    'label' => $label,
                    'amount' => round((float) $rows->sum('amount'), 2),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    protected function rowsToBreakdown(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row) => (float) ($row['amount'] ?? 0) !== 0.0)
            ->values()
            ->all();
    }

    protected function buildPoCashInRows(Collection $entries): array
    {
        return $entries
            ->map(function (PurchaseOrder $entry) {
                $poNumber = trim((string) ($entry->po_number ?? ''));
                $principal = round((float) $entry->total_amount - (float) ($entry->shipping_cost ?? 0), 2);

                return [
                    'label' => 'Pelunasan',
                    'reference' => $poNumber !== '' ? $poNumber : '#' . $entry->id,
                    'amount' => $principal,
                ];
            })
            ->groupBy(fn (array $row) => $row['label'] . '|' . $row['reference'])
            ->map(fn (Collection $rows) => [
                'label' => $rows->first()['label'],
                'reference' => $rows->first()['reference'],
                'amount' => round((float) $rows->sum('amount'), 2),
            ])
            ->sortBy('reference')
            ->values()
            ->all();
    }

    protected function buildCashflowWeekTabs(array $cashflowPeriods, Collection $availableWeekNumbers): array
    {
        return collect($availableWeekNumbers)
            ->map(function (int $weekNumber) use ($cashflowPeriods) {
                $rows = collect($cashflowPeriods)
                    ->where('week_number', $weekNumber)
                    ->values();

                return [
                    'week_number' => $weekNumber,
                    'label' => 'Week ' . $weekNumber,
                    'period_count' => $rows->count(),
                    'months' => $rows->pluck('group_label')->filter()->unique()->values()->all(),
                    'total_income' => round((float) $rows->sum('total_income'), 2),
                    'total_expense' => round((float) $rows->sum('total_expense'), 2),
                    'net_cashflow' => round((float) $rows->sum(function ($row) {
                        return (float) ($row['total_income'] ?? 0) - (float) ($row['total_expense'] ?? 0);
                    }), 2),
                ];
            })
            ->values()
            ->all();
    }

    protected function currentUser(): User
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function currentUserId(): int
    {
        return $this->currentUser()->id;
    }

    protected function ensureOpeningBalanceEditAccess(): void
    {
        abort_unless(
            $this->currentUser()->hasAnyRole(['owner', 'superadmin']),
            403,
            'Hanya owner atau superadmin yang dapat mengedit saldo awal.'
        );
    }

    protected function ensurePeriodClosingAccess(): void
    {
        abort_unless(
            $this->currentUser()->hasAnyRole(['owner', 'superadmin']),
            403,
            'Hanya owner atau superadmin yang dapat menutup dan membuka periode.'
        );
    }

    protected function ensureBalanceSheetAdjustmentAccess(): void
    {
        abort_unless(
            $this->currentUser()->hasAnyRole(['owner', 'superadmin']),
            403,
            'Hanya owner atau superadmin yang dapat mengelola adjustment neraca.'
        );
    }

    protected function ensureProfitLossAdjustmentAccess(): void
    {
        abort_unless(
            $this->currentUser()->hasAnyRole(['owner', 'superadmin']),
            403,
            'Hanya owner atau superadmin yang dapat mengelola adjustment laba rugi.'
        );
    }

    protected function balanceSheetAdjustmentGroupOptions(): array
    {
        return [
            BalanceSheetAdjustment::GROUP_CASH => 'Kas',
            BalanceSheetAdjustment::GROUP_RECEIVABLE => 'Piutang Usaha',
            BalanceSheetAdjustment::GROUP_INVENTORY => 'Persediaan',
            BalanceSheetAdjustment::GROUP_FIXED_ASSET => 'Aktiva Tetap',
            BalanceSheetAdjustment::GROUP_PAYABLE => 'Kewajiban',
            BalanceSheetAdjustment::GROUP_EQUITY => 'Modal',
            BalanceSheetAdjustment::GROUP_WEALTH => 'Kekayaan',
        ];
    }

    protected function balanceSheetAdjustmentGroupLabel(?string $group): string
    {
        return $this->balanceSheetAdjustmentGroupOptions()[$group] ?? ucfirst((string) $group);
    }

    protected function balanceSheetAdjustmentAuditSnapshot(BalanceSheetAdjustment $adjustment): array
    {
        return [
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'account_group' => $adjustment->account_group,
            'group_label' => $this->balanceSheetAdjustmentGroupLabel($adjustment->account_group),
            'label' => $adjustment->label,
            'amount' => $adjustment->amount,
            'notes' => $adjustment->notes,
        ];
    }

    protected function profitLossAdjustmentGroupOptions(): array
    {
        return [
            ProfitLossAdjustment::GROUP_REVENUE => 'Pendapatan',
            ProfitLossAdjustment::GROUP_COGS => 'Beban Pokok Pendapatan',
            ProfitLossAdjustment::GROUP_OPERATING_EXPENSE => 'Beban Operasional',
            ProfitLossAdjustment::GROUP_OTHER_INCOME => 'Pendapatan Lain-lain',
        ];
    }

    protected function profitLossAdjustmentGroupLabel(?string $group): string
    {
        return $this->profitLossAdjustmentGroupOptions()[$group] ?? ucfirst((string) $group);
    }

    protected function profitLossAdjustmentExpenseCategories()
    {
        return ExpenseCategory::query()
            ->where('expense_mode', ExpenseCategory::MODE_DIRECT_EXPENSE)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function profitLossAdjustmentAuditSnapshot(ProfitLossAdjustment $adjustment): array
    {
        return [
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'statement_group' => $adjustment->statement_group,
            'group_label' => $this->profitLossAdjustmentGroupLabel($adjustment->statement_group),
            'expense_category_id' => $adjustment->expense_category_id,
            'label' => $adjustment->label,
            'amount' => $adjustment->amount,
            'notes' => $adjustment->notes,
        ];
    }

    protected function validateOpeningBalanceData(Request $request): array
    {
        $data = $request->validate([
            'balance_date' => ['required', 'date'],
            'type' => ['required', 'in:cash,receivable,payable'],
            'reference_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        if ($data['type'] === 'cash' && empty($data['reference_id'])) {
            throw ValidationException::withMessages([
                'reference_id' => 'Akun kas wajib dipilih untuk saldo awal tunai.',
            ]);
        }

        if ($data['type'] === 'receivable' && empty($data['reference_id'])) {
            throw ValidationException::withMessages([
                'reference_id' => 'Customer wajib dipilih untuk saldo awal piutang.',
            ]);
        }

        if ($data['type'] === 'cash' && ! CashAccount::whereKey($data['reference_id'])->exists()) {
            throw ValidationException::withMessages([
                'reference_id' => 'Akun kas yang dipilih tidak valid.',
            ]);
        }

        if ($data['type'] === 'receivable' && ! Customer::whereKey($data['reference_id'])->exists()) {
            throw ValidationException::withMessages([
                'reference_id' => 'Customer yang dipilih tidak valid.',
            ]);
        }

        if ($data['type'] === 'payable') {
            $data['reference_id'] = null;

            if (blank($data['supplier_name'] ?? null)) {
                throw ValidationException::withMessages([
                    'supplier_name' => 'Supplier wajib diisi untuk saldo awal hutang.',
                ]);
            }

            return $data;
        }

        $data['supplier_name'] = null;

        return $data;
    }

    protected function openingBalanceAuditSnapshot(OpeningBalance $openingBalance): array
    {
        return [
            'balance_date' => $openingBalance->balance_date?->toDateString(),
            'type' => $openingBalance->type,
            'type_label' => $this->openingBalanceTypeLabel($openingBalance->type),
            'reference_id' => $openingBalance->reference_id,
            'reference_label' => $this->openingBalanceReferenceLabel($openingBalance),
            'supplier_name' => $openingBalance->supplier_name ?? $openingBalance->payable?->supplier_name,
            'amount' => $openingBalance->amount,
            'description' => $openingBalance->description,
        ];
    }

    protected function openingBalanceTypeLabel(?string $type): string
    {
        return [
            'cash' => 'Tunai',
            'receivable' => 'Piutang',
            'payable' => 'Hutang',
        ][$type] ?? ucfirst((string) $type);
    }

    protected function openingBalanceReferenceLabel(OpeningBalance $openingBalance): string
    {
        return match ($openingBalance->type) {
            'cash' => $openingBalance->cashAccount->name ?? '-',
            'receivable' => $openingBalance->customer->name ?? '-',
            'payable' => $openingBalance->supplier_name ?? $openingBalance->payable?->supplier_name ?? '-',
            default => '-',
        };
    }

    protected function ensureOpeningBalancePayableCanBeUpdated(OpeningBalance $openingBalance, array $data): void
    {
        $payable = $openingBalance->payable;

        if (! $payable || ! $payable->cashOuts->isNotEmpty()) {
            return;
        }

        $existingSupplier = trim((string) ($openingBalance->supplier_name ?? $payable->supplier_name ?? ''));
        $incomingSupplier = trim((string) ($data['supplier_name'] ?? ''));
        $hasStructuralChanges =
            $openingBalance->type !== $data['type']
            || $openingBalance->balance_date?->toDateString() !== $data['balance_date']
            || (float) $openingBalance->amount !== (float) $data['amount']
            || $existingSupplier !== $incomingSupplier;

        if (! $hasStructuralChanges) {
            return;
        }

        throw ValidationException::withMessages([
            'type' => 'Saldo awal hutang yang sudah pernah dibayar tidak bisa diubah tipe, tanggal, supplier, atau nominalnya.',
        ]);
    }

    protected function syncPayableForOpeningBalance(OpeningBalance $openingBalance): void
    {
        $openingBalance->loadMissing('payable.cashOuts');

        if ($openingBalance->type !== 'payable') {
            $this->deleteOpeningBalancePayableIfAllowed($openingBalance);

            return;
        }

        Payable::query()->updateOrCreate(
            ['opening_balance_id' => $openingBalance->id],
            [
                'transaction_date' => $openingBalance->balance_date,
                'due_date' => null,
                'supplier_name' => $this->openingBalancePayableSupplierName($openingBalance),
                'description' => $openingBalance->description ?: 'Saldo awal hutang',
                'amount' => $openingBalance->amount,
                'status' => $openingBalance->payable?->cashOuts->isNotEmpty() ? 'paid' : 'unpaid',
                'paid_at' => $openingBalance->payable?->cashOuts->isNotEmpty() ? ($openingBalance->payable?->paid_at ?? now()) : null,
                'notes' => $openingBalance->description,
                'is_adjustment' => false,
                'adjustment_note' => null,
                'adjusted_by' => null,
                'created_by' => $openingBalance->payable?->created_by ?? $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]
        );
    }

    protected function deleteOpeningBalancePayableIfAllowed(OpeningBalance $openingBalance): void
    {
        $payable = $openingBalance->payable;

        if (! $payable) {
            return;
        }

        if ($payable->cashOuts()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'Saldo awal hutang ini sudah pernah dibayar dan tidak bisa diubah ke tipe lain.',
            ]);
        }

        $payable->delete();
    }

    protected function openingBalancePayableSupplierName(OpeningBalance $openingBalance): string
    {
        $supplierName = trim((string) ($openingBalance->supplier_name ?? ''));

        if ($supplierName !== '') {
            return $supplierName;
        }

        $fallback = trim((string) ($openingBalance->description ?? ''));

        if ($fallback !== '') {
            return $fallback;
        }

        return 'Saldo Awal Hutang #' . $openingBalance->id;
    }

    protected function yearMonthSelectSql(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column}) as period",
            'pgsql' => "to_char({$column}, 'YYYY-MM') as period",
            default => "DATE_FORMAT({$column}, '%Y-%m') as period",
        };
    }

    protected function monthLabel(int $month): string
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ][$month] ?? (string) $month;
    }

    protected function closedPeriodsByYearMap(): array
    {
        return PeriodClosing::query()
            ->select(['period_year', 'period_month'])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get()
            ->groupBy('period_year')
            ->map(fn ($rows) => $rows->pluck('period_month')->map(fn ($month) => (int) $month)->values()->all())
            ->toArray();
    }

    protected function extractInventoryPurchaseData(Request $request, ExpenseCategory $category, array $data): ?array
    {
        if (! $category->isInventoryPurchase()) {
            return null;
        }

        $paymentType = $data['payment_type'] ?? 'cash';

        if (! empty($data['payable_id'] ?? null)) {
            throw ValidationException::withMessages([
                'payable_id' => 'Pembayaran hutang tidak bisa digabung dengan pembelian stok baru dalam satu pengeluaran.',
            ]);
        }

        $messages = [];

        if (blank($data['inventory_item_id'] ?? null)) {
            $messages['inventory_item_id'] = 'Item inventory wajib dipilih untuk kategori pembelian stok.';
        }

        if (blank($data['inventory_unit_cost'] ?? null)) {
            $messages['inventory_unit_cost'] = 'Total cost wajib diisi untuk pembelian stok.';
        }

        if ($paymentType === 'payable' && blank($data['supplier_name'] ?? null)) {
            $messages['supplier_name'] = 'Supplier wajib diisi untuk pembelian stok hutang.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        return [
            'inventory_item_id' => (int) $data['inventory_item_id'],
            'qty' => 1.0,
            'unit_cost' => (float) $data['inventory_unit_cost'],
            'amount' => (float) $data['inventory_unit_cost'],
            'payment_type' => $paymentType,
            'due_date' => $paymentType === 'payable' ? ($data['due_date'] ?? null) : null,
            'supplier_name' => $data['supplier_name'] ?? null,
        ];
    }

    protected function normalizeExpenseFlowData(array &$data, string $expenseFlow): void
    {
        if ($expenseFlow === 'payable_settlement') {
            $data['payment_type'] = null;
            $data['due_date'] = null;
            $data['inventory_item_id'] = null;
            $data['inventory_qty'] = null;
            $data['inventory_unit_cost'] = null;
            $data['supplier_name'] = null;
            $data['amount'] = null;

            return;
        }

        $data['payable_id'] = null;
    }

    protected function resolveExpenseCategoryForFlow(array &$data, string $expenseFlow): ExpenseCategory
    {
        if ($expenseFlow === 'payable_settlement') {
            $category = $this->resolvePayableSettlementCategory();
            $data['expense_category_id'] = $category->getKey();

            return $category;
        }

        if (blank($data['expense_category_id'] ?? null)) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'Kategori wajib dipilih untuk pengeluaran biasa atau pembelian stok.',
            ]);
        }

        return ExpenseCategory::findOrFail($data['expense_category_id']);
    }

    protected function extractPayableSettlementData(ExpenseCategory $category, array $data, string $expenseFlow = 'expense'): ?array
    {
        if (empty($data['payable_id'] ?? null)) {
            if ($expenseFlow === 'payable_settlement') {
                throw ValidationException::withMessages([
                    'payable_id' => 'Hutang yang akan dibayar wajib dipilih.',
                ]);
            }

            return null;
        }

        if ($expenseFlow !== 'payable_settlement' && $category->isInventoryPurchase()) {
            throw ValidationException::withMessages([
                'payable_id' => 'Pembayaran hutang tidak boleh memakai kategori pembelian stok.',
            ]);
        }

        $payable = Payable::query()->findOrFail($data['payable_id']);

        if ($payable->status === 'paid') {
            throw ValidationException::withMessages([
                'payable_id' => 'Hutang yang dipilih sudah berstatus lunas.',
            ]);
        }

        return [
            'payable_id' => $payable->id,
            'amount' => (float) $payable->amount,
        ];
    }

    protected function ensureExpenseAmountForCategory(ExpenseCategory $category, array $data, ?array $payableSettlement = null): void
    {
        if ($payableSettlement !== null) {
            return;
        }

        if ($category->isInventoryPurchase()) {
            return;
        }

        if (blank($data['amount'] ?? null)) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal wajib diisi untuk pengeluaran direct expense.',
            ]);
        }
    }

    protected function ensureExpenseCashAccountForFlow(
        ExpenseCategory $category,
        array $data,
        ?array $inventoryData = null,
        ?array $payableSettlement = null
    ): void {
        if ($inventoryData !== null && ($inventoryData['payment_type'] ?? 'cash') === 'payable') {
            return;
        }

        if (! blank($data['cash_account_id'] ?? null)) {
            return;
        }

        $message = 'Akun kas wajib dipilih untuk transaksi ini.';

        if ($payableSettlement !== null) {
            $message = 'Akun kas wajib dipilih untuk pembayaran hutang.';
        } elseif ($category->isInventoryPurchase()) {
            $message = 'Akun kas wajib dipilih untuk pembelian stok tunai.';
        } elseif ($category->isDirectExpense()) {
            $message = 'Akun kas wajib dipilih untuk pengeluaran biasa.';
        }

        throw ValidationException::withMessages([
            'cash_account_id' => $message,
        ]);
    }

    protected function resolvePayableSettlementCategory(): ExpenseCategory
    {
        /** @var ExpenseCategory $category */
        $category = ExpenseCategory::query()->firstOrCreate(
            ['name' => self::PAYABLE_SETTLEMENT_CATEGORY_NAME],
            [
                'description' => 'Kategori sistem untuk pelunasan hutang dagang.',
                'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
                'include_hpp' => false,
                'is_active' => true,
            ]
        );

        if (! $category->is_active || ! $category->isDirectExpense() || $category->include_hpp) {
            $category->update([
                'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
                'include_hpp' => false,
                'is_active' => true,
            ]);
        }

        return $category;
    }

    protected function isProtectedExpenseCategory(ExpenseCategory $category): bool
    {
        return $this->isReservedExpenseCategoryName($category->name);
    }

    protected function isReservedExpenseCategoryName(?string $name): bool
    {
        return strcasecmp(trim((string) $name), self::PAYABLE_SETTLEMENT_CATEGORY_NAME) === 0;
    }

    protected function syncInventoryPurchaseForCashOut(CashOut $cashOut, ?array $inventoryData, ?string $notes = null): void
    {
        if ($inventoryData === null) {
            $cashOut->inventoryPurchase()?->delete();

            return;
        }

        InventoryPurchase::updateOrCreate(
            ['cash_out_id' => $cashOut->id],
            [
                'inventory_item_id' => $inventoryData['inventory_item_id'],
                'transaction_date' => $cashOut->expense_date,
                'qty' => $inventoryData['qty'],
                'unit_cost' => $inventoryData['unit_cost'],
                'total_value' => $inventoryData['amount'],
                'payment_type' => 'cash',
                'payable_id' => null,
                'supplier_name' => $inventoryData['supplier_name'],
                'notes' => $notes,
                'created_by' => $cashOut->created_by,
                'updated_by' => $this->currentUserId(),
            ]
        );
    }

    protected function createInventoryPurchasePayableFromExpense(
        array $data,
        array $inventoryData,
        bool $isClosedPeriod,
        ?string $adjustmentNote = null
    ): InventoryPurchase {
        $item = InventoryItem::query()->findOrFail($inventoryData['inventory_item_id']);

        $inventoryPurchase = InventoryPurchase::create([
            'inventory_item_id' => $inventoryData['inventory_item_id'],
            'transaction_date' => $data['expense_date'],
            'qty' => $inventoryData['qty'],
            'unit_cost' => $inventoryData['unit_cost'],
            'total_value' => $inventoryData['amount'],
            'payment_type' => 'payable',
            'cash_out_id' => null,
            'payable_id' => null,
            'supplier_name' => $inventoryData['supplier_name'],
            'notes' => $data['description'] ?? null,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);

        $payable = Payable::create([
            'transaction_date' => $data['expense_date'],
            'due_date' => $inventoryData['due_date'],
            'supplier_name' => $inventoryData['supplier_name'],
            'description' => $data['description'] ?? ('Pembelian stok ' . $item->name),
            'amount' => $inventoryData['amount'],
            'status' => 'unpaid',
            'paid_at' => null,
            'notes' => $data['description'] ?? null,
            'is_adjustment' => $isClosedPeriod,
            'adjustment_note' => $adjustmentNote,
            'adjusted_by' => $isClosedPeriod ? $this->currentUserId() : null,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);

        $inventoryPurchase->update([
            'payable_id' => $payable->id,
        ]);

        return $inventoryPurchase->fresh()->load(['item', 'payable']);
    }

    protected function inventoryPurchaseAuditSnapshot(InventoryPurchase $inventoryPurchase): array
    {
        return [
            'transaction_date' => $inventoryPurchase->transaction_date?->toDateString(),
            'inventory_item' => $inventoryPurchase->item->name ?? null,
            'qty' => $inventoryPurchase->qty,
            'unit_cost' => $inventoryPurchase->unit_cost,
            'total_value' => $inventoryPurchase->total_value,
            'payment_type' => $inventoryPurchase->payment_type,
            'supplier_name' => $inventoryPurchase->supplier_name,
            'notes' => $inventoryPurchase->notes,
            'payable_id' => $inventoryPurchase->payable_id,
            'due_date' => $inventoryPurchase->payable?->due_date?->toDateString(),
        ];
    }

    protected function syncPayableSettlementForCashOut(CashOut $cashOut, ?int $oldPayableId, ?int $newPayableId): void
    {
        if ($oldPayableId && $oldPayableId !== $newPayableId) {
            $stillReferenced = CashOut::query()
                ->where('payable_id', $oldPayableId)
                ->whereKeyNot($cashOut->id)
                ->exists();

            if (! $stillReferenced) {
                Payable::query()
                    ->whereKey($oldPayableId)
                    ->update([
                        'status' => 'unpaid',
                        'paid_at' => null,
                        'updated_by' => $this->currentUserId(),
                    ]);
            }
        }

        if (! $newPayableId) {
            return;
        }

        $payable = Payable::query()->findOrFail($newPayableId);

        if ($payable->status === 'paid' && $oldPayableId !== $newPayableId) {
            throw ValidationException::withMessages([
                'payable_id' => 'Hutang yang dipilih sudah berstatus lunas.',
            ]);
        }

        $payable->update([
            'status' => 'paid',
            'paid_at' => now(),
            'updated_by' => $this->currentUserId(),
        ]);
    }

    protected function parseDateRange(Request $request): array
    {
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        try {
            $dateFrom = $from ? Carbon::parse($from) : now()->startOfMonth();
        } catch (\Exception $e) {
            $dateFrom = now()->startOfMonth();
        }

        try {
            $dateTo = $to ? Carbon::parse($to) : now()->endOfMonth();
        } catch (\Exception $e) {
            $dateTo = now()->endOfMonth();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    protected function cashInQuery(Carbon $dateFrom, Carbon $dateTo)
    {
        return PurchaseOrder::query()
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at', 'and')
            ->whereBetween('cash_received_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ], 'and');
    }

    /**
     * The cash actually flowing in from receivable settlement is total_amount,
     * but shipping is booked separately as OtherIncome (Penjualan Lain-Lain),
     * so for cashflow / dashboard "Penerimaan PO" we surface only the non-shipping portion.
     */
    protected function poCashInPrincipalExpression(): string
    {
        return '(COALESCE(total_amount, 0) - COALESCE(shipping_cost, 0))';
    }

    protected function syncShippingIncomeForReceivablePo(PurchaseOrder $po): void
    {
        $shippingCost = round((float) ($po->shipping_cost ?? 0), 2);

        if ($shippingCost <= 0 || ! $po->cash_received_at || ! $po->cash_account_id) {
            return;
        }

        $category = IncomeCategory::query()->firstOrCreate(
            ['name' => OtherIncome::CATEGORY_OTHER_SALES],
            [
                'description' => 'Kategori sistem untuk ongkos kirim PO yang ditagih ke customer.',
                'is_active' => true,
            ]
        );

        OtherIncome::query()->updateOrCreate(
            [
                'source_type' => OtherIncome::SOURCE_PURCHASE_ORDER_SHIPPING,
                'source_id' => $po->id,
            ],
            [
                'income_date' => $po->cash_received_at->toDateString(),
                'income_category_id' => $category->id,
                'cash_account_id' => $po->cash_account_id,
                'amount' => $shippingCost,
                'description' => sprintf('Ongkir Pelunasan PO %s', $po->po_number),
                'created_by' => $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]
        );
    }

    public function cashAccountTransfersIndex(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = CashAccountTransfer::query()
            ->with(['fromCashAccount:id,name,type', 'toCashAccount:id,name,type', 'creator:id,name'])
            ->when($dateFrom, fn ($q) => $q->whereDate('transfer_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('transfer_date', '<=', $dateTo))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        $transfers = $query->paginate(20)->withQueryString();

        $cashAccounts = CashAccount::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $totalTransferred = CashAccountTransfer::query()
            ->when($dateFrom, fn ($q) => $q->whereDate('transfer_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('transfer_date', '<=', $dateTo))
            ->sum('amount');

        return view('accountingapp.cash-account-transfers.index', compact(
            'transfers',
            'cashAccounts',
            'dateFrom',
            'dateTo',
            'totalTransferred'
        ));
    }

    public function cashAccountTransfersStore(Request $request)
    {
        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'from_cash_account_id' => ['required', 'exists:cash_accounts,id', 'different:to_cash_account_id'],
            'to_cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'from_cash_account_id.different' => 'Akun sumber dan akun tujuan tidak boleh sama.',
        ]);

        $transfer = CashAccountTransfer::create([
            'transfer_date' => $data['transfer_date'],
            'from_cash_account_id' => $data['from_cash_account_id'],
            'to_cash_account_id' => $data['to_cash_account_id'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);

        $transfer->load(['fromCashAccount:id,name', 'toCashAccount:id,name']);

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'cash_account_transfer',
            'entity_id' => $transfer->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s transfer Rp %s dari %s ke %s pada %s',
                $this->currentUser()->name,
                number_format((float) $transfer->amount, 0, ',', '.'),
                $transfer->fromCashAccount->name ?? '-',
                $transfer->toCashAccount->name ?? '-',
                Carbon::parse($transfer->transfer_date)->format('d-m-Y')
            ),
            'before_json' => null,
            'after_json' => [
                'transfer_date' => $transfer->transfer_date?->toDateString(),
                'from' => $transfer->fromCashAccount->name ?? null,
                'to' => $transfer->toCashAccount->name ?? null,
                'amount' => (float) $transfer->amount,
                'notes' => $transfer->notes,
            ],
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.cash-account-transfers.index')
            ->with('success', 'Transfer antar akun berhasil dicatat.');
    }

    public function cashAccountTransfersDestroy(Request $request, CashAccountTransfer $cashAccountTransfer)
    {
        $cashAccountTransfer->load(['fromCashAccount:id,name', 'toCashAccount:id,name']);

        $before = [
            'transfer_date' => $cashAccountTransfer->transfer_date?->toDateString(),
            'from' => $cashAccountTransfer->fromCashAccount->name ?? null,
            'to' => $cashAccountTransfer->toCashAccount->name ?? null,
            'amount' => (float) $cashAccountTransfer->amount,
            'notes' => $cashAccountTransfer->notes,
        ];

        $transferId = $cashAccountTransfer->id;
        $cashAccountTransfer->delete();

        AuditLog::create([
            'user_id' => $this->currentUserId(),
            'entity' => 'cash_account_transfer',
            'entity_id' => $transferId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus transfer antar akun ID %s',
                $this->currentUser()->name,
                $transferId
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.cash-account-transfers.index')
            ->with('success', 'Transfer antar akun berhasil dihapus.');
    }

    protected function cashCarryForwardBefore(Carbon $dateFrom): float
    {
        $cutoff = $dateFrom->copy()->startOfDay();

        $openingBaseline = (float) OpeningBalance::query()
            ->where('type', 'cash')
            ->whereDate('balance_date', '<=', $cutoff->toDateString())
            ->sum('amount');

        $priorCashIn = (float) PurchaseOrder::query()
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at')
            ->where('cash_received_at', '<', $cutoff)
            ->sum(DB::raw($this->poCashInPrincipalExpression()));

        $priorOtherIncome = (float) OtherIncome::query()
            ->whereDate('income_date', '<', $cutoff->toDateString())
            ->sum('amount');

        $priorExpense = (float) CashOut::query()
            ->whereDate('expense_date', '<', $cutoff->toDateString())
            ->sum('amount');

        return round($openingBaseline + $priorCashIn + $priorOtherIncome - $priorExpense, 2);
    }
}
