<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cara satu komponen resep ditampilkan di Lembar SPK PRA. recipe_id kosong berarti
 * berlaku di semua menu; terisi berarti pengecualian untuk satu menu.
 */
class SpkComponentRule extends Model
{
    public const DISPLAY_DETAIL = 'detail';

    public const DISPLAY_SINGLE = 'single';

    public const DISPLAY_HIDDEN = 'hidden';

    /** Jumlah baris ringkas: jumlah komponen itu sendiri (porsi menu atau jumlah sub-menu). */
    public const BASIS_COMPONENT = 'component';

    /** Jumlah baris ringkas: jumlah satu bahan utama, mis. fillet ayam. */
    public const BASIS_INGREDIENT = 'ingredient';

    protected $fillable = [
        'recipe_id',
        'component_key',
        'display',
        'label',
        'qty_basis',
        'basis_item',
        'hidden_items',
        'updated_by',
    ];

    protected $casts = [
        'hidden_items' => 'array',
    ];

    /** @return array<string, string> */
    public static function displayOptions(): array
    {
        return [
            self::DISPLAY_DETAIL => 'Rinci (tampilkan bahannya)',
            self::DISPLAY_SINGLE => 'Satu baris',
            self::DISPLAY_HIDDEN => 'Sembunyikan',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
