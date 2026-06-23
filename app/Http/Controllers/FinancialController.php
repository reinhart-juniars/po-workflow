<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPeriodClosing;
use App\Http\Controllers\Concerns\ResolvesCentralExpenseLocation;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FinancialController extends Controller
{
    use ChecksPeriodClosing;
    use ResolvesCentralExpenseLocation;

    public function dashboard(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $salesQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()]);

        $cashInQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at')
            ->whereBetween('cash_received_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()]);

        $expenseQuery = CashOut::query()
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        $totalSales = $salesQuery->sum('total_amount');
        // Ongkir dipisah ke OtherIncome (Penjualan Lain-Lain), jadi cuma porsi principal di sini.
        $totalCashIn = $cashInQuery->sum(DB::raw('(COALESCE(total_amount, 0) - COALESCE(shipping_cost, 0))'));
        $totalExpense = $expenseQuery->sum('amount');
        $netCashflow = $totalCashIn - $totalExpense;

        $salesPerMonth = PurchaseOrder::query()
            ->selectRaw('DATE_FORMAT(completed_at, "%Y-%m") as period, SUM(total_amount) as total')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $expensePerMonth = CashOut::query()
            ->selectRaw('DATE_FORMAT(expense_date, "%Y-%m") as period, SUM(amount) as total')
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return view('financial.dashboard', compact(
            'totalSales',
            'totalCashIn',
            'totalExpense',
            'netCashflow',
            'salesPerMonth',
            'expensePerMonth',
            'dateFrom',
            'dateTo'
        ));
    }

    public function expensesIndex(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $expenses = CashOut::with(['category', 'creator'])
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderByDesc('expense_date')
            ->paginate(20)
            ->withQueryString();

        $categories = ExpenseCategory::where('is_active', true)->orderBy('name')->get();

        return view('financial.expenses.index', compact(
            'expenses',
            'categories',
            'dateFrom',
            'dateTo'
        ));
    }

    public function expensesStore(Request $request)
    {
        $data = $request->validate([
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'adjustment_note' => ['nullable', 'string'],
        ]);

        $isClosedPeriod = $this->isPeriodClosed($data['expense_date']);

        if ($isClosedPeriod) {
            if (! auth()->user()->hasRole('owner')) {
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

        CashOut::create([
            ...$data,
            'expense_location_id' => $this->centralExpenseLocationId(),
            'is_adjustment' => $isClosedPeriod,
            'adjustment_note' => $request->input('adjustment_note'),
            'adjusted_by' => $isClosedPeriod ? Auth::id() : null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'Pengeluaran berhasil ditambahkan.');
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
}
