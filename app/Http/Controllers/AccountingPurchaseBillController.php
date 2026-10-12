<?php

namespace App\Http\Controllers;

use App\Http\Requests\Accounting\CreditPurchaseBillRequest;
use App\Http\Requests\Accounting\PayPurchaseBillRequest;
use App\Http\Requests\Accounting\ReturnPurchaseBillRequest;
use App\Models\CashAccount;
use App\Models\PurchaseBill;
use App\Services\PurchaseBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Accounting › Tagihan Pembelian: antrean tagihan dari gudang. Membayar,
 * menjadikan hutang supplier, atau mengembalikan -- pembukuannya di
 * PurchaseBillService.
 */
class AccountingPurchaseBillController extends Controller
{
    /** Tab => status yang ditampilkan. */
    protected const TABS = [
        'menunggu' => [PurchaseBill::STATUS_SUBMITTED],
        'hutang' => [PurchaseBill::STATUS_CREDIT],
        'dibayar' => [PurchaseBill::STATUS_PAID],
        'gudang' => [PurchaseBill::STATUS_DRAFT, PurchaseBill::STATUS_RETURNED],
    ];

    public function __construct(protected PurchaseBillService $bills) {}

    public function index(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'menunggu';
        $q = trim((string) $request->query('q', ''));

        $bills = PurchaseBill::query()
            ->with(['requisition:id,number', 'supplier:id,name'])
            ->whereIn('status', self::TABS[$tab])
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('number', 'like', '%'.$q.'%')
                ->orWhere('supplier_name', 'like', '%'.$q.'%')))
            // Hutang supplier: yang jatuh tempo duluan di atas.
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $counts = collect(self::TABS)->map(fn (array $statuses) => PurchaseBill::query()->whereIn('status', $statuses)->count());
        $totals = [
            'menunggu' => (float) PurchaseBill::query()->where('status', PurchaseBill::STATUS_SUBMITTED)->sum('total'),
            'hutang' => (float) PurchaseBill::query()->where('status', PurchaseBill::STATUS_CREDIT)->sum('total'),
            'jatuh_tempo' => PurchaseBill::query()->where('status', PurchaseBill::STATUS_CREDIT)->whereDate('due_date', '<=', today())->count(),
        ];

        return view('accountingapp.purchase-bills.index', compact('bills', 'tab', 'counts', 'totals', 'q'));
    }

    public function show(PurchaseBill $purchaseBill): View
    {
        $purchaseBill->load(['purchases.item', 'requisition.productionOrder', 'supplier', 'submitter', 'decider', 'cashAccount', 'cashOut']);

        return view('accountingapp.purchase-bills.show', [
            'bill' => $purchaseBill,
            'cashAccounts' => CashAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canDecide' => auth()->user()?->can('purchase.pay') ?? false,
            'defaultDueDate' => $purchaseBill->supplier?->dueDateFor(now()->toDateString()) ?? now()->addDays(14)->toDateString(),
        ]);
    }

    public function pay(PayPurchaseBillRequest $request, PurchaseBill $purchaseBill): RedirectResponse
    {
        $this->bills->pay($purchaseBill, $request->validated(), $request->user()->id);

        return redirect()->route('accountingapp.purchase-bills.show', $purchaseBill)
            ->with('status', 'Tagihan '.$purchaseBill->number.' dibayar. Kas keluar tercatat di Pengeluaran.');
    }

    public function credit(CreditPurchaseBillRequest $request, PurchaseBill $purchaseBill): RedirectResponse
    {
        $this->bills->markCredit($purchaseBill, $request->validated('due_date'), $request->user()->id);

        return redirect()->route('accountingapp.purchase-bills.show', $purchaseBill)
            ->with('status', 'Tagihan '.$purchaseBill->number.' dicatat sebagai hutang supplier.');
    }

    public function returnToInventory(ReturnPurchaseBillRequest $request, PurchaseBill $purchaseBill): RedirectResponse
    {
        $this->bills->returnToInventory($purchaseBill, $request->validated('return_reason'), $request->user()->id);

        return redirect()->route('accountingapp.purchase-bills.index')
            ->with('status', 'Tagihan '.$purchaseBill->number.' dikembalikan ke gudang.');
    }
}
