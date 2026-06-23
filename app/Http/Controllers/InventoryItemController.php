<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryItemController extends Controller
{
    public function index(): View
    {
        $items = InventoryItem::query()
            ->withCount(['openings', 'purchases', 'opnames'])
            ->orderBy('name')
            ->paginate(20);
        $categoryOptions = InventoryItem::categoryOptions();

        return view('inventory.items.index', compact('items', 'categoryOptions'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('accountingapp.inventory-items.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'category' => ['required', Rule::in(array_keys(InventoryItem::categoryOptions()))],
            'description' => ['nullable', 'string'],
        ]);

        InventoryItem::create([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Item berhasil ditambahkan');
    }

    public function edit(InventoryItem $inventoryItem): RedirectResponse
    {
        return redirect()->route('accountingapp.inventory-items.index');
    }

    public function update(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'category' => ['required', Rule::in(array_keys(InventoryItem::categoryOptions()))],
            'description' => ['nullable', 'string'],
        ]);

        $inventoryItem->update([
            ...$data,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Item berhasil diperbarui');
    }

    public function destroy(InventoryItem $inventoryItem): RedirectResponse
    {
        $inventoryItem->loadCount(['openings', 'purchases', 'opnames']);

        $usageSummary = $this->inventoryItemUsageSummary($inventoryItem);

        if ($usageSummary !== []) {
            return back()->with(
                'error',
                'Item tidak bisa dihapus karena sudah dipakai di ' . implode(', ', $usageSummary) . '. Hapus atau koreksi transaksi terkait dulu.'
            );
        }

        $inventoryItem->delete();

        return back()->with('success', 'Item berhasil dihapus');
    }

    protected function inventoryItemUsageSummary(InventoryItem $inventoryItem): array
    {
        return collect([
            'opening inventory' => (int) $inventoryItem->openings_count,
            'pembelian stok' => (int) $inventoryItem->purchases_count,
            'stock opname' => (int) $inventoryItem->opnames_count,
        ])
            ->filter(fn (int $count) => $count > 0)
            ->map(fn (int $count, string $label) => $label . ' (' . $count . ')')
            ->values()
            ->all();
    }
}
