<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\IncomeCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class IncomeCategoryController extends Controller
{
    public function index()
    {
        $incomeCategories = IncomeCategory::query()
            ->orderBy('name')
            ->paginate(20);

        return view('accountingapp.income-categories.index', compact('incomeCategories'));
    }

    public function create()
    {
        return redirect()->route('accountingapp.income-categories.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:income_categories,name'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $incomeCategory = IncomeCategory::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'income_category',
            'entity_id' => $incomeCategory->id,
            'purchase_order_id' => null,
            'action' => 'created',
            'message' => sprintf(
                'User %s menambahkan kategori pemasukan %s',
                Auth::user()->name ?? 'Unknown',
                $incomeCategory->name
            ),
            'before_json' => null,
            'after_json' => json_encode([
                'name' => $incomeCategory->name,
                'description' => $incomeCategory->description,
                'is_active' => $incomeCategory->is_active,
            ]),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.income-categories.index')
            ->with('success', 'Kategori pemasukan berhasil ditambahkan.');
    }

    public function edit(IncomeCategory $incomeCategory)
    {
        return view('accountingapp.income-categories.edit', compact('incomeCategory'));
    }

    public function update(Request $request, IncomeCategory $incomeCategory)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('income_categories', 'name')->ignore($incomeCategory->id)],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = [
            'name' => $incomeCategory->name,
            'description' => $incomeCategory->description,
            'is_active' => $incomeCategory->is_active,
        ];

        $incomeCategory->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'income_category',
            'entity_id' => $incomeCategory->id,
            'purchase_order_id' => null,
            'action' => 'updated',
            'message' => sprintf(
                'User %s mengubah kategori pemasukan %s',
                Auth::user()->name ?? 'Unknown',
                $incomeCategory->name
            ),
            'before_json' => json_encode($before),
            'after_json' => json_encode([
                'name' => $incomeCategory->name,
                'description' => $incomeCategory->description,
                'is_active' => $incomeCategory->is_active,
            ]),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.income-categories.index')
            ->with('success', 'Kategori pemasukan berhasil diperbarui.');
    }

    public function destroy(Request $request, IncomeCategory $incomeCategory)
    {
        if ($incomeCategory->otherIncomes()->exists()) {
            return redirect()
                ->route('accountingapp.income-categories.index')
                ->with('error', 'Kategori pemasukan tidak bisa dihapus karena sudah dipakai transaksi.');
        }

        $before = [
            'name' => $incomeCategory->name,
            'description' => $incomeCategory->description,
            'is_active' => $incomeCategory->is_active,
        ];

        $categoryName = $incomeCategory->name;
        $categoryId = $incomeCategory->id;

        $incomeCategory->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'income_category',
            'entity_id' => $categoryId,
            'purchase_order_id' => null,
            'action' => 'deleted',
            'message' => sprintf(
                'User %s menghapus kategori pemasukan %s',
                Auth::user()->name ?? 'Unknown',
                $categoryName
            ),
            'before_json' => json_encode($before),
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('accountingapp.income-categories.index')
            ->with('success', 'Kategori pemasukan berhasil dihapus.');
    }
}
