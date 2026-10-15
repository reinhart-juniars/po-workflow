<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Services\WebsitePublisher;
use App\Support\MenuPhotoProcessor;
use App\Support\WebsiteCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Bagian B.4 -- Katalog Foto Menu berbasis SKU, di aplikasi Marketing.
 *
 * Tim marketing (dan owner) mengelola foto resmi tiap menu dan mencentang
 * menu mana yang tampil di website. SKU adalah nama menu di website, jadi
 * menu tanpa SKU tidak bisa dicentang. Foto disimpan di disk `public`
 * dengan nama berawalan SKU, sehingga website (Bagian C) cukup membaca
 * Product::onWebsite() tanpa tabel tambahan.
 */
class MenuCatalogController extends Controller
{
    public const DISK = 'public';

    public const DIR = 'menu-photos';

    public function dashboard(): View
    {
        $active = fn () => Product::query()->where('active', true);

        return view('marketingapp.dashboard', [
            'stats' => [
                'total' => $active()->count(),
                'with_photo' => $active()->whereNotNull('photo_path')->count(),
                'on_website' => Product::query()->onWebsite()->count(),
                'website_without_photo' => Product::query()->onWebsite()->whereNull('photo_path')->count(),
            ],
            'needsPhoto' => Product::query()->onWebsite()->whereNull('photo_path')->orderBy('name')->limit(10)->get(),
            'websiteStatus' => app(WebsitePublisher::class)->status(),
        ]);
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('foto', 'semua'); // semua | ada | belum
        $website = (string) $request->query('website', 'semua'); // semua | tampil | tidak
        $activeOnly = $request->boolean('aktif', true);

        $products = Product::query()
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$q.'%')
                ->orWhere('sku', 'like', '%'.$q.'%')))
            ->when($filter === 'ada', fn ($query) => $query->whereNotNull('photo_path'))
            ->when($filter === 'belum', fn ($query) => $query->whereNull('photo_path'))
            ->when($website === 'tampil', fn ($query) => $query->where('show_on_website', true))
            ->when($website === 'tidak', fn ($query) => $query->where('show_on_website', false))
            // Dicentang tapi belum bisa terbit: kategori kosong atau bukan milik brand-nya.
            ->when($website === 'tanpa-kategori', fn ($query) => $query->where('show_on_website', true)
                ->whereNotIn('id', Product::query()->onWebsite()->select('id')))
            ->orderBy('name')
            ->paginate(48)
            ->withQueryString();

        $summary = [
            'total' => Product::query()->where('active', true)->count(),
            'with_photo' => Product::query()->where('active', true)->whereNotNull('photo_path')->count(),
            'on_website' => Product::query()->onWebsite()->count(),
        ];

        return view('catalog.index', [
            'products' => $products,
            'summary' => $summary,
            'q' => $q,
            'filter' => $filter,
            'website' => $website,
            'activeOnly' => $activeOnly,
            'websiteStatus' => app(WebsitePublisher::class)->status(),
        ]);
    }

    /**
     * Ganti SKU (= nama menu di website) langsung dari kartu katalog. Aturannya
     * sama dengan Master Menu; audit log dan lonceng ke admin dikirim oleh
     * hook model (SkuChangeNotifier), jadi jalurnya tidak perlu tahu.
     */
    public function updateSku(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $request->merge(['sku' => Product::normalizeSku($request->input('sku'))]);
        $validation = Product::skuValidation($product->id);
        $sku = $request->validate($validation['rules'], $validation['messages'])['sku'];

        $product->update(['sku' => $sku]);

        $message = 'SKU '.$product->name.' sekarang: '.$product->sku.'.';

        if ($request->expectsJson()) {
            return response()->json(['sku' => $product->sku, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    /**
     * Centang "Tampil di website" dan kategori websitenya (Menu Ayam, Frozen Food, ...)
     * untuk satu menu. Kategori yang ditawarkan hanya milik brand menu itu (is_3s).
     */
    public function updateWebsite(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $brand = WebsiteCatalog::brandOf($product);
        $data = $request->validate([
            'show_on_website' => ['required', 'boolean'],
            'website_category' => ['nullable', 'string', Rule::in(array_keys(WebsiteCatalog::categoriesFor($brand)))],
        ], [
            'website_category.in' => 'Kategori itu bukan milik halaman '.WebsiteCatalog::brands()[$brand]['label'].'.',
        ]);
        $show = filter_var($data['show_on_website'], FILTER_VALIDATE_BOOLEAN);
        // Form lama (tanpa pilihan kategori) tidak boleh menghapus kategori yang sudah ada.
        $category = $request->has('website_category') ? ($data['website_category'] ?? null) : $product->website_category;

        // SKU = nama menu di website; tanpa SKU menunya tidak punya nama untuk tampil.
        if ($show && blank($product->sku)) {
            throw ValidationException::withMessages([
                'show_on_website' => 'Menu '.$product->name.' belum punya SKU. Isi SKU di Master Menu dulu -- SKU dipakai sebagai nama menu di website.',
            ]);
        }

        if ($show && ! WebsiteCatalog::fits($product, $category)) {
            throw ValidationException::withMessages([
                'show_on_website' => 'Pilih kategori website untuk '.$product->sku.' dulu -- kategori menentukan letak menunya di halaman website.',
            ]);
        }

        $showChanged = $product->show_on_website !== $show;
        $categoryChanged = $product->website_category !== $category;

        if ($showChanged || $categoryChanged) {
            $product->forceFill(['show_on_website' => $show, 'website_category' => $category])->save();

            AuditLog::create([
                'user_id' => Auth::id(),
                'entity' => 'product',
                'entity_id' => $product->id,
                'action' => $showChanged ? ($show ? 'website_shown' : 'website_hidden') : 'website_category_changed',
                'message' => 'Menu '.$product->name.' ('.$product->sku.') '
                    .($show ? 'tampil di' : 'tidak tampil di').' website'
                    .($category ? ', kategori '.WebsiteCatalog::title($category) : ', tanpa kategori'),
                'ip_address' => $request->ip(),
            ]);
        }

        $message = $product->sku.($show ? ' tampil di website ('.WebsiteCatalog::title($category).').' : ' tidak tampil di website.');

        if ($request->expectsJson()) {
            return response()->json([
                'show_on_website' => $show,
                'website_category' => $category,
                'message' => $message,
                'on_website' => Product::query()->onWebsite()->count(),
            ]);
        }

        return back()->with('success', $message);
    }

    public function upload(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'photo.max' => 'Ukuran foto maksimal 5 MB (foto dikompres otomatis setelah diunggah).',
            'photo.mimes' => 'Foto harus JPG, PNG, atau WEBP.',
        ]);

        // Kompres di server (maks. sisi 1600 px, JPEG) supaya disk server dan
        // halaman katalog tetap ringan walau yang diunggah foto HP 5 MB.
        try {
            $photo = app(MenuPhotoProcessor::class)->process((string) file_get_contents($request->file('photo')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        $old = $product->photo_path;
        // Nama berkas = SKU + waktu + akhiran acak, supaya URL selalu baru (tidak
        // tertimpa cache browser) walau diganti dua kali dalam detik yang sama.
        $name = (Str::slug((string) $product->sku) ?: 'menu-'.$product->id).'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.jpg';
        $path = self::DIR.'/'.$name;
        Storage::disk(self::DISK)->put($path, $photo['data']);

        $product->forceFill(['photo_path' => $path, 'photo_updated_at' => now()])->save();

        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'product',
            'entity_id' => $product->id,
            'action' => 'photo_uploaded',
            'message' => 'Foto menu '.$product->name.' ('.$product->sku.') '.($old ? 'diganti' : 'diunggah'),
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'Foto '.$product->name.' tersimpan.');
    }

    public function destroyPhoto(Request $request, Product $product): RedirectResponse
    {
        if ($product->photo_path) {
            Storage::disk(self::DISK)->delete($product->photo_path);
            $product->forceFill(['photo_path' => null, 'photo_updated_at' => null])->save();

            AuditLog::create([
                'user_id' => Auth::id(),
                'entity' => 'product',
                'entity_id' => $product->id,
                'action' => 'photo_removed',
                'message' => 'Foto menu '.$product->name.' ('.$product->sku.') dihapus',
                'ip_address' => $request->ip(),
            ]);
        }

        return back()->with('success', 'Foto '.$product->name.' dihapus.');
    }
}
