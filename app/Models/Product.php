<?php

namespace App\Models;

use App\Support\WebsiteCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'unit',
        'base_price',
        'lapak_price',
        'raw_material_cost',
        'overhead_cost',
        'profit',
        'active',
        'is_3s',
        'recipe_id',
        'needs_recipe',
        'cost_source',
        'cost_synced_at',
        'photo_path',
        'photo_updated_at',
        'show_on_website',
        'website_category',
    ];

    /** Sama dengan default kolom: menu baru tidak tampil di website sampai dicentang marketing. */
    protected $attributes = [
        'show_on_website' => false,
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_3s' => 'boolean',
        'photo_updated_at' => 'datetime',
        'show_on_website' => 'boolean',
        'needs_recipe' => 'boolean',
        'cost_synced_at' => 'datetime',
        'base_price' => 'decimal:2',
        'lapak_price' => 'decimal:2',
        'raw_material_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'profit' => 'decimal:2',
    ];

    /**
     * Asal menu jual (kolom origin): dari PO Workflow versi 2. Produk yang
     * dibuat di 3S ONE dibiarkan kosong. Sengaja tidak fillable: asal data
     * bukan isian form.
     */
    public const ORIGIN_V2 = 'v2';

    /**
     * Dibuat otomatis dari Menu Utama tim menu (MasterMenuSync): nama dan
     * status aktifnya mengikuti resep.
     */
    public const ORIGIN_MENU = 'menu';

    /** HPP & OHC diketik admin. */
    public const COST_MANUAL = 'manual';

    /** HPP & OHC diturunkan dari resep (ProductRecipeCostSync). */
    public const COST_RECIPE = 'resep';

    /**
     * Alasan yang dicatat di riwayat harga untuk perubahan berikutnya, mis.
     * "HPP & OHC dari resep ...". Dikosongkan lagi oleh pemasangnya.
     */
    public static ?string $historyReason = null;

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            $product->base_price = static::normalizeMoney($product->base_price) ?? 0;
            $product->raw_material_cost = static::normalizeMoney($product->raw_material_cost);
            $product->overhead_cost = static::normalizeMoney($product->overhead_cost);

            $product->profit = $product->calculateProfit();
        });

        static::created(function (self $product) {
            $product->recordPriceHistory('Harga awal saat produk dibuat.');
        });

        // Ditautkan ke / dilepas dari resep: HPP & OHC-nya disinkronkan di
        // akhir request. Ditunda, bukan langsung -- save() bersarang di event
        // model yang sama melihat recipe_id masih "berubah" dan berulang.
        static::saved(function (self $product) {
            if ($product->wasChanged('recipe_id') || ($product->wasRecentlyCreated && $product->recipe_id !== null)) {
                $sync = app(\App\Services\ProductRecipeCostSync::class);
                $sync->queueProduct($product->id);

                // Produk lain di resep lama & baru ikut dihitung ulang: harga lapak
                // hanya mengikuti target bila resepnya dipakai satu produk, jadi
                // menautkan/melepas satu varian mengubah status varian lainnya.
                foreach (array_filter([$product->getOriginal('recipe_id'), $product->recipe_id]) as $recipeId) {
                    $sync->queueRecipe((int) $recipeId);
                }
            }
        });

        static::updated(function (self $product) {
            $tracked = ['base_price', 'lapak_price', 'raw_material_cost', 'overhead_cost', 'profit'];

            foreach ($tracked as $column) {
                if ($product->wasChanged($column)) {
                    $product->recordPriceHistory(static::$historyReason);

                    break;
                }
            }

            // Bagian B.1: lonceng perubahan harga menu.
            foreach (['lapak_price' => 'Harga lapak', 'base_price' => 'Harga non lapak'] as $column => $label) {
                if ($product->wasChanged($column)) {
                    app(\App\Services\PriceChangeNotifier::class)->productPriceChanged(
                        $product,
                        $product->getOriginal($column) === null ? null : (float) $product->getOriginal($column),
                        $product->{$column} === null ? null : (float) $product->{$column},
                        $label,
                    );
                }
            }

            // SKU = nama menu di website: admin dan marketing sama-sama boleh
            // menggantinya, jadi setiap penggantian dicatat dan diberitahukan.
            if ($product->wasChanged('sku')) {
                app(\App\Services\SkuChangeNotifier::class)->skuChanged($product, $product->getOriginal('sku'), $product->sku);
            }

            // Halaman website ikut tanda Menu 3S: marketing diberi tahu bila menu
            // yang tampil di website pindah halaman atau dinonaktifkan.
            if ($product->wasChanged('is_3s') || $product->wasChanged('active')) {
                app(\App\Services\WebsiteMenuWarnings::class)->productUpdated($product);
            }
        });
    }

    /**
     * Aturan validasi SKU, dipakai Master Menu (admin) dan Katalog (marketing).
     *
     * @return array{rules: array<string, array<int, mixed>>, messages: array<string, string>}
     */
    public static function skuValidation(?int $ignoreId = null): array
    {
        return [
            'rules' => [
                'sku' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('products', 'sku')->ignore($ignoreId)],
            ],
            'messages' => [
                'sku.required' => 'SKU wajib diisi. SKU dipakai sebagai nama menu di website.',
                'sku.max' => 'SKU maksimal 100 karakter.',
                'sku.unique' => 'SKU ini sudah dipakai menu lain.',
            ],
        ];
    }

    /** HPP & OHC produk ini mengikuti resep, bukan ketikan admin. */
    public function costFollowsRecipe(): bool
    {
        return $this->cost_source === self::COST_RECIPE;
    }

    /**
     * Harga Jual Target resep tertaut, yang menjadi Harga Lapak menu ini.
     *
     * Null -- harga lapak tetap diisi admin -- bila belum tertaut, resepnya
     * tanpa target, atau resepnya dipakai beberapa produk: varian 10K dan 12K
     * boleh berbagi resep, tetapi targetnya hanya satu angka, sehingga varian
     * yang lebih murah akan mendapat harga lapak di atas harga jualnya.
     */
    public function lapakTargetFromRecipe(): ?float
    {
        $recipe = $this->recipe_id === null ? null : ($this->relationLoaded('recipe') ? $this->recipe : $this->recipe()->first());

        if ($recipe?->target_price === null || $this->recipeIsShared()) {
            return null;
        }

        return round((float) $recipe->target_price, 2);
    }

    /**
     * Resep tertautnya juga dipakai produk aktif lain (varian harga). Varian
     * yang dinonaktifkan MasterMenuSync tidak dihitung, supaya harga lapak menu
     * jualnya kembali mengikuti Harga Jual Target resep.
     */
    public function recipeIsShared(): bool
    {
        $recipe = $this->recipe_id === null ? null : ($this->relationLoaded('recipe') ? $this->recipe : $this->recipe()->first());

        if (! $recipe) {
            return false;
        }

        // products_count dimuat lewat withCount (activeProductsCount) di daftar & sinkron; sisanya dihitung.
        return ($recipe->products_count ?? $recipe->products()->where('active', true)->count()) > 1;
    }

    /** Untuk withCount resep: hanya produk aktif (lihat recipeIsShared). */
    public static function activeProductsCount(): array
    {
        return ['products' => fn ($query) => $query->where('active', true)];
    }

    /** Harga lapak diatur tim menu lewat resep, terkunci di Admin App. */
    public function lapakFollowsRecipe(): bool
    {
        return $this->lapakTargetFromRecipe() !== null;
    }

    public function recordPriceHistory(?string $reason = null): void
    {
        $this->priceHistories()->create([
            'base_price' => $this->base_price,
            'lapak_price' => $this->lapak_price,
            'raw_material_cost' => $this->raw_material_cost,
            'overhead_cost' => $this->overhead_cost,
            'profit' => $this->profit,
            'changed_by' => Auth::id(),
            'reason' => $reason,
            'effective_from' => now(),
        ]);
    }

    /** URL foto katalog (disk public), null bila belum ada. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->photo_path) : null;
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->orderByDesc('effective_from');
    }

    public static function normalizeMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        return round(max(0, $number), 2);
    }

    /**
     * Harga yang menjadi dasar profit & margin menu: Harga Lapak (revisi user
     * 4 Nov 2026). Mengganti Harga Non Lapak tidak boleh menggeser profit. Menu
     * tanpa harga lapak memakai Harga Non Lapak, sama seperti aturan harga PO.
     */
    public function profitPrice(): float
    {
        return (float) ($this->lapak_price ?? $this->base_price ?? 0);
    }

    public function calculateProfit(): float
    {
        return round(
            $this->profitPrice()
            - ((float) ($this->raw_material_cost ?? 0) + (float) ($this->overhead_cost ?? 0)),
            2
        );
    }

    public function salesActualItems()
    {
        return $this->hasMany(SalesActualItem::class);
    }

    /** Resep yang dipakai memasak produk ini (beberapa varian harga boleh berbagi satu resep). */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** Produk yang masih harus dicocokkan: perlu resep tapi belum ditautkan. */
    public function scopeAwaitingRecipe(Builder $query): Builder
    {
        return $query->where('needs_recipe', true)->whereNull('recipe_id');
    }

    /**
     * Menu yang boleh dipesan di PO dan tampil di Harga Menu (revisi 9 Nov
     * 2026): hanya menu jual sebuah Menu Utama aktif tim menu (origin `menu`,
     * lihat MasterMenuSync). Sisa V2, varian harga, dan item tanpa resep
     * (HARGA UP, EXTRA 1K) tidak bisa dipesan.
     */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('origin', self::ORIGIN_MENU)
            ->whereHas('recipe', fn (Builder $recipe) => $recipe->utama()->where('is_active', true));
    }

    public function isOrderable(): bool
    {
        return $this->exists && static::query()->orderable()->whereKey($this->getKey())->exists();
    }

    /**
     * Porsi terjual `$days` hari terakhir sebagai kolom `porsi_terjual`, untuk
     * mengurutkan daftar kerja: produk yang paling laku dicocokkan lebih dulu.
     */
    public function scopeWithPorsiTerjual(Builder $query, int $days = 90): Builder
    {
        $since = now()->subDays($days)->toDateString();

        // Satu agregat untuk jendela $days hari lalu di-join, bukan subquery
        // per produk: subquery berkorelasi menyapu seluruh riwayat item tiap
        // produk sebelum menyaring tanggal, jadi makin lambat tiap bulan.
        $sold = SalesActualItem::query()
            ->join('sales_actuals', 'sales_actuals.id', '=', 'sales_actual_items.sales_actual_id')
            ->where('sales_actuals.sales_date', '>=', $since)
            ->groupBy('sales_actual_items.product_id')
            ->selectRaw('sales_actual_items.product_id, SUM(sales_actual_items.qty_delivery) as qty');

        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        return $query
            ->leftJoinSub($sold, 'porsi_terjual_window', 'porsi_terjual_window.product_id', '=', 'products.id')
            ->addSelect(DB::raw('COALESCE(porsi_terjual_window.qty, 0) as porsi_terjual'));
    }

    /**
     * Mutator: selalu simpan name dalam huruf besar
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => mb_strtoupper((string) $value),
        );
    }

    /**
     * SKU ditulis manual oleh admin dan dipakai sebagai nama menu di website,
     * jadi disimpan persis seperti diketik (bukan dipaksa kapital) -- hanya
     * spasi ganda dirapikan. Kosong disimpan sebagai null agar kolom unik
     * tidak bentrok antar-menu yang belum punya SKU.
     */
    protected function sku(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => static::normalizeSku($value),
        );
    }

    /**
     * Tingkat harga yang benar-benar berlaku untuk menu ini: harga lapak hanya
     * bila menu punya harga lapak; selain itu harga non lapak (base_price).
     */
    public function effectivePriceTier(?string $tier): string
    {
        return $tier === PurchaseOrderItem::TIER_LAPAK && $this->lapak_price !== null
            ? PurchaseOrderItem::TIER_LAPAK
            : PurchaseOrderItem::TIER_NORMAL;
    }

    /** Harga menu untuk tingkat harga tertentu: lapak, atau non lapak (TIER_NORMAL). */
    public function priceForTier(?string $tier): float
    {
        return $this->effectivePriceTier($tier) === PurchaseOrderItem::TIER_LAPAK
            ? (float) $this->lapak_price
            : (float) ($this->base_price ?? 0);
    }

    public static function normalizeSku(mixed $value): ?string
    {
        $sku = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $sku === '' ? null : $sku;
    }

    /**
     * Menu yang terbit di website: aktif, dicentang marketing, punya SKU (= namanya
     * di website), dan berkategori website yang cocok dengan brand-nya (is_3s).
     */
    public function scopeOnWebsite(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('show_on_website', true)
            ->whereNotNull('sku')
            ->where(fn (Builder $brand) => $brand
                ->where(fn (Builder $q) => $q->where('is_3s', true)
                    ->whereIn('website_category', array_keys(WebsiteCatalog::categoriesFor(WebsiteCatalog::BRAND_3S))))
                ->orWhere(fn (Builder $q) => $q->where('is_3s', false)
                    ->whereIn('website_category', array_keys(WebsiteCatalog::categoriesFor(WebsiteCatalog::BRAND_WWP)))));
    }
}
