<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LeftoverBreakdown;
use App\Models\LeftoverComponent;
use App\Models\LeftoverDisposal;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Services\LeftoverComponentSuggester;
use App\Services\LeftoverStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Barang Sisa di aplikasi Sales: stok retur yang bisa dijual ke customer mana
 * pun ("Penjualan Barang Sisa") atau dibuang bila tidak layak jual.
 */
class LeftoverStockController extends Controller
{
    public function __construct(
        private readonly LeftoverStockService $leftovers,
        private readonly LeftoverComponentSuggester $suggester,
    ) {}

    public function index(): View
    {
        $stock = $this->leftovers->available();
        $breakdowns = $this->leftovers->breakdownRows();

        // Saran rincian dari bagian resep tiap menu (lihat LeftoverComponentSuggester).
        $entries = SalesActualItem::query()
            ->with('product.recipe')
            ->whereIn('id', $stock->pluck('entry_id')->merge($breakdowns->pluck('entry_id'))->filter()->unique())
            ->get()
            ->keyBy('id');
        $stock = $stock->map(fn (array $row) => $row + [
            'suggestions' => ($entry = $entries->get($row['entry_id']))
                ? $this->suggester->perPortion($entry->product, $entry->leftoverUnitCost(), (float) $entry->unit_price)
                : [],
        ]);
        $breakdowns = $breakdowns->map(function (array $row) use ($entries) {
            $entry = $entries->get($row['entry_id']);
            $row['components'] = $row['components']->map(fn (array $component) => $component + [
                'suggested_price' => $entry
                    ? $this->suggester->salePrice((float) $component['unit_cost'], (float) $entry->unit_price, $entry->leftoverUnitCost())
                    : null,
            ]);
            $row['suggestions'] = $entry
                ? $this->suggester->perPortion($entry->product, $entry->leftoverUnitCost(), (float) $entry->unit_price)
                : [];

            return $row;
        });

        // Pilihan customer pembeli: nama dari Master Customer saja (diketik).
        $customerNames = Customer::query()
            ->external()
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values();

        $recentDisposals = LeftoverDisposal::query()
            ->with(['sourceItem.salesActual.customer', 'sourceItem.product', 'component', 'creator:id,name'])
            ->whereDate('disposed_at', '>=', now()->subDays(30)->toDateString())
            ->orderByDesc('disposed_at')
            ->orderByDesc('id')
            ->get();

        return view('salesapp.leftovers', [
            'stock' => $stock,
            'breakdowns' => $breakdowns,
            'customerNames' => $customerNames,
            'recentDisposals' => $recentDisposals,
            'totalQty' => round((float) $stock->sum('available_qty'), 2),
            'totalValue' => round((float) $stock->sum('value') + (float) $breakdowns->sum('available_value'), 2),
        ]);
    }

