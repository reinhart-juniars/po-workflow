<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPeriodClosing;
use App\Http\Controllers\Concerns\ResolvesCentralExpenseLocation;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryPurchaseController extends Controller
{
    use ChecksPeriodClosing;
    use ResolvesCentralExpenseLocation;

    public function index(Request $request): View
    {
        $items = InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $itemId = $request->integer('inventory_item_id') ?: null;
        $rawDateFrom = $request->input('date_from');
        $rawDateTo = $request->input('date_to');
        $paymentType = $request->input('payment_type');
        $rangeStart = $rawDateFrom ? Carbon::parse($rawDateFrom) : now()->startOfMonth();
        $rangeEnd = $rawDateTo ? Carbon::parse($rawDateTo) : now()->endOfMonth();
        $dateFrom = $rangeStart->toDateString();
        $dateTo = $rangeEnd->toDateString();
        $rangePeriodStatus = $this->periodStatusForRange($rangeStart, $rangeEnd);

        $purchases = InventoryPurchase::query()
            ->with(['item', 'cashOut.cashAccount', 'payable'])
            ->when($itemId, fn ($query) => $query->where('inventory_item_id', $itemId))
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->when(in_array($paymentType, ['cash', 'payable'], true), fn ($query) => $query->where('payment_type', $paymentType))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $purchases->getCollection()->transform(function (InventoryPurchase $purchase) {
            $purchase->setAttribute('period_closed', $this->isPeriodClosed($purchase->transaction_date->toDateString()));

            return $purchase;
        });

        $categoryTotals = InventoryPurchase::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_purchases.inventory_item_id')
            ->whereDate('inventory_purchases.transaction_date', '>=', $dateFrom)
            ->whereDate('inventory_purchases.transaction_date', '<=', $dateTo)
            ->when($itemId, fn ($query) => $query->where('inventory_purchases.inventory_item_id', $itemId))
            ->when(in_array($paymentType, ['cash', 'payable'], true), fn ($query) => $query->where('inventory_purchases.payment_type', $paymentType))
            ->groupBy('inventory_items.category')
            ->selectRaw('inventory_items.category as category, SUM(inventory_purchases.total_value) as total')
            ->pluck('total', 'category');

        $kpis = [
            'raw_material' => (float) ($categoryTotals[InventoryItem::CATEGORY_RAW_MATERIAL] ?? 0),
            'fixed_asset' => (float) ($categoryTotals[InventoryItem::CATEGORY_FIXED_ASSET] ?? 0),
            'packaging' => (float) ($categoryTotals[InventoryItem::CATEGORY_PACKAGING] ?? 0),
        ];

        return view('inventory.purchases.index', compact(
            'purchases',
            'items',
            'itemId',
            'dateFrom',
            'dateTo',
            'paymentType',
            'rangePeriodStatus',
            'kpis'
        ));
    }

    public function create(): RedirectResponse
    {
        return redirect()
            ->route('accountingapp.expenses.index')
            ->with('warning', 'Input pembelian stok sekarang dilakukan lewat menu Pengeluaran.');
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()
            ->route('accountingapp.expenses.index')
            ->with('warning', 'Input pembelian stok sekarang dilakukan lewat menu Pengeluaran.');
    }

    public function edit(InventoryPurchase $inventoryPurchase): View
    {
        $inventoryPurchase->load(['item', 'cashOut', 'payable']);
        $inventoryItemKey = (new InventoryItem())->getKeyName();
        $expenseCategoryKey = (new ExpenseCategory())->getKeyName();
        $cashAccountKey = (new CashAccount())->getKeyName();

        $items = InventoryItem::query()
            ->where(function ($query) use ($inventoryPurchase, $inventoryItemKey) {
                $query->where('is_active', true)
                    ->orWhere($inventoryItemKey, $inventoryPurchase->inventory_item_id);
            })
            ->orderBy('name')
            ->get();

        $inventoryCategories = ExpenseCategory::query()
            ->where(function ($query) use ($inventoryPurchase, $expenseCategoryKey) {
                $query->where(function ($innerQuery) {
                    $innerQuery->where('is_active', true)
                        ->where('expense_mode', ExpenseCategory::MODE_INVENTORY_PURCHASE);
                });

                if ($inventoryPurchase->cashOut?->expense_category_id) {
                    $query->orWhere($expenseCategoryKey, $inventoryPurchase->cashOut->expense_category_id);
                }
            })
            ->orderBy('name')
            ->get();

        $cashAccounts = CashAccount::query()
            ->where(function ($query) use ($inventoryPurchase, $cashAccountKey) {
                $query->where('is_active', true);

                if ($inventoryPurchase->cashOut?->cash_account_id) {
                    $query->orWhere($cashAccountKey, $inventoryPurchase->cashOut->cash_account_id);
                }
            })
            ->orderBy('name')
            ->get();

        return view('inventory.purchases.edit', compact(
            'inventoryPurchase',
            'items',
            'inventoryCategories',
            'cashAccounts'
        ));
    }

    public function update(Request $request, InventoryPurchase $inventoryPurchase): RedirectResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'transaction_date' => ['required', 'date'],
            'total_cost' => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['required', Rule::in(['cash', 'payable'])],
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'cash_account_id' => ['nullable', 'exists:cash_accounts,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['qty'] = 1.0;
        $data['unit_cost'] = (float) $data['total_cost'];

        $data = $this->normalizePaymentData($data);
        $this->ensureFlowRequirements($data);

        DB::transaction(function () use ($inventoryPurchase, $data) {
            $inventoryPurchase->update([
                'inventory_item_id' => $data['inventory_item_id'],
                'transaction_date' => $data['transaction_date'],
                'qty' => $data['qty'],
                'unit_cost' => $data['unit_cost'],
                'total_value' => (float) $data['unit_cost'],
                'payment_type' => $data['payment_type'],
                'supplier_name' => $data['supplier_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $this->currentUserId(),
            ]);

            $this->syncFinancialFlowForPurchase($inventoryPurchase, $data);
        });

        return redirect()
            ->route('accountingapp.inventory-purchases.index')
            ->with('success', 'Pembelian stok diperbarui.');
    }

    public function destroy(InventoryPurchase $inventoryPurchase): RedirectResponse
    {
        $this->ensureInventoryPurchaseDeleteAccess();

        $inventoryPurchase->load(['payable', 'cashOut']);

        DB::transaction(function () use ($inventoryPurchase) {
            if ($inventoryPurchase->payable && $inventoryPurchase->payable->status !== 'unpaid') {
                throw ValidationException::withMessages([
                    'payment_type' => 'Pembelian stok kredit tidak bisa dihapus karena hutangnya sudah dibayar atau diproses.',
                ]);
            }

            if ($inventoryPurchase->cashOut) {
                $inventoryPurchase->cashOut->delete();
            }

            if ($inventoryPurchase->payable) {
                $inventoryPurchase->payable->delete();
            }

            $inventoryPurchase->delete();
        });

        return redirect()
            ->route('accountingapp.inventory-purchases.index')
            ->with('success', 'Pembelian stok berhasil dihapus.');
    }

    protected function ensureFlowRequirements(array $data): void
    {
        $messages = [];

        if ($data['payment_type'] === 'payable' && blank($data['supplier_name'] ?? null)) {
            $messages['supplier_name'] = 'Supplier wajib diisi untuk pembelian kredit.';
        }

        if ($data['payment_type'] === 'cash') {
            if (blank($data['expense_category_id'] ?? null)) {
                $messages['expense_category_id'] = 'Kategori pengeluaran wajib dipilih untuk pembelian tunai.';
            }

            if (blank($data['cash_account_id'] ?? null)) {
                $messages['cash_account_id'] = 'Cash account wajib dipilih untuk pembelian tunai.';
            } elseif (! CashAccount::query()->whereKey($data['cash_account_id'])->exists()) {
                $messages['cash_account_id'] = 'Cash account tidak valid.';
            }

            if (! empty($data['expense_category_id'] ?? null)) {
                $category = ExpenseCategory::query()->find($data['expense_category_id']);

                if (! $category || $category->expense_mode !== ExpenseCategory::MODE_INVENTORY_PURCHASE) {
                    $messages['expense_category_id'] = 'Kategori untuk pembelian tunai harus bertipe Pembelian Stok.';
                }
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    protected function normalizePaymentData(array $data): array
    {
        if ($data['payment_type'] === 'cash') {
            $data['due_date'] = null;
        }

        return $data;
    }

    protected function syncFinancialFlowForPurchase(InventoryPurchase $inventoryPurchase, array $data): void
    {
        if ($data['payment_type'] === 'cash') {
            $this->syncCashOutForPurchase($inventoryPurchase, $data);
            $this->detachPayableFromPurchase($inventoryPurchase);

            return;
        }

        $this->syncPayableForPurchase($inventoryPurchase, $data);
        $this->detachCashOutFromPurchase($inventoryPurchase);
    }

    protected function syncCashOutForPurchase(InventoryPurchase $inventoryPurchase, array $data): void
    {
        $itemName = $inventoryPurchase->item()->value('name') ?? 'Item inventory';

        $cashOut = CashOut::query()->updateOrCreate(
            ['id' => $inventoryPurchase->cash_out_id],
            [
                'expense_category_id' => $data['expense_category_id'],
                'expense_location_id' => $this->centralExpenseLocationId(),
                'cash_account_id' => $data['cash_account_id'],
                'payable_id' => null,
                'amount' => (float) $data['unit_cost'],
                'expense_date' => $data['transaction_date'],
                'description' => $data['notes'] ?? ('Pembelian stok ' . $itemName),
                'is_adjustment' => false,
                'adjustment_note' => null,
                'adjusted_by' => null,
                'created_by' => $inventoryPurchase->created_by ?? $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]
        );

        if ($inventoryPurchase->cash_out_id !== $cashOut->id) {
            $inventoryPurchase->update(['cash_out_id' => $cashOut->id]);
        }
    }

    protected function syncPayableForPurchase(InventoryPurchase $inventoryPurchase, array $data): void
    {
        $itemName = $inventoryPurchase->item()->value('name') ?? 'Item inventory';

        $payable = Payable::query()->updateOrCreate(
            ['id' => $inventoryPurchase->payable_id],
            [
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'] ?? null,
                'supplier_name' => $data['supplier_name'],
                'description' => 'Pembelian stok ' . $itemName,
                'amount' => (float) $data['unit_cost'],
                'status' => 'unpaid',
                'paid_at' => null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $inventoryPurchase->created_by ?? $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]
        );

        if ($inventoryPurchase->payable_id !== $payable->id) {
            $inventoryPurchase->update(['payable_id' => $payable->id]);
        }
    }

    protected function detachCashOutFromPurchase(InventoryPurchase $inventoryPurchase): void
    {
        if (! $inventoryPurchase->cash_out_id) {
            return;
        }

        CashOut::query()->whereKey($inventoryPurchase->cash_out_id)->delete();
        $inventoryPurchase->update(['cash_out_id' => null]);
    }

    protected function detachPayableFromPurchase(InventoryPurchase $inventoryPurchase): void
    {
        if (! $inventoryPurchase->payable_id) {
            return;
        }

        $payable = $inventoryPurchase->payable;

        if ($payable && $payable->status !== 'unpaid') {
            throw ValidationException::withMessages([
                'payment_type' => 'Pembelian ini sudah terkait hutang yang tidak bisa dilepas karena sudah dibayar atau diproses.',
            ]);
        }

        if ($payable) {
            $payable->delete();
        }

        $inventoryPurchase->update(['payable_id' => null]);
    }

    protected function ensureInventoryPurchaseDeleteAccess(): void
    {
        abort_unless(
            $this->currentUser()->hasAnyRole(['owner', 'superadmin']),
            403,
            'Hanya owner atau superadmin yang dapat menghapus pembelian stok.'
        );
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
}
