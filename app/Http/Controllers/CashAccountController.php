<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CashAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CashAccountController extends Controller
{
    public function index()
    {
        $cashAccounts = CashAccount::query()
            ->orderBy('name')
            ->paginate(20);

        return view('accountingapp.cash-accounts.index', compact('cashAccounts'));
    }

    public function create()
    {
        return redirect()->route('accountingapp.cash-accounts.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cash_accounts,name'],
            'type' => ['required', 'in:cash,bank'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $cashAccount = CashAccount::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'cash_account',
            'entity_id' => $cashAccount->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan cash account %s',
                Auth::user()->name ?? 'Unknown',
                $cashAccount->name
            ),
            'before_json' => null,
            'after_json' => json_encode([
                'name' => $cashAccount->name,
                'type' => $cashAccount->type,
                'description' => $cashAccount->description,
                'is_active' => $cashAccount->is_active,
            ]),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.cash-accounts.index')
            ->with('success', 'Cash account berhasil ditambahkan.');
    }

    public function edit(CashAccount $cashAccount)
    {
        return view('accountingapp.cash-accounts.edit', compact('cashAccount'));
    }

    public function update(Request $request, CashAccount $cashAccount)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('cash_accounts', 'name')->ignore($cashAccount->id)],
            'type' => ['required', 'in:cash,bank'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = [
            'name' => $cashAccount->name,
            'type' => $cashAccount->type,
            'description' => $cashAccount->description,
            'is_active' => $cashAccount->is_active,
        ];

        $cashAccount->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'cash_account',
            'entity_id' => $cashAccount->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah cash account %s',
                Auth::user()->name ?? 'Unknown',
                $cashAccount->name
            ),
            'before_json' => json_encode($before),
            'after_json' => json_encode([
                'name' => $cashAccount->name,
                'type' => $cashAccount->type,
                'description' => $cashAccount->description,
                'is_active' => $cashAccount->is_active,
            ]),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.cash-accounts.index')
            ->with('success', 'Cash account berhasil diperbarui.');
    }

    public function destroy(Request $request, CashAccount $cashAccount)
    {
        if (
            $cashAccount->cashOuts()->exists()
            || $cashAccount->purchaseOrders()->exists()
            || $cashAccount->otherIncomes()->exists()
            || $cashAccount->openingBalances()->exists()
        ) {
            return redirect()
                ->route('accountingapp.cash-accounts.index')
                ->with('error', 'Cash account tidak bisa dihapus karena sudah dipakai transaksi.');
        }

        $before = [
            'name' => $cashAccount->name,
            'type' => $cashAccount->type,
            'description' => $cashAccount->description,
            'is_active' => $cashAccount->is_active,
        ];

        $cashAccountName = $cashAccount->name;
        $cashAccountId = $cashAccount->id;

        $cashAccount->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'cash_account',
            'entity_id' => $cashAccountId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus cash account %s',
                Auth::user()->name ?? 'Unknown',
                $cashAccountName
            ),
            'before_json' => json_encode($before),
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.cash-accounts.index')
            ->with('success', 'Cash account berhasil dihapus.');
    }
}