    /** Jual dari halaman Barang Sisa: ketik customer pembeli dari Master Customer. */
    public function sell(Request $request, SalesActualItem $entry): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'customer_name.required' => 'Ketik nama customer pembeli.',
        ]);

        $salesActual = $this->draftForCustomer($data['customer_name']);

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
        $data = $this->validateDisposal($request);

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

    /** Batal: porsi Barang Sisa ini tidak jadi dibuat (bukan waste). */
    public function cancel(Request $request, SalesActualItem $entry): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'disposed_at' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->leftovers->cancel(
            $entry,
            (float) $data['qty'],
            Carbon::parse($data['disposed_at']),
            $data['reason'] ?? null,
            $request->user()?->id,
        );

        return redirect()
            ->route('salesapp.leftovers.index')
            ->with('success', 'Barang Sisa dicatat batal dibuat; stoknya berkurang tanpa dihitung waste.');
    }

    /**
     * Tambah dari halaman edit Sales Actual. Pilihan Barang Sisa dikirim
     * sebagai "entry:ID" (porsi utuh) atau "component:ID" (komponen rincian).
     */
    public function storeOnActual(Request $request, SalesActual $salesActual): RedirectResponse
    {
        $data = $request->validate([
            'leftover_source' => ['required_without:leftover_entry_id', 'nullable', 'string', 'regex:/^(entry|component):\d+$/'],
            'leftover_entry_id' => ['nullable', 'integer'],
            'leftover_qty' => ['required', 'numeric', 'gt:0'],
            'leftover_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'leftover_source.required_without' => 'Pilih Barang Sisa yang dijual.',
        ]);

        [$kind, $id] = filled($data['leftover_source'] ?? null)
            ? explode(':', $data['leftover_source'])
            : ['entry', $data['leftover_entry_id']];
        $price = isset($data['leftover_price']) ? (float) $data['leftover_price'] : null;

        if ($kind === 'component') {
            if ($price === null) {
                return back()->withInput()->withErrors(['leftover_price' => 'Isi harga jual komponen Barang Sisa.']);
            }

            $this->leftovers->addComponentToSalesActual($salesActual, LeftoverComponent::query()->findOrFail($id), (float) $data['leftover_qty'], $price);
        } else {
            $this->leftovers->addToSalesActual($salesActual, SalesActualItem::query()->findOrFail($id), (float) $data['leftover_qty'], $price);
        }

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Penjualan Barang Sisa ditambahkan.');
    }

    /** Rinci porsi Barang Sisa menjadi komponen yang diketik bebas oleh user. */
    public function breakDown(Request $request, SalesActualItem $entry): RedirectResponse
    {
        $data = $this->validateBreakdown($request, withDate: true);

        $this->leftovers->breakDown(
            $entry,
            (float) $data['portion_qty'],
            $data['components'] ?? [],
            Carbon::parse($data['broken_at']),
            $data['notes'] ?? null,
            $request->user()?->id,
        );

        return redirect()
            ->route('salesapp.leftovers.index')
            ->with('success', 'Barang Sisa dirinci per komponen. Komponennya bisa dijual atau dibuang sendiri-sendiri.');
    }

    public function updateBreakdown(Request $request, LeftoverBreakdown $breakdown): RedirectResponse
    {
        $data = $this->validateBreakdown($request, withDate: false);

        $this->leftovers->updateBreakdown($breakdown, (float) $data['portion_qty'], $data['components'] ?? [], $data['notes'] ?? null);

        return redirect()
            ->route('salesapp.leftovers.index')
            ->with('success', 'Rincian Barang Sisa diperbarui.');
    }

    public function cancelBreakdown(LeftoverBreakdown $breakdown): RedirectResponse
    {
        $this->leftovers->cancelBreakdown($breakdown);

        return redirect()
            ->route('salesapp.leftovers.index')
            ->with('success', 'Rincian dibatalkan; porsinya kembali ke stok Barang Sisa.');
    }

    public function sellComponent(Request $request, LeftoverComponent $component): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ], [
            'customer_name.required' => 'Ketik nama customer pembeli.',
            'unit_price.required' => 'Isi harga jual komponen.',
        ]);

        $salesActual = $this->draftForCustomer($data['customer_name']);

        $this->leftovers->addComponentToSalesActual($salesActual, $component, (float) $data['qty'], (float) $data['unit_price']);

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Komponen Barang Sisa ditambahkan sebagai Penjualan Barang Sisa.');
    }

    public function disposeComponent(Request $request, LeftoverComponent $component): RedirectResponse
    {
        $data = $this->validateDisposal($request);

        $this->leftovers->disposeComponent(
            $component,
            (float) $data['qty'],
            Carbon::parse($data['disposed_at']),
            $data['reason'],
            $request->user()?->id,
        );

        return redirect()
            ->route('salesapp.leftovers.index')
            ->with('success', 'Komponen Barang Sisa dicatat sebagai waste.');
    }

    public function destroyOnActual(SalesActual $salesActual, SalesActualItem $item): RedirectResponse
    {
        abort_unless((int) $item->sales_actual_id === (int) $salesActual->id, 404);

        $this->leftovers->removeFromSalesActual($item);

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Penjualan Barang Sisa dilepas; qty-nya kembali ke stok Barang Sisa.');
    }

    /**
     * Sales Actual draft tujuan untuk customer yang diketik.
     *
     * Customer harus ada di Master Customer (bukan customer internal). Cara
     * bayar Penjualan Barang Sisa diambil dari PO customer itu, jadi tujuannya
     * draft Sales Actual customer tersebut -- yang terbaru bila lebih dari satu.
     */
    private function draftForCustomer(string $name): SalesActual
    {
        $name = trim($name);

        $customerIds = Customer::query()
            ->external()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->pluck('id');

        if ($customerIds->isEmpty()) {
            throw ValidationException::withMessages([
                'customer_name' => "Customer \"{$name}\" tidak ada di Master Customer. Pilih nama dari daftar.",
            ]);
        }

        $salesActual = SalesActual::query()
            ->external()
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'draft')
            ->orderByDesc('sales_date')
            ->orderByDesc('id')
            ->first();

        if (! $salesActual) {
            throw ValidationException::withMessages([
                'customer_name' => "Customer \"{$name}\" belum punya Sales Actual draft. Barang Sisa dijual lewat Sales Actual dari DO customer itu.",
            ]);
        }

        return $salesActual;
    }

    /** @return array<string, mixed> */
    private function validateDisposal(Request $request): array
    {
        return $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'disposed_at' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Alasan wajib diisi supaya pembuangan bisa ditelusuri.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validateBreakdown(Request $request, bool $withDate): array
    {
        return $request->validate([
            'portion_qty' => ['required', 'numeric', 'gt:0'],
            'broken_at' => $withDate ? ['required', 'date', 'before_or_equal:today'] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:255'],
            'components' => ['required', 'array', 'max:20'],
            'components.*.name' => ['nullable', 'string', 'max:100'],
            'components.*.qty' => ['nullable', 'numeric', 'min:0'],
            'components.*.unit' => ['nullable', 'string', 'max:30'],
            'components.*.value' => ['nullable', 'numeric', 'min:0'],
        ], [
            'components.required' => 'Isi minimal satu komponen.',
        ]);
    }
}
