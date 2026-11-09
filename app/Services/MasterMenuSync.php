<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Master Menu = Menu Utama tim menu (revisi 9 Nov 2026).
 *
 * Satu Menu Utama punya tepat satu menu jual (origin `menu`) yang namanya sama
 * dengan resepnya. Hanya menu itu yang tampil di Admin App › Harga Menu dan
 * bisa dipesan di PO (Product::scopeOrderable). Admin hanya mengisi Harga Non
 * Lapak; nama mengikuti resep -- tidak ada lagi nama V2 berakhiran harga
 * seperti "NASI KOLOKE 15K".
 *
 * Menu jual sebuah resep dipilih begini:
 * 1. sudah ada menu ber-origin `menu` → dipakai, namanya disamakan;
 * 2. resep sudah tertaut ke menu V2 → yang paling sering dipesan 90 hari
 *    terakhir dijadikan menu jualnya (harga, foto, SKU website terbawa) dan
 *    diganti nama; varian lain (10K/12K) dinonaktifkan;
 * 3. ada menu V2 tanpa resep yang namanya sama → ditautkan lalu dipakai;
 * 4. selain itu dibuatkan menu baru berharga non lapak 0, admin diberi lonceng.
 */
class MasterMenuSync
{
    public const DEFAULT_UNIT = 'PORSI';

    public const USAGE_DAYS = 90;

    /** @var array<string, int> hitungan aksi syncAll() */
    protected array $tally = [];

    /** Selama syncAll(): lonceng per menu ditahan, diganti satu ringkasan. */
    protected bool $quiet = false;

    /**
     * Pastikan Menu Utama ini punya menu jual dan kembalikan menu itu.
     * Sub-menu dilewati.
     */
    public function ensureFor(Recipe $recipe): ?Product
    {
        if ($recipe->jenis !== Recipe::JENIS_UTAMA) {
            return null;
        }

        return DB::transaction(function () use ($recipe) {
            $current = Product::query()
                ->where('recipe_id', $recipe->id)
                ->where('origin', Product::ORIGIN_MENU)
                ->orderBy('id')
                ->get();

            if ($current->isNotEmpty()) {
                $primary = $current->shift();
                // Lebih dari satu menu jual untuk satu resep tidak boleh ada.
                $current->each(fn (Product $extra) => $this->retire($extra));
                $this->rename($primary, $recipe);

                return $primary;
            }

            $linked = Product::query()->where('recipe_id', $recipe->id)->get();

            if ($linked->isNotEmpty()) {
                return $this->promote($this->mostOrdered($linked), $recipe, $linked);
            }

            $sameName = $this->nameMatches()->firstWhere('recipe.id', $recipe->id);

            if ($sameName) {
                $this->count('ditautkan');

                return $this->promote($sameName['product'], $recipe, collect([$sameName['product']]));
            }

            return $this->create($recipe);
        });
    }

    /**
     * Samakan seluruh Menu Utama dengan menu jualnya. Dipakai perintah
     * menu:sync-from-recipes untuk merapikan data lama sekali jalan.
     *
     * @return array<string, int>
     */
    public function syncAll(): array
    {
        $this->tally = ['dibuat' => 0, 'diganti_nama' => 0, 'ditautkan' => 0, 'varian_nonaktif' => 0];

        // Ratusan menu baru sekaligus: satu lonceng ringkasan, bukan satu per menu.
        $this->quiet = true;

        try {
            Recipe::query()->utama()->orderBy('id')->get()->each(fn (Recipe $recipe) => $this->ensureFor($recipe));
        } finally {
            $this->quiet = false;
        }

        if ($this->tally['dibuat'] > 0) {
            $this->sendToAdmins(
                $this->tally['dibuat'].' menu dari tim menu belum punya Harga Non Lapak',
                'Menu ini sudah bisa dipilih di PO. Isi Harga Non Lapak-nya di Harga Menu (tombol "Harga belum diisi").',
                route('adminapp.products.index', ['harga' => 'kosong']),
            );
        }

        return $this->tally;
    }

    /**
     * Resep diganti nama atau diaktifkan/dinonaktifkan: menu jualnya ikut.
     * Dipanggil dari event model, jadi hanya kolom yang benar-benar berubah
     * yang diteruskan -- menu yang sengaja dinonaktifkan di Master Menu tidak
     * hidup lagi hanya karena resepnya disimpan ulang.
     */
    public function follow(Recipe $recipe): void
    {
        $changes = [];

        if ($recipe->wasChanged('name')) {
            $changes['name'] = $recipe->name;
        }

        if ($recipe->wasChanged('is_active')) {
            $changes['active'] = (bool) $recipe->is_active;
        }

        if ($changes === []) {
            return;
        }

        Product::query()
            ->where('recipe_id', $recipe->id)
            ->where('origin', Product::ORIGIN_MENU)
            ->get()
            ->each(fn (Product $product) => $product->update($changes));
    }

