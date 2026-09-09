<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItemPriceHistory extends Model
{
    public const ACTION_SET_AWAL = 'set-awal';

    public const ACTION_NAIK = 'naik';

    public const ACTION_TURUN_DITOLAK = 'turun-ditolak';

    public const ACTION_TURUN_DIPAKSA = 'turun-dipaksa';

    protected $fillable = [
        'inventory_item_id',
        'old_unit_price',
        'new_unit_price',
        'old_pack_price',
        'new_pack_price',
        'action',
        'source',
        'note',
        'created_by',
    ];

    protected $casts = [
        'old_unit_price' => 'decimal:4',
        'new_unit_price' => 'decimal:4',
        'old_pack_price' => 'decimal:2',
        'new_pack_price' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return array<string, string> */
    public static function actionLabels(): array
    {
        return [
            self::ACTION_SET_AWAL => 'Harga Awal',
            self::ACTION_NAIK => 'Naik',
            self::ACTION_TURUN_DITOLAK => 'Turun (Ditolak)',
            self::ACTION_TURUN_DIPAKSA => 'Turun (Dipaksa)',
        ];
    }

    public function actionLabel(): string
    {
        return self::actionLabels()[$this->action] ?? (string) $this->action;
    }
}
