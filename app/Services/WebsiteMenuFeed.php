<?php

namespace App\Services;

use App\Models\Product;
use App\Support\WebsiteCatalog;
use App\Support\WebsitePhotoRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * Bagian C: data menu yang terbit di www.w3scatering.com.
 *
 * Bentuknya per brand -> per kategori -> menu, tidak terikat ke halaman
 * tertentu di website: skrip build website yang menentukan di mana tiap
 * brand ditampilkan. Nama menu di website = SKU; foto diambil lewat URL
 * bertoken dengan versi di query string, supaya foto yang diganti tidak
 * tertahan di cache CDN.
 */
class WebsiteMenuFeed
{
    /** Folder cache foto ukuran website di disk lokal (bukan publik). */
    public const PHOTO_CACHE_DIR = 'website-photos';

    /**
     * @return array{version: string, total: int, brands: array<int, array<string, mixed>>}
     */
    public function build(): array
    {
        $products = Product::query()->onWebsite()
            ->get(['id', 'sku', 'is_3s', 'website_category', 'photo_path'])
            ->sortBy(fn (Product $product) => mb_strtolower((string) $product->sku), SORT_NATURAL)
            ->groupBy('website_category');

        $brands = [];
        foreach (WebsiteCatalog::brands() as $brandKey => $brand) {
            $categories = [];
            foreach (WebsiteCatalog::categoriesFor($brandKey) as $key => $category) {
                $categories[] = [
                    'key' => $key,
                    'title' => $category['title'],
                    'filter' => $category['filter'],
                    'description' => $category['description'],
                    'items' => ($products[$key] ?? collect())->map(fn (Product $product) => [
                        'id' => $product->id,
                        'name' => $product->sku,
                        'photo' => $product->photo_path ? [
                            'version' => $this->photoVersion($product),
                            'url' => route('api.website.menu.photo', ['product' => $product->id, 'v' => $this->photoVersion($product)]),
                        ] : null,
                    ])->values()->all(),
                ];
            }

            $brands[] = [
                'key' => $brandKey,
                'label' => $brand['label'],
                'page' => $brand['page'],
                'count' => array_sum(array_map(fn ($category) => count($category['items']), $categories)),
                'categories' => $categories,
            ];
        }

        return [
            // Sidik isi: berubah hanya bila menu, nama, kategori, atau foto berubah.
            // Dipakai penanda "ada perubahan yang belum diterbitkan".
            'version' => sha1(json_encode($brands)),
            'total' => array_sum(array_column($brands, 'count')),
            'brands' => $brands,
        ];
    }

    public function photoVersion(Product $product): string
    {
        return substr(sha1((string) $product->photo_path), 0, 12);
    }

    /**
     * Foto 560x420 WebP satu menu, dari cache bila sudah pernah dibuat. Nama
     * berkas memuat versi foto, jadi foto yang diganti otomatis dibuat ulang.
     */
    public function photo(Product $product): string
    {
        $disk = Storage::disk('local');
        $cached = self::PHOTO_CACHE_DIR.'/'.$product->id.'-'.$this->photoVersion($product).'.webp';

        if ($disk->exists($cached)) {
            return (string) $disk->get($cached);
        }

        $data = app(WebsitePhotoRenderer::class)->render((string) Storage::disk('public')->get($product->photo_path));

        // Buang versi lama menu ini sebelum menyimpan yang baru.
        foreach ($disk->files(self::PHOTO_CACHE_DIR) as $file) {
            if (str_starts_with(basename($file), $product->id.'-')) {
                $disk->delete($file);
            }
        }
        $disk->put($cached, $data);

        return $data;
    }
}
