<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderLine extends Model
{
    /** Menunjuk resep; kebutuhan bahannya dihitung. */
    public const KIND_MENU = 'menu';

    /** Teks bebas, mis. "siapkan es batu"; tidak dihitung. */
    public const KIND_MANUAL = 'manual';

    public const SOURCE_PO = 'po';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MASTER_MENU = 'master_menu';

    protected $fillable = [
        'production_order_id',
        'sort_order',
        'kind',
        'recipe_id',
        'source',
        'purchase_order_item_id',
        'label',
        'qty',
        'unit',
        'remark',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    /** @return array<string, string> */
    public static function kindOptions(): array
    {
        return [
            self::KIND_MENU => 'Menu (dari resep)',
            self::KIND_MANUAL => 'Manual',
        ];
    }

    public function isMenu(): bool
    {
        return $this->kind === self::KIND_MENU && $this->recipe_id !== null;
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /** Nama yang ditampilkan: label, atau nama resepnya. */
    public function displayName(): string
    {
        return $this->label ?: ($this->recipe?->name ?? '-');
    }
}
