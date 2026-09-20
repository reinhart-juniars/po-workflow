<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Collection;

/**
 * Usulan resep untuk produk yang belum ditautkan.
 *
 * Master produk dan resep ditulis oleh orang berbeda: produk memuat harga di
 * namanya ("NASI GORENG SOLARIA 12K"), resep tidak ("Nasi Goreng Ala Solaria").
 * Kelas ini hanya memberi usulan beserta skornya -- keputusan tetap manusia,
 * karena skor 85% bisa berarti menu yang sama atau menu lain yang kebetulan
 * mirip namanya (Nasi Campur Telur vs Nasi Campur Telur Bali).
 */
class MenuMatchSuggester
{
    /** Di bawah ini usulan lebih sering menyesatkan daripada membantu. */
    public const MIN_SCORE = 60.0;

    /**
     * Ejaan yang berbeda antara kedua daftar tetapi maksudnya sama.
     * Kunci sudah ternormalisasi (huruf kecil, tanpa simbol).
     */
    protected const SYNONYMS = [
        'mihun' => 'bihun',
        'rb' => 'rice bowl',
        'spagethi' => 'spageti',
        'spaghetti' => 'spageti',
        'ala' => '',
    ];

    /** @var Collection<int, array{recipe: Recipe, key: string}>|null */
    protected ?Collection $candidates = null;

    /**
     * Nama ternormalisasi: huruf kecil, tanpa token harga (10K, 22.5K), tanpa
     * simbol, sinonim diseragamkan, spasi ganda diratakan.
     */
    public function normalize(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));
        $value = preg_replace('/\b\d+([.,]\d+)?\s*k\b/u', ' ', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $value);

        $tokens = array_filter(explode(' ', (string) $value), fn ($t) => $t !== '');
        $tokens = array_map(fn ($t) => self::SYNONYMS[$t] ?? $t, $tokens);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $tokens)));
    }

    /**
     * Skor kemiripan 0-100. Gabungan dua ukuran: similar_text (toleran sisipan
     * kata) dan irisan token (toleran urutan kata berbeda) -- diambil yang
     * tertinggi supaya "Telur Goreng Nasi" tetap dikenali sebagai "Nasi Goreng Telur".
     */
    public function score(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $percent);

        $ta = array_unique(explode(' ', $a));
        $tb = array_unique(explode(' ', $b));
        $jaccard = count(array_intersect($ta, $tb)) / max(count(array_unique(array_merge($ta, $tb))), 1) * 100;

        return round(max((float) $percent, $jaccard), 1);
    }

    /**
     * Resep utama aktif yang menjadi kandidat, dimuat sekali per instance
     * karena halaman pencocokan memanggil suggest() untuk tiap baris.
     *
     * @return Collection<int, array{recipe: Recipe, key: string}>
     */
    public function candidates(): Collection
    {
        return $this->candidates ??= Recipe::query()
            ->utama()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Recipe $recipe) => ['recipe' => $recipe, 'key' => $this->normalize($recipe->name)])
            ->values();
    }

    /**
     * Resep paling mirip untuk sebuah produk, atau null bila di bawah ambang.
     *
     * @return array{recipe: Recipe, score: float}|null
     */
    public function suggest(Product|string $product): ?array
    {
        $needle = $this->normalize($product instanceof Product ? $product->name : $product);
        $best = null;
        $bestScore = 0.0;

        foreach ($this->candidates() as $candidate) {
            $score = $this->score($needle, $candidate['key']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate['recipe'];
            }
        }

        return $best !== null && $bestScore >= self::MIN_SCORE
            ? ['recipe' => $best, 'score' => $bestScore]
            : null;
    }
}
