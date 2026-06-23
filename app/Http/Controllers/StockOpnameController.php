<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\StockOpname;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockOpnameController extends Controller
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

        $opnames = StockOpname::query()
            ->with('item')
            ->when($itemId, fn ($query) => $query->where('inventory_item_id', $itemId))
            ->when($dateFrom, fn ($query) => $query->whereDate('opname_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('opname_date', '<=', $dateTo))
            ->orderByDesc('opname_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.opnames.index', compact('opnames', 'items', 'itemId', 'dateFrom', 'dateTo'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('accountingapp.stock-opnames.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'opname_date' => ['required', 'date'],
            'total_cost' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $totalCost = (float) $data['total_cost'];

        StockOpname::create([
            'inventory_item_id' => $data['inventory_item_id'],
            'opname_date' => $data['opname_date'],
            'qty' => 1,
            'unit_cost' => $totalCost,
            'total_value' => $totalCost,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Stock opname tersimpan');
    }

    public function edit(StockOpname $stockOpname): RedirectResponse
    {
        return redirect()->route('accountingapp.stock-opnames.index');
    }

    public function update(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'opname_date' => ['required', 'date'],
            'total_cost' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $totalCost = (float) $data['total_cost'];

        $stockOpname->update([
            'inventory_item_id' => $data['inventory_item_id'],
            'opname_date' => $data['opname_date'],
            'qty' => 1,
            'unit_cost' => $totalCost,
            'total_value' => $totalCost,
            'notes' => $data['notes'] ?? null,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Stock opname diperbarui');
    }

    public function destroy(StockOpname $stockOpname): RedirectResponse
    {
        $stockOpname->delete();

        return back()->with('success', 'Stock opname dihapus');
    }
}
