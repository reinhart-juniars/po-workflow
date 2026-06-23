<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    public const CATEGORY_FIXED_ASSET = 'inventaris';
    public const CATEGORY_RAW_MATERIAL = 'bahan_baku';
    public const CATEGORY_PACKAGING = 'packaging';

    protected $fillable = [
        'name',
        'unit',
        'category',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_FIXED_ASSET => 'Inventaris',
            self::CATEGORY_RAW_MATERIAL => 'Bahan Baku',
            self::CATEGORY_PACKAGING => 'Kemasan',
        ];
    }

    public static function stockCategories(): array
    {
        return [
            self::CATEGORY_RAW_MATERIAL,
            self::CATEGORY_PACKAGING,
        ];
    }

    public function categoryLabel(): string
    {
        return self::categoryOptions()[$this->category] ?? ucfirst(str_replace('_', ' ', (string) $this->category));
    }

    public function openings(): HasMany
    {
        return $this->hasMany(InventoryOpening::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function opnames(): HasMany
    {
        return $this->hasMany(StockOpname::class);
    }
}
