<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeMismatch extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_LINKED = 'linked';

    public const STATUS_CREATED = 'created';

    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'raw_name',
        'raw_name_norm',
        'occurrence_count',
        'recipe_count',
        'sample_unit',
        'assumed_unit_price',
        'status',
        'resolved_inventory_item_id',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected $casts = [
        'occurrence_count' => 'integer',
        'recipe_count' => 'integer',
        'assumed_unit_price' => 'decimal:4',
        'resolved_at' => 'datetime',
    ];

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'Belum Diputuskan',
            self::STATUS_LINKED => 'Ditautkan',
            self::STATUS_CREATED => 'Bahan Baru Dibuat',
            self::STATUS_IGNORED => 'Diabaikan',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function isResolved(): bool
    {
        return $this->status !== self::STATUS_OPEN;
    }

    public function resolvedItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'resolved_inventory_item_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
