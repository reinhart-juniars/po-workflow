<?php

namespace App\Services\MasterMenu;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Rekonsiliasi data Master Menu Revamp terhadap data PO-Workflow.
 *
 * Dipakai sebagai bahan sesi mapping dengan klien sebelum migrasi, sekaligus
 * cikal bakal Modul Mismatches. Service ini murni membaca — tidak menulis
 * apa pun ke kedua sistem.
 */
class MasterMenuAuditService
{
    /** Skor kemiripan minimum (0-100) supaya sebuah kandidat layak disarankan. */
    protected const SARAN_MIN_SCORE = 55.0;

    public function __construct(protected MasterMenuSource $source) {}

    /**
     * Normalisasi nama menu untuk pencocokan.
     *
     * PO-Workflow menyimpan harga di dalam nama ("NASI CAPJAY 12K") sedangkan
     * Master Menu tidak, jadi token harga dibuang lebih dulu. Simbol dan spasi
     * ganda ikut diratakan supaya "Nasi Ayam Bali + Telur" dan
     * "NASI AYAM BALI TELUR" jatuh ke bentuk yang sama.
     */
    public function normalizeMenu(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));
        $value = preg_replace('/\b\d+\s*k\b/u', ' ', $value);       // suffix harga: 10K, 12 K
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);     // simbol -> spasi
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    /** Normalisasi nama bahan — tanpa pembuangan token harga. */
    public function normalizeIngredient(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * Skor kemiripan dua string ternormalisasi (0-100).
     *
     * Memakai similar_text karena toleran terhadap sisipan/penghapusan kata,
     * yang persis pola perbedaan penamaan antar kedua sistem.
     */
    protected function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return round((float) $percent, 1);
    }

    /**
     * Kandidat paling mirip dari daftar, atau null kalau skornya di bawah ambang.
     *
     * @param  Collection<int, array{key: string, label: string}>  $candidates
     * @return array{label: string|null, score: float}
     */
    protected function bestCandidate(string $needle, Collection $candidates): array
    {
        $bestLabel = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $score = $this->similarity($needle, $candidate['key']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLabel = $candidate['label'];
            }
        }

        return $bestScore >= self::SARAN_MIN_SCORE
            ? ['label' => $bestLabel, 'score' => $bestScore]
            : ['label' => null, 'score' => $bestScore];
    }

    /**
     * Mapping resep (Master Menu) -> produk (PO-Workflow).
     *
     * PO-Workflow adalah induk: produk dipakai oleh ribuan sales actual dan
     * tidak boleh dipindahkan. Resep yang tidak menemukan produk harus
     * diputuskan manual oleh klien.
     */
    public function menuMapping(): Collection
    {
        $productsByExact = collect();
        $productsByNorm = collect();
        $candidates = collect();

        foreach (Product::query()->get(['id', 'sku', 'name', 'active']) as $product) {
            $exact = mb_strtolower(trim((string) $product->name));
            $norm = $this->normalizeMenu($product->name);

            if (! $productsByExact->has($exact)) {
                $productsByExact->put($exact, $product);
            }

            if (! $productsByNorm->has($norm)) {
                $productsByNorm->put($norm, $product);
            }

            $candidates->push(['key' => $norm, 'label' => $product->sku.' — '.$product->name]);
        }

        return collect($this->source->table('recipes')
            ->orderBy('name')
            ->get(['id', 'name', 'jenis', 'kategori', 'target_price']))
            ->map(function ($recipe) use ($productsByExact, $productsByNorm, $candidates) {
                $exact = mb_strtolower(trim((string) $recipe->name));
                $norm = $this->normalizeMenu($recipe->name);

                $match = $productsByExact->get($exact);
                $status = $match ? 'cocok_persis' : null;

                if (! $match) {
                    $match = $productsByNorm->get($norm);
                    $status = $match ? 'cocok_normalisasi' : 'belum_cocok';
                }

                $saran = $match
                    ? ['label' => null, 'score' => 100.0]
                    : $this->bestCandidate($norm, $candidates);

                return [
                    'recipe_id' => (int) $recipe->id,
                    'nama_resep' => (string) $recipe->name,
                    'jenis' => (string) ($recipe->jenis ?? ''),
                    'kategori' => (string) ($recipe->kategori ?? ''),
                    'target_price' => $recipe->target_price !== null ? (float) $recipe->target_price : null,
                    'status' => $status,
                    'product_id' => $match?->id,
                    'product_sku' => $match?->sku,
                    'product_name' => $match?->name,
                    'saran_kandidat' => $saran['label'],
                    'skor_kemiripan' => $saran['score'],
                ];
            })
            ->values();
    }

    /** Produk PO-Workflow yang belum punya pasangan resep — kandidat menu tanpa BOM. */
    public function productsWithoutRecipe(): Collection
    {
        $recipeNorms = collect($this->source->table('recipes')->get(['name']))
            ->map(fn ($r) => $this->normalizeMenu($r->name))
            ->flip();

        return Product::query()
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'base_price', 'raw_material_cost', 'overhead_cost', 'active'])
            ->reject(fn (Product $p) => $recipeNorms->has($this->normalizeMenu($p->name)))
            ->map(fn (Product $p) => [
                'product_id' => $p->id,
                'sku' => $p->sku,
                'nama_produk' => $p->name,
                'harga_jual' => (float) $p->base_price,
                'bahan_baku' => (float) $p->raw_material_cost,
                'overhead' => (float) $p->overhead_cost,
                'aktif' => $p->active ? 'ya' : 'tidak',
            ])
            ->values();
    }

    /**
     * Mapping bahan (ingredients) -> InventoryItem.
     *
     * PO-Workflow saat ini hanya punya bucket agregat (Bahan Baku / Packaging /
     * Inventaris), jadi kolom bucket_tujuan menunjukkan induk yang akan dipakai
     * saat 305 bahan dipecah menjadi item detail di bawahnya.
     */
    public function ingredientMapping(): Collection
    {
        $buckets = InventoryItem::query()->get(['id', 'name', 'category']);
        $itemsByNorm = $buckets->mapWithKeys(
            fn (InventoryItem $i) => [$this->normalizeIngredient($i->name) => $i]
        );

        return collect($this->source->table('ingredients')->orderBy('name')->get())
            ->map(function ($ingredient) use ($itemsByNorm, $buckets) {
                $norm = $this->normalizeIngredient($ingredient->name);
                $kategori = mb_strtolower(trim((string) ($ingredient->category ?? '')));

                $targetCategory = str_contains($kategori, 'kemasan')
                    ? InventoryItem::CATEGORY_PACKAGING
                    : InventoryItem::CATEGORY_RAW_MATERIAL;

                $bucket = $buckets->firstWhere('category', $targetCategory);

                return [
                    'ingredient_id' => (int) $ingredient->id,
                    'nama_bahan' => (string) $ingredient->name,
                    'kategori_sumber' => (string) ($ingredient->category ?? ''),
                    'satuan_pack' => (string) ($ingredient->pack_unit ?? ''),
                    'pack_qty' => (float) $ingredient->pack_qty,
                    'harga_pack' => (float) $ingredient->pack_price,
                    'harga_satuan' => (float) $ingredient->unit_price,
                    'sudah_ada_di_inventory' => $itemsByNorm->has($norm) ? 'ya' : 'tidak',
                    'bucket_tujuan' => $bucket?->name,
                    'kategori_tujuan' => $targetCategory,
                ];
            })
            ->values();
    }

    /**
     * Baris resep yang tidak menunjuk ke ingredient maupun sub-resep ("yatim").
     *
     * Diagregasi per nama mentah supaya menjadi daftar kerja yang bisa
     * diselesaikan manusia — bukan ribuan baris. Selama baris ini masih yatim,
     * HPP berbasis resep belum bisa dipercaya.
     */
    public function orphanRecipeItems(): Collection
    {
        $ingredientCandidates = collect($this->source->table('ingredients')->get(['name']))
            ->map(fn ($i) => ['key' => $this->normalizeIngredient($i->name), 'label' => (string) $i->name]);

        return collect($this->source->table('recipe_items')
            ->whereNull('ingredient_id')
            ->whereNull('ref_recipe_id')
            ->get(['raw_name', 'unit', 'recipe_id']))
            ->groupBy(fn ($row) => $this->normalizeIngredient($row->raw_name))
            ->map(function (Collection $rows, string $norm) use ($ingredientCandidates) {
                $saran = $this->bestCandidate($norm, $ingredientCandidates);

                return [
                    'nama_mentah' => (string) $rows->first()->raw_name,
                    'jumlah_baris' => $rows->count(),
                    'jumlah_resep' => $rows->pluck('recipe_id')->unique()->count(),
                    'satuan' => (string) ($rows->first()->unit ?? ''),
                    'saran_bahan' => $saran['label'],
                    'skor_kemiripan' => $saran['score'],
                ];
            })
            ->sortByDesc('jumlah_baris')
            ->values();
    }

    /** Ringkasan angka kedua sistem — halaman pertama laporan audit. */
    public function summary(): Collection
    {
        $menu = $this->menuMapping();
        $orphans = $this->orphanRecipeItems();
        $counts = $this->source->counts();

        return collect([
            ['metrik' => 'Bahan (ingredients) di Master Menu', 'nilai' => $counts->get('ingredients')],
            ['metrik' => 'Item inventory di PO-Workflow', 'nilai' => InventoryItem::query()->count()],
            ['metrik' => 'Resep di Master Menu', 'nilai' => $counts->get('recipes')],
            ['metrik' => 'Produk di PO-Workflow', 'nilai' => Product::query()->count()],
            ['metrik' => 'Resep cocok persis dengan produk', 'nilai' => $menu->where('status', 'cocok_persis')->count()],
            ['metrik' => 'Resep cocok setelah normalisasi', 'nilai' => $menu->where('status', 'cocok_normalisasi')->count()],
            ['metrik' => 'Resep belum cocok (perlu mapping manual)', 'nilai' => $menu->where('status', 'belum_cocok')->count()],
            ['metrik' => 'Produk tanpa pasangan resep', 'nilai' => $this->productsWithoutRecipe()->count()],
            ['metrik' => 'Baris resep total', 'nilai' => $counts->get('recipe_items')],
            ['metrik' => 'Baris resep yatim (tanpa bahan/sub-resep)', 'nilai' => (int) $orphans->sum('jumlah_baris')],
            ['metrik' => 'Nama bahan mentah unik yang yatim', 'nilai' => $orphans->count()],
            ['metrik' => 'Unmatched items tercatat di Master Menu', 'nilai' => $counts->get('unmatched_items')],
            ['metrik' => 'Riwayat harga bahan', 'nilai' => $counts->get('ingredient_price_history')],
            ['metrik' => 'SPK produksi di Master Menu', 'nilai' => $counts->get('spk')],
            ['metrik' => 'Order di Master Menu', 'nilai' => $counts->get('orders')],
        ]);
    }
}
