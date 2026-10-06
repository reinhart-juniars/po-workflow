<?php

namespace App\Models;

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
        'raw_material_cost',
        'overhead_cost',
        'profit',
        'active',
        'is_3s',
        'recipe_id',
        'needs_recipe',
        'photo_path',
        'photo_updated_at',
        'show_on_website',
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
        'base_price' => 'decimal:2',
        'raw_material_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'profit' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            $product->base_price = static::normalizeMoney($product->base_price) ?? 0;
            $product->raw_material_cost = static::normalizeMoney($product->raw_material_cost);
            $product->overhead_cost = static::normalizeMoney($product->overhead_cost);

            $product->profit = round(
                (float) $product->base_price
                - ((float) ($product->raw_material_cost ?? 0) + (float) ($product->overhead_cost ?? 0)),
                2
            );
        });

        static::created(function (self $product) {
            $product->recordPriceHistory('Harga awal saat produk dibuat.');
        });

        static::updated(function (self $product) {
            $tracked = ['base_price', 'raw_material_cost', 'overhead_cost', 'profit'];

            foreach ($tracked as $column) {
                if ($product->wasChanged($column)) {
                    $product->recordPriceHistory();

                    break;
                }
            }

            // Bagian B.1: lonceng perubahan harga jual menu.
            if ($product->wasChanged('base_price')) {
                app(\App\Services\PriceChangeNotifier::class)->productPriceChanged(
                    $product,
                    $product->getOriginal('base_price') === null ? null : (float) $product->getOriginal('base_price'),
                    $product->base_price === null ? null : (float) $product->base_price,
                );
            }

            // SKU = nama menu di website: admin dan marketing sama-sama boleh
            // menggantinya, jadi setiap penggantian dicatat dan diberitahukan.
            if ($product->wasChanged('sku')) {
                app(\App\Services\SkuChangeNotifier::class)->skuChanged($product, $product->getOriginal('sku'), $product->sku);
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

    public function recordPriceHistory(?string $reason = null): void
    {
        $this->priceHistories()->create([
            'base_price' => $this->base_price,
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

    public function calculateProfit(): float
    {
        return round(
            (float) $this->base_price
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

    public static function normalizeSku(mixed $value): ?string
    {
        $sku = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $sku === '' ? null : $sku;
    }

    /** Menu yang terbit di website: aktif, dicentang marketing, dan punya SKU (= namanya di website). */
    public function scopeOnWebsite(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('show_on_website', true)
            ->whereNotNull('sku');
    }
}
