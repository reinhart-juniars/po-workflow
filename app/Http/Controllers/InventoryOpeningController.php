<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryOpeningController extends Controller
{
    public function index(Request $request): View
    {
        $items = InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $itemId = $request->integer('inventory_item_id') ?: null;
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $openings = InventoryOpening::query()
            ->with('item')
            ->when($itemId, fn ($query) => $query->where('inventory_item_id', $itemId))
            ->when($dateFrom, fn ($query) => $query->whereDate('balance_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('balance_date', '<=', $dateTo))
            ->orderByDesc('balance_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.openings.index', compact('openings', 'items', 'itemId', 'dateFrom', 'dateTo'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('accountingapp.inventory-openings.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'balance_date' => ['required', 'date'],
            'total_cost' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $totalCost = (float) $data['total_cost'];

        InventoryOpening::create([
            'inventory_item_id' => $data['inventory_item_id'],
            'balance_date' => $data['balance_date'],
            'qty' => 1,
            'unit_cost' => $totalCost,
            'total_value' => $totalCost,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Opening inventory tersimpan');
    }

    public function edit(InventoryOpening $inventoryOpening): RedirectResponse
    {
        return redirect()->route('accountingapp.inventory-openings.index');
    }

    public function update(Request $request, InventoryOpening $inventoryOpening): RedirectResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'balance_date' => ['required', 'date'],
            'total_cost' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $totalCost = (float) $data['total_cost'];

        $inventoryOpening->update([
            'inventory_item_id' => $data['inventory_item_id'],
            'balance_date' => $data['balance_date'],
            'qty' => 1,
            'unit_cost' => $totalCost,
            'total_value' => $totalCost,
            'notes' => $data['notes'] ?? null,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Opening inventory diperbarui');
    }

    public function destroy(InventoryOpening $inventoryOpening): RedirectResponse
    {
        $inventoryOpening->delete();

        return back()->with('success', 'Opening inventory dihapus');
    }
}
