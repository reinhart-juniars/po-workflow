<?php

namespace App\Support;

use App\Models\Product;

/**
 * Bagian C: kategori menu website (config/website.php) dan brand tiap menu.
 *
 * Brand diturunkan dari is_3s, bukan disimpan terpisah, supaya menu yang
 * dipindah brand-nya di Master Menu tidak tersangkut di halaman lama: kategori
 * yang tidak cocok dengan brand-nya dianggap kosong (tidak terbit).
 */
final class WebsiteCatalog
{
    public const BRAND_WWP = 'wwp';

    public const BRAND_3S = '3s';

    /** @return array<string, array{brand: string, title: string, filter: string, description: string}> */
    public static function categories(): array
    {
        return config('website.categories', []);
    }

    /** @return array<string, array{brand: string, title: string, filter: string, description: string}> */
    public static function categoriesFor(string $brand): array
    {
        return array_filter(self::categories(), fn (array $category) => $category['brand'] === $brand);
    }

    /** @return array<string, array{label: string, page: string}> */
    public static function brands(): array
    {
        return config('website.brands', []);
    }

    public static function brandOf(Product $product): string
    {
        return $product->is_3s ? self::BRAND_3S : self::BRAND_WWP;
    }

    /** Kategori boleh dipakai menu ini: ada di config dan milik brand menu tersebut. */
    public static function fits(Product $product, ?string $category): bool
    {
        return $category !== null && array_key_exists($category, self::categoriesFor(self::brandOf($product)));
    }

    public static function title(?string $category): ?string
    {
        return $category === null ? null : (self::categories()[$category]['title'] ?? null);
    }
}
