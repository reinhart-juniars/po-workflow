<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Bagian B.4 -- Katalog Foto Menu berbasis SKU.
 *
 * Satu halaman untuk tim marketing: tiap menu tampil dengan SKU, harga, dan
 * foto resminya. Admin/Owner mengunggah atau mengganti foto (dari aplikasi
 * Admin); Sales hanya melihat dan mengunduh. Foto disimpan di disk `public`
 * dengan nama = SKU, sehingga tautannya stabil dan bisa dipakai website
 * (Bagian C) tanpa tabel tambahan.
 */
class MenuCatalogController extends Controller
{
    public const DISK = 'public';

    public const DIR = 'menu-photos';

    public function index(Request $request, string $app): View
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('foto', 'semua'); // semua | ada | belum
        $activeOnly = $request->boolean('aktif', true);

        $products = Product::query()
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$q.'%')
                ->orWhere('sku', 'like', '%'.$q.'%')))
            ->when($filter === 'ada', fn ($query) => $query->whereNotNull('photo_path'))
            ->when($filter === 'belum', fn ($query) => $query->whereNull('photo_path'))
            ->orderBy('name')
            ->paginate(48)
            ->withQueryString();

        $summary = [
            'total' => Product::query()->where('active', true)->count(),
            'with_photo' => Product::query()->where('active', true)->whereNotNull('photo_path')->count(),
        ];

        return view('catalog.index', [
            'app' => $app,
            'layout' => $app === 'sales' ? 'layouts.salesapp' : 'layouts.adminapp',
            'canManage' => $app === 'admin',
            'products' => $products,
            'summary' => $summary,
            'q' => $q,
            'filter' => $filter,
            'activeOnly' => $activeOnly,
        ]);
    }

    public function upload(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'photo.max' => 'Ukuran foto maksimal 5 MB.',
            'photo.mimes' => 'Foto harus JPG, PNG, atau WEBP.',
        ]);

        $old = $product->photo_path;
        $ext = strtolower($request->file('photo')->getClientOriginalExtension() ?: 'jpg');
        // Nama berkas = SKU + waktu, supaya foto lama tidak tertimpa cache browser.
        $name = ($product->sku ?: 'menu-'.$product->id).'-'.now()->format('YmdHis').'.'.$ext;
        $path = $request->file('photo')->storeAs(self::DIR, $name, self::DISK);

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