    /**
     * Pasangan menu V2 tanpa resep ↔ Menu Utama yang namanya sama persis
     * setelah token harga ("12K") dan tanda baca dibuang
     * (MenuMatchSuggester::normalize). Dilewati: nama yang cocok ke lebih dari
     * satu resep, dan pasangan yang sama-sama menyebut harga tetapi berbeda
     * (NASI SOP MERAH 15K vs resep "nasi sop merah 12k" bisa jadi porsi lain).
     *
     * @return Collection<int, array{product: Product, recipe: Recipe}>
     */
    public function nameMatches(): Collection
    {
        $suggester = app(MenuMatchSuggester::class);

        $recipes = Recipe::query()
            ->utama()
            ->get(['id', 'name', 'jenis'])
            ->groupBy(fn (Recipe $recipe) => $suggester->normalize($recipe->name));

        return Product::query()
            ->awaitingRecipe()
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->map(function (Product $product) use ($recipes, $suggester) {
                $candidates = $recipes->get($suggester->normalize($product->name));

                if ($candidates === null || $candidates->count() !== 1) {
                    return null;
                }

                $recipe = $candidates->first();

                return $this->pricesConflict($product->name, $recipe->name)
                    ? null
                    : ['product' => $product, 'recipe' => $recipe];
            })
            ->filter()
            // Satu resep cukup satu menu V2 bernama sama.
            ->unique(fn (array $pair) => $pair['recipe']->id)
            ->values();
    }

    /**
     * Jadikan $product menu jual resep ini: ditautkan, diberi nama resep,
     * status aktif ikut resep. Menu lain yang tertaut ke resep yang sama
     * dinonaktifkan (tetap tertaut supaya riwayat HPP-nya utuh).
     *
     * @param  Collection<int, Product>  $linked
     */
    protected function promote(Product $product, Recipe $recipe, Collection $linked): Product
    {
        $product->forceFill([
            'recipe_id' => $recipe->id,
            'needs_recipe' => true,
            'active' => (bool) $recipe->is_active,
            'origin' => Product::ORIGIN_MENU,
        ]);
        $this->rename($product, $recipe);

        $linked->reject(fn (Product $other) => $other->is($product))
            ->each(fn (Product $other) => $this->retire($other));

        // Varian yang dinonaktifkan mengubah "resep dipakai satu menu", jadi
        // harga lapak dari Harga Jual Target dihitung ulang.
        app(ProductRecipeCostSync::class)->queueRecipe($recipe->id);

        return $product;
    }

    protected function rename(Product $product, Recipe $recipe): void
    {
        $product->name = $recipe->name;

        if ($product->isDirty('name') && $product->exists) {
            $this->count('diganti_nama');
        }

        $product->save();
    }

    /** Menu yang bukan menu jual resepnya: dinonaktifkan dan tidak lagi ber-origin menu. */
    protected function retire(Product $product): void
    {
        if ($product->active) {
            $this->count('varian_nonaktif');
        }

        $product->forceFill([
            'active' => false,
            'origin' => $product->origin === Product::ORIGIN_MENU ? null : $product->origin,
        ])->save();
    }

    protected function create(Recipe $recipe): Product
    {
        $product = new Product([
            'name' => $recipe->name,
            'sku' => $this->uniqueSku($recipe->name),
            'unit' => self::DEFAULT_UNIT,
            // Belum ada harga non lapak sampai admin mengisinya.
            'base_price' => 0,
            'active' => (bool) $recipe->is_active,
            'is_3s' => false,
            'recipe_id' => $recipe->id,
            'needs_recipe' => true,
        ]);
        $product->origin = Product::ORIGIN_MENU;
        $product->save();

        $this->count('dibuat');
        $this->notifyAdmins($product);

        return $product;
    }

    /**
     * Menu yang paling sering dipesan dalam 90 hari terakhir; seri → id terkecil.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function mostOrdered(Collection $products): Product
    {
        $usage = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_order_items.product_id', $products->pluck('id'))
            ->where('purchase_orders.delivery_date', '>=', now()->subDays(self::USAGE_DAYS)->toDateString())
            ->groupBy('purchase_order_items.product_id')
            ->selectRaw('purchase_order_items.product_id, count(*) as line_count')
            ->pluck('line_count', 'product_id');

        return $products
            ->sortBy(fn (Product $product) => [-(int) ($usage[$product->id] ?? 0), $product->id])
            ->first();
    }

    /** Kedua nama menyebut harga (mis. 15K dan 12k) dan harganya berbeda. */
    protected function pricesConflict(string $a, string $b): bool
    {
        $prices = function (string $name): array {
            preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*k\b/iu', $name, $m);
            $found = array_values(array_unique(array_map(fn ($p) => str_replace(',', '.', $p), $m[1])));
            sort($found);

            return $found;
        };

        $pa = $prices($a);
        $pb = $prices($b);

        return $pa !== [] && $pb !== [] && $pa !== $pb;
    }

    /** SKU = nama menu di website, wajib unik: nama resep, diberi nomor bila sudah dipakai. */
    protected function uniqueSku(string $name): string
    {
        $base = mb_substr((string) Product::normalizeSku($name), 0, 94);
        $sku = $base;

        for ($n = 2; Product::query()->where('sku', $sku)->exists(); $n++) {
            $sku = $base.' ('.$n.')';
        }

        return $sku;
    }

    protected function count(string $key): void
    {
        if (array_key_exists($key, $this->tally)) {
            $this->tally[$key]++;
        }
    }

    /** Admin perlu mengisi Harga Non Lapak sebelum menu ini dipesan customer non lapak. */
    protected function notifyAdmins(Product $product): void
    {
        if ($this->quiet) {
            return;
        }

        $this->sendToAdmins(
            'Menu baru dari tim menu: '.$product->name,
            'Menu ini sudah bisa dipilih di PO. Isi Harga Non Lapak-nya di Harga Menu.',
            route('adminapp.products.edit', $product),
        );
    }

    protected function sendToAdmins(string $title, string $body, string $url): void
    {
        // whereHas, bukan User::role(): scope itu melempar galat bila peran
        // admin belum pernah dibuat.
        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'admin'))
            ->get();

        Notify::users($admins, $title, $body, $url, 'info', 'Isi harga');
    }
}
