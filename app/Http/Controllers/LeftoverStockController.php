<?php

namespace App\Http\Controllers;

use App\Models\LeftoverDisposal;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Services\LeftoverStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Barang Sisa di aplikasi Sales: stok retur yang bisa dijual ke customer mana
 * pun ("Penjualan Barang Sisa") atau dibuang bila tidak layak jual.
 */
class LeftoverStockController extends Controller
{
    public function __construct(private readonly LeftoverStockService $leftovers) {}

    public function index(): View
    {
        $stock = $this->leftovers->available();

        $draftActuals = SalesActual::query()
            ->with(['customer:id,name', 'deliveryOrder:id,do_code'])
            ->where('status', 'draft')
            ->orderByDesc('sales_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'customer_id', 'delivery_order_id', 'sales_date']);

        $recentDisposals = LeftoverDisposal::query()
            ->with(['sourceItem.salesActual.customer', 'sourceItem.product', 'creator:id,name'])
            ->whereDate('disposed_at', '>=', now()->subDays(30)->toDateString())
            ->orderByDesc('disposed_at')
            ->orderByDesc('id')
            ->get();

        return view('salesapp.leftovers', [
            'stock' => $stock,
            'draftActuals' => $draftActuals,
            'recentDisposals' => $recentDisposals,
            'totalQty' => round((float) $stock->sum('available_qty'), 2),
            'totalValue' => round((float) $stock->sum('value'), 2),
        ]);
    }

    /** Jual dari halaman Barang Sisa: pilih Sales Actual draft tujuan. */
    public function sell(Request $request, SalesActualItem $entry): RedirectResponse
    {
        $data = $request->validate([
            'sales_actual_id' => ['required', 'integer', Rule::exists('sales_actuals', 'id')->where('status', 'draft')],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'sales_actual_id.exists' => 'Sales Actual tujuan harus yang masih draft.',
        ]);

        $salesActual = SalesActual::query()->findOrFail($data['sales_actual_id']);

        $this->leftovers->addToSalesActual(
            $salesActual,
            $entry,
            (float) $data['qty'],
            isset($data['unit_price']) ? (float) $data['unit_price'] : null,
        );

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Barang Sisa ditambahkan sebagai Penjualan Barang Sisa. Isi qty terjual lalu submit seperti biasa.');
    }

    public function dispose(Request $request, SalesActualItem $entry): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'disposed_at' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Alasan wajib diisi supaya pembuangan bisa ditelusuri.',
        ]);

        $this->leftovers->dispose(
            $entry,
            (float) $data['qty'],
            Carbon::parse($data['disposed_at']),
            $data['reason'],
            $request->user()?->id,
        );

        return redirect()
            ->route('salesapp.leftovers.index')
            ->with('success', 'Barang Sisa dicatat sebagai dibuang.');
    }

    /** Tambah dari halaman edit Sales Actual (penjualan menu hari ini). */
    public function storeOnActual(Request $request, SalesActual $salesActual): RedirectResponse
    {
        $data = $request->validate([
            'leftover_entry_id' => ['required', 'integer', Rule::exists('sales_actual_items', 'id')],
            'leftover_qty' => ['required', 'numeric', 'gt:0'],
            'leftover_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'leftover_entry_id.required' => 'Pilih Barang Sisa yang dijual.',
        ]);

        $this->leftovers->addToSalesActual(
            $salesActual,
            SalesActualItem::query()->findOrFail($data['leftover_entry_id']),
            (float) $data['leftover_qty'],
            isset($data['leftover_price']) ? (float) $data['leftover_price'] : null,
        );

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Penjualan Barang Sisa ditambahkan.');
    }

    public function destroyOnActual(SalesActual $salesActual, SalesActualItem $item): RedirectResponse
    {
        abort_unless((int) $item->sales_actual_id === (int) $salesActual->id, 404);

        $this->leftovers->removeFromSalesActual($item);

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Penjualan Barang Sisa dilepas; qty-nya kembali ke stok Barang Sisa.');
    }
}
