<?php

namespace App\Services;

use App\Models\MenuNameAlias;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Mencocokkan baris Excel rencana masak ("Nasi Goreng Crabstik", 10.000) ke
 * produk Master Menu ("NASI GORENG CRABSTIK 10K").
 *
 * Produk di 3S ONE adalah varian harga, jadi harga ikut menentukan: nama dasar
 * (tanpa akhiran "12K") harus sama DAN harganya sama. Urutan:
 *   1. alias yang pernah dipilih manual (nama + harga);
 *   2. nama dasar + harga sama persis (setelah ejaan dirapikan);
 *   3. tidak cocok -> kandidat terdekat untuk dipilih manual.
 * Bila beberapa produk sama-sama cocok, yang punya resep didahulukan, karena
 * hanya menu berresep yang bisa didistribusikan dari stok siap.
 */
class ReadyStockPlanMatcher
{
    /** @var Collection<int, array{product: Product, base: string, price: ?float}>|null */
    private ?Collection $index = null;

    /**
     * @return array{product_id: ?int, via: 'alias'|'nama'|null, candidates: list<array{id: int, name: string, has_recipe: bool}>}
     */
    public function match(string $name, ?float $price): array
    {
        $norm = self::normalize($name);

        $alias = MenuNameAlias::query()->where('name_norm', $norm)->where('price', $price)->with('product')->first();
        if ($alias?->product?->active) {
            return ['product_id' => $alias->product_id, 'via' => 'alias', 'candidates' => []];
        }

        $exact = $this->index()
            ->filter(fn (array $row) => $row['base'] === $norm && ($price === null || $row['price'] === null || abs($row['price'] - $price) < 0.5))
            ->sortByDesc(fn (array $row) => $row['product']->recipe_id !== null)
            ->first();

        if ($exact) {
            return ['product_id' => $exact['product']->id, 'via' => 'nama', 'candidates' => []];
        }

        return ['product_id' => null, 'via' => null, 'candidates' => $this->candidates($norm, $price)];
    }

    /** Simpan pilihan manual supaya impor berikutnya otomatis cocok. */
    public function remember(string $name, ?float $price, int $productId, ?int $userId = null): void
    {
        MenuNameAlias::query()->updateOrCreate(
            ['name_norm' => self::normalize($name), 'price' => $price],
            ['product_id' => $productId, 'created_by' => $userId],
        );
    }

    /**
     * Ejaan dirapikan supaya "Saos"/"Saus", "Sambel"/"Sambal", "Cap Cay"/"Capcay",
     * "Gado Gado"/"Gado-Gado" dianggap sama; catatan dalam kurung dibuang.
     */
    public static function normalize(string $name): string
    {
        $text = mb_strtoupper($name);
        $text = preg_replace('/\([^)]*\)/u', ' ', $text);
        $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
        $text = ' '.trim(preg_replace('/\s+/', ' ', $text)).' ';
        $text = strtr($text, [
            ' SAOS ' => ' SAUS ',
            ' SAMBEL ' => ' SAMBAL ',
            ' CAP CAY ' => ' CAPCAY ',
            ' CHIC ' => ' CHICKEN ',
            ' RICE BOWL ' => ' RICEBOWL ',
            ' DORY ' => ' DORI ',
            ' KRISPI ' => ' CRISPY ',
            ' HK ' => ' HONGKONG ',
            ' CAPJAY ' => ' CAPCAY ',
            ' KERENGSENGAN ' => ' KRENGSENGAN ',
        ]);

        return trim($text);
    }

    /** @return Collection<int, array{product: Product, base: string, price: ?float}> */
    private function index(): Collection
    {
        return $this->index ??= Product::query()
            ->where('active', true)
            ->get(['id', 'name', 'base_price', 'recipe_id', 'active'])
            ->map(function (Product $product) {
                $norm = self::normalize($product->name);
                // Akhiran harga di nama: "12K", "22 5K" (22.5K), "35RB".
                $price = null;
                if (preg_match('/\s(\d+)(?:\s(\d))?\s?(K|RB)$/', $norm, $m)) {
                    $price = ((float) $m[1] + (isset($m[2]) && $m[2] !== '' ? (float) $m[2] / 10 : 0)) * 1000;
                    $norm = trim(substr($norm, 0, -strlen($m[0])));
                }

                return ['product' => $product, 'base' => $norm, 'price' => $price ?? (float) $product->base_price];
            });
    }

    /** @return list<array{id: int, name: string, has_recipe: bool}> */
    private function candidates(string $norm, ?float $price): array
    {
        return $this->index()
            ->map(function (array $row) use ($norm, $price) {
                similar_text($norm, $row['base'], $percent);
                // Harga sama memberi dorongan kecil: varian yang sama lebih mungkin benar.
                $score = $percent + ($price !== null && $row['price'] !== null && abs($row['price'] - $price) < 0.5 ? 8 : 0);

                return $row + ['score' => $score];
            })
            ->filter(fn (array $row) => $row['score'] >= 60)
            ->sortByDesc('score')
            ->take(5)
            ->map(fn (array $row) => ['id' => $row['product']->id, 'name' => $row['product']->name, 'has_recipe' => $row['product']->recipe_id !== null])
            ->values()
            ->all();
    }
}
