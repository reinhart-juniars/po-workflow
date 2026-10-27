<?php

namespace App\Services;

use App\Models\Product;
use App\Support\WebsiteCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Usulan pasangan hidangan website -> produk Master Menu (Bagian C).
 *
 * Website menampilkan hidangan ("Nasi Ayam Teriyaki"), Master Menu menyimpan
 * varian harga ("NASI AYAM TERIYAKI 12K", "... 18K"). Satu hidangan diwakili
 * satu produk, yang SKU-nya menjadi nama di website. Status usulan:
 *
 *   cocok  : tepat satu produk aktif bernama dasar sama
 *   varian : beberapa varian bernama dasar sama -> diusulkan yang paling sering dipesan
 *   mirip  : tidak ada yang sama persis, ada kandidat bernama mirip
 *   belum  : tidak ada kandidat; menunya belum ada di Master Menu
 */
class WebsiteMenuMapper
{
    public const STATUS_LABELS = [
        'cocok' => 'Cocok',
        'varian' => 'Pilih varian',
        'mirip' => 'Perlu dipilih',
        'belum' => 'Belum ada di Master Menu',
    ];

    /** Skor kemiripan minimal (0-100) untuk kandidat. */
    private const CANDIDATE_THRESHOLD = 60;

    /**
     * @param  list<array{page: string, brand: string, group: string, category: ?string, name: string, photo: ?string, order: int}>  $dishes
     * @return list<array<string, mixed>> hidangan + status + usulan + kandidat
     */
    public function suggest(array $dishes): array
    {
        $products = $this->productIndex();

        return array_map(function (array $dish) use ($products) {
            $base = ReadyStockPlanMatcher::normalize($dish['name']);
            $same = $products->filter(fn (array $row) => $row['base'] === $base)
                ->sortByDesc('orders')->values();

            if ($same->isNotEmpty()) {
                $status = $same->count() === 1 ? 'cocok' : 'varian';
                $candidates = $same->map(fn (array $row) => $row + ['score' => 100]);
            } else {
                $candidates = $products
                    ->map(function (array $row) use ($base) {
                        similar_text($base, $row['base'], $percent);

                        return $row + ['score' => (int) round($percent)];
                    })
                    ->filter(fn (array $row) => $row['score'] >= self::CANDIDATE_THRESHOLD)
                    ->sortByDesc(fn (array $row) => [$row['score'], $row['orders']])
                    ->values();
                $status = $candidates->isEmpty() ? 'belum' : 'mirip';
            }

            $candidates = $candidates->take(5)->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'brand' => $row['brand'],
                'orders' => $row['orders'],
                'score' => $row['score'],
            ])->all();

            return $dish + [
                'status' => $status,
                'suggested' => in_array($status, ['cocok', 'varian'], true) ? $candidates[0] : null,
                'candidates' => $candidates,
            ];
        }, $dishes);
    }

    /**
     * Produk aktif dengan nama dasar (tanpa akhiran harga "12K") dan jumlah
     * baris PO-nya, untuk memilih varian yang paling mewakili hidangan.
     *
     * @return Collection<int, array{id: int, name: string, sku: ?string, brand: string, base: string, orders: int}>
     */
    public function productIndex(): Collection
    {
        $orders = DB::table('purchase_order_items')
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->pluck(DB::raw('COUNT(*)'), 'product_id');

        return Product::query()
            ->where('active', true)
            ->get(['id', 'name', 'sku', 'is_3s'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'brand' => WebsiteCatalog::brandOf($product),
                'base' => self::baseName($product->name),
                'orders' => (int) ($orders[$product->id] ?? 0),
            ]);
    }

    /** Nama dasar produk: dirapikan, akhiran harga ("12K", "22 5K", "35RB") dibuang. */
    public static function baseName(string $name): string
    {
        $norm = ReadyStockPlanMatcher::normalize($name);

        return trim((string) preg_replace('/\s\d+(?:\s\d)?\s?(K|RB)$/', '', $norm));
    }
}
