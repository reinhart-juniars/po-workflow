<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HPP (Bahan Baku) & OHC produk diturunkan dari resepnya di aplikasi Menu.
 *
 * Revisi 7 Okt 2026: tim menu mengatur resep dan biayanya; admin hanya
 * mengatur harga jual dan melihat profit. Aturannya:
 *
 *   Bahan Baku produk = HPP resep per satuan hasil × faktor satuan produk
 *   OHC produk        = OHC resep per satuan hasil × faktor satuan produk
 *
 * Hanya resep yang hitungannya **bersih** yang boleh menimpa angka produk --
 * definisi yang sama dengan ProfitGuardService: biaya > 0, tanpa bahan belum
 * tertaut, tanpa catatan hitungan. Resep setengah jadi akan menulis HPP yang
 * diam-diam kekecilan; lebih aman produk tetap memakai angka manual admin,
 * dengan alasan yang bisa dibaca, sampai resepnya beres.
 *
 * Yang lama tidak disentuh: sales actual menyimpan snapshot biayanya sendiri,
 * jadi laporan periode yang sudah lewat tidak bergeser.
 */
class ProductRecipeCostSync
{
    /** @var array<int, true> Bahan yang harganya berubah dalam request ini. */
    protected array $pendingIngredients = [];

    /** @var array<int, true> Resep yang header/barisnya berubah dalam request ini. */
    protected array $pendingRecipes = [];

    /** @var array<int, true> Produk yang ditautkan/dilepas dari resep dalam request ini. */
    protected array $pendingProducts = [];

    protected bool $terminatingRegistered = false;

    public function __construct(
        protected RecipeCostService $costs,
        protected IngredientUnitConverter $converter,
    ) {}

    /**
     * Status biaya sebuah produk terhadap resepnya, tanpa menulis apa pun.
     *
     * @return array{state: 'resep'|'manual'|'tertahan', reason: ?string, raw_material_cost: ?float, overhead_cost: ?float}
     */
    public function evaluate(Product $product): array
    {
        $none = fn (string $state, ?string $reason) => ['state' => $state, 'reason' => $reason, 'raw_material_cost' => null, 'overhead_cost' => null];

        $recipe = $product->recipe_id ? ($product->relationLoaded('recipe') ? $product->recipe : $product->recipe()->first()) : null;

        if (! $recipe) {
            return $none(Product::COST_MANUAL, 'Belum tertaut ke resep.');
        }

        if (! $recipe->is_active) {
            return $none('tertahan', 'Resep "'.$recipe->name.'" nonaktif.');
        }

        $cost = $this->costs->cost($recipe);

        if ((float) $cost['total_biaya'] <= 0 || $cost['has_unmatched'] || $cost['issues'] !== []) {
            $count = count($cost['issues']);

            return $none('tertahan', 'Hitungan resep "'.$recipe->name.'" belum lengkap'.($count > 0 ? " ({$count} catatan)" : '').'.');
        }

        // HPP resep per satu satuan hasil; produk bisa dijual dalam satuan
        // lain yang sebesaran (mis. resep per kg, produk per 500 gram).
        $factor = $this->converter->convertByRegistry(1.0, $product->unit, $recipe->yield_unit);

        if ($factor === null) {
            return $none('tertahan', 'Satuan produk "'.$product->unit.'" tidak sepadan dengan satuan hasil resep "'.$recipe->yield_unit.'".');
        }

        return [
            'state' => Product::COST_RECIPE,
            'reason' => null,
            'raw_material_cost' => round((float) $cost['hpp_per_yield'] * $factor, 2),
            'overhead_cost' => round((float) $cost['ohc'] * $factor, 2),
        ];
    }

    /**
     * Terapkan status ke produk. Produk yang tidak lagi mengikuti resep
     * dikembalikan ke manual dengan angka terakhirnya (admin bisa mengubahnya).
     */
    public function syncProduct(Product $product): string
    {
        $result = $this->evaluate($product);

        if ($result['state'] !== Product::COST_RECIPE) {
            if ($product->costFollowsRecipe()) {
                $product->forceFill(['cost_source' => Product::COST_MANUAL])->saveQuietly();
            }

            return $result['state'];
        }

        Product::$historyReason = 'HPP & OHC dari resep "'.$product->recipe?->name.'" (aplikasi Menu).';

        try {
            $product->forceFill([
                'raw_material_cost' => $result['raw_material_cost'],
                'overhead_cost' => $result['overhead_cost'],
                'cost_source' => Product::COST_RECIPE,
                'cost_synced_at' => now(),
            ])->save();
        } finally {
            Product::$historyReason = null;
        }

        return Product::COST_RECIPE;
    }

    /**
     * Sinkronkan semua produk sebuah resep, ditambah resep lain yang memakai
     * resep ini sebagai sub menu (biaya sub menu ikut menentukan HPP-nya).
     *
     * @return array{resep: int, manual: int, tertahan: int}
     */
    public function syncRecipe(Recipe $recipe): array
    {
        return $this->syncRecipeIds($this->withParents([$recipe->id]));
    }

