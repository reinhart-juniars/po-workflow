<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_3s' => 'boolean',
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
                    return;
                }
            }
        });
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
     * Mutator: selalu simpan sku dalam huruf besar
     */
    protected function sku(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => mb_strtoupper((string) $value),
        );
    }

    public static function generateUniqueSku(string $name, ?int $ignoreId = null): string
    {
        $baseSku = Str::of($name)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->value();

        if ($baseSku === '') {
            $baseSku = 'MENU';
        }

        $baseSku = Str::limit($baseSku, 50, '');
        $candidate = $baseSku;
        $counter = 2;

        while (static::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('sku', $candidate)
            ->exists()) {
            $suffix = '-' . $counter;
            $candidate = Str::limit($baseSku, 50 - strlen($suffix), '') . $suffix;
            $counter++;
        }

        return $candidate;
    }
}
