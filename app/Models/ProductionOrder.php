<?php

namespace App\Models;

use App\Helpers\AutoNumberHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SPK Produksi: daftar menu yang harus dimasak pada satu waktu produksi.
 *
 * Berbeda dari `Spk` (slot jadwal yang mengikat PO). Lihat komentar pada
 * migration production_orders.
 */
class ProductionOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'number',
        'title',
        'spk_id',
        'production_date',
        'production_time',
        'status',
        'notes',
        'completed_at',
        'completed_by',
        'source_spk_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'production_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->number)) {
                $order->number = AutoNumberHelper::generate('production_orders', 'number', 'SPKP');
            }

            if (empty($order->status)) {
                $order->status = self::STATUS_DRAFT;
            }
        });
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PLANNED => 'Siap Produksi',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_CANCELLED => 'Dibatalkan',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Baris menu masih boleh diubah. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PLANNED], true);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Baris yang menunjuk resep dan ikut dihitung kebutuhannya. */
    public function menuLines(): HasMany
    {
        return $this->lines()->where('kind', ProductionOrderLine::KIND_MENU)->whereNotNull('recipe_id');
    }

    public function requisition(): HasOne
    {
        return $this->hasOne(Requisition::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class)->orderBy('sort_order')->orderBy('id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function spk(): BelongsTo
    {
        return $this->belongsTo(Spk::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_PLANNED]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