    /**
     * Harga bahan berubah: resep yang memakainya (langsung atau lewat sub
     * menu) dihitung ulang sekali di akhir request, bukan sekali per bahan --
     * Form Kebutuhan bisa memperbarui puluhan harga bahan dalam satu simpan.
     */
    public function queueIngredient(int $ingredientId): void
    {
        $this->pendingIngredients[$ingredientId] = true;
        $this->registerFlush();
    }

    /**
     * Resep berubah (header, baris bahan, aktif/nonaktif). Diantrekan, bukan
     * dihitung saat itu juga: form Filament menyimpan baris bahan sesudah
     * header, jadi hitungan langsung masih membaca baris yang lama.
     */
    public function queueRecipe(int $recipeId): void
    {
        $this->pendingRecipes[$recipeId] = true;
        $this->registerFlush();
    }

    /** Produk ditautkan ke / dilepas dari resep. */
    public function queueProduct(int $productId): void
    {
        $this->pendingProducts[$productId] = true;
        $this->registerFlush();
    }

    /**
     * Proses antrean sekarang. Dipanggil otomatis saat request/perintah
     * selesai (app()->terminating); tes dan perintah memanggilnya langsung.
     *
     * @return array{resep: int, manual: int, tertahan: int}
     */
    public function flush(): array
    {
        $recipes = array_keys($this->pendingRecipes);
        $ingredients = array_keys($this->pendingIngredients);
        $products = array_keys($this->pendingProducts);
        $this->pendingRecipes = [];
        $this->pendingIngredients = [];
        $this->pendingProducts = [];

        if ($ingredients !== []) {
            $recipes = array_merge($recipes, RecipeItem::query()->whereIn('inventory_item_id', $ingredients)->distinct()->pluck('recipe_id')->all());
        }

        $tally = $this->syncRecipeIds($this->withParents(array_values(array_unique($recipes))));

        // Produk yang baru dilepas dari resep tidak terjangkau lewat recipe_id
        // lagi; tanpa ini sumbernya tetap 'resep' dan admin terkunci.
        $done = $recipes === [] ? [] : Product::query()->whereIn('recipe_id', $recipes)->pluck('id')->all();
        $rest = array_diff($products, $done);

        if ($rest !== []) {
            Product::query()->whereKey($rest)->with('recipe')->get()
                ->each(function (Product $product) use (&$tally) {
                    $tally[$this->syncProduct($product)]++;
                });
        }

        return $tally;
    }

    protected function registerFlush(): void
    {
        if (! $this->terminatingRegistered) {
            $this->terminatingRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * Semua produk bertaut resep, mis. setelah deploy atau dari jadwal harian.
     *
     * @return array{resep: int, manual: int, tertahan: int}
     */
    public function syncAll(): array
    {
        $tally = ['resep' => 0, 'manual' => 0, 'tertahan' => 0];

        Product::query()
            ->where(fn ($q) => $q->whereNotNull('recipe_id')->orWhere('cost_source', Product::COST_RECIPE))
            ->with('recipe')
            ->chunkById(200, function (Collection $products) use (&$tally) {
                foreach ($products as $product) {
                    $tally[$this->syncProduct($product)]++;
                }
            });

        return $tally;
    }

    /**
     * @param  list<int>  $recipeIds
     * @return array{resep: int, manual: int, tertahan: int}
     */
    protected function syncRecipeIds(array $recipeIds): array
    {
        $tally = ['resep' => 0, 'manual' => 0, 'tertahan' => 0];

        if ($recipeIds === []) {
            return $tally;
        }

        DB::transaction(function () use ($recipeIds, &$tally) {
            Product::query()->whereIn('recipe_id', $recipeIds)->with('recipe')->get()
                ->each(function (Product $product) use (&$tally) {
                    $tally[$this->syncProduct($product)]++;
                });
        });

        return $tally;
    }

    /**
     * Tambahkan resep induk (yang memakai resep-resep ini sebagai sub menu),
     * berulang sampai tidak ada yang baru. Penjaga putaran: id yang sudah
     * terkumpul tidak diproses lagi.
     *
     * @param  list<int>  $recipeIds
     * @return list<int>
     */
    protected function withParents(array $recipeIds): array
    {
        $all = array_fill_keys($recipeIds, true);
        $frontier = $recipeIds;

        while ($frontier !== []) {
            $parents = RecipeItem::query()->whereIn('ref_recipe_id', $frontier)->distinct()->pluck('recipe_id')->all();
            $frontier = array_values(array_filter($parents, fn ($id) => ! isset($all[$id])));
            foreach ($frontier as $id) {
                $all[$id] = true;
            }
        }

        return array_keys($all);
    }
}
