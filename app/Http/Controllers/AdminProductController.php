<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Filament\Menu\Resources\MasterMenuResource;
use App\Filament\Menu\Resources\RecipeResource;
use App\Models\Product;
use App\Services\ProductRecipeCostSync;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Admin App › Harga Menu.
 *
 * Sejak 9 Nov 2026 menu dibuat dan diatur tim menu di aplikasi Menu (Master
 * Menu, lihat MasterMenuSync). Admin tidak lagi membuat menu atau mengimpor
 * Excel; di sini admin hanya mengisi Harga Non Lapak. Harga lapak, HPP, nama,
 * SKU, dan status aktif hanya ditampilkan.
 */
class AdminProductController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $priceMissing = $request->query('harga') === 'kosong';

        // Harga Menu = menu yang bisa dipesan di PO: Menu Utama tim menu (punya
        // resep) atau ditandai tim menu memang tanpa resep (Product::scopeOrderable).
        // Sisa V2 yang belum tertaut tidak ditampilkan sama sekali.
        $products = Product::query()
            ->orderable()
            ->with(['recipe' => fn ($query) => $query->select('id', 'name', 'target_price')->withCount(Product::activeProductsCount())])
            ->withMax('priceHistories as price_last_changed_at', 'effective_from')
            ->withCount('priceHistories')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like);
                });
            })
            ->when($priceMissing, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('base_price')->orWhere('base_price', '<=', 0)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $priceMissingCount = Product::query()
            ->orderable()
            ->where(fn ($inner) => $inner->whereNull('base_price')->orWhere('base_price', '<=', 0))
            ->count();

        return view('adminapp.products.index', compact('products', 'q', 'priceMissing', 'priceMissingCount'));
    }

    public function edit(Product $product, ProductRecipeCostSync $costs)
    {
        $product->load(['priceHistories.changedBy', 'recipe']);
        $user = auth()->user();

        return view('adminapp.products.form', [
            'product' => $product,
            // Asal HPP & OHC: dari resep (terkunci) atau manual, dengan alasan
            // bila resepnya belum bisa dipakai.
            'costStatus' => $costs->evaluate($product),
            'recipeUrl' => $product->recipe_id && $user?->can('recipe.view')
                ? RecipeResource::getUrl('hpp', ['record' => $product->recipe_id])
                : null,
            'masterMenuUrl' => $user?->can('recipe.manage')
                ? MasterMenuResource::getUrl('edit', ['record' => $product])
                : null,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        // Hanya Harga Non Lapak: kolom lain milik tim menu dan diabaikan
        // walau ikut terkirim dari form yang dirakit sendiri.
        $data = $request->validate([
            'base_price' => ['required', 'numeric', 'min:0'],
        ]);

        $product->update(['base_price' => $data['base_price']]);

        return redirect()
            ->route('adminapp.products.index')
            ->with('status', 'Harga '.$product->name.' disimpan.');
    }

    public function exportExcel()
    {
        $fileName = 'harga_menu_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(
            new ProductsExport(Product::query()->orderable()->orderBy('name')->get()),
            $fileName
        );
    }
}
