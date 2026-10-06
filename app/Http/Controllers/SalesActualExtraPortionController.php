<?php

namespace App\Http\Controllers;

use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Services\SalesActualService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Porsi Tambahan di Sales Actual: customer meminta lebih dari yang dikirim
 * (mis. ganti menu 10+5 menjadi 13+2). Ditagih dengan harga PO.
 */
class SalesActualExtraPortionController extends Controller
{
    public function __construct(private readonly SalesActualService $salesActuals) {}

    public function store(Request $request, SalesActual $salesActual): RedirectResponse
    {
        $data = $request->validate([
            'extra_line_id' => ['required', 'integer'],
            'extra_qty' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ], [
            'extra_line_id.required' => 'Pilih menu PO untuk Porsi Tambahan.',
        ]);

        // Baris acuan harus milik Sales Actual ini; id lain diperlakukan sama dengan tidak ada.
        $poLine = $salesActual->items()
            ->whereKey($data['extra_line_id'])
            ->whereNotNull('purchase_order_item_id')
            ->first();

        if (! $poLine) {
            return back()->withInput()->withErrors(['extra_line_id' => 'Pilih menu dari PO di Sales Actual ini.']);
        }

        $this->salesActuals->addExtraPortion($salesActual, $poLine, (float) $data['extra_qty'], $request->ip());

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Porsi Tambahan ditambahkan dengan harga PO.');
    }

    public function destroy(Request $request, SalesActual $salesActual, SalesActualItem $item): RedirectResponse
    {
        abort_unless((int) $item->sales_actual_id === (int) $salesActual->id, 404);

        $this->salesActuals->removeExtraPortion($item, $request->ip());

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Porsi Tambahan dihapus.');
    }
}
