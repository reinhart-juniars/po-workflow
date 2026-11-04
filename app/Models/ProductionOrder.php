<?php

namespace App\Models;

use App\Helpers\AutoNumberHelper;
use App\Support\Settings\Settings;
use Carbon\CarbonInterface;
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
        'is_ready_stock',
        'production_date',
        'work_date',
        'production_time',
        'status',
        'notes',
        'completed_at',
        'completed_by',
        'source_spk_id',
        'source_produksi_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_ready_stock' => 'boolean',
        'production_date' => 'date',
        'work_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->number)) {
                $order->number = AutoNumberHelper::generate('production_orders', 'number', app(Settings::class)->get('document.production_prefix'));
            }

            if (empty($order->status)) {
                $order->status = self::STATUS_DRAFT;
            }

            if ($order->is_ready_stock && $order->work_date === null && $order->production_date !== null) {
                $order->work_date = $order->production_date->copy()->subDays(ProductionTask::DEFAULT_DAYS_BEFORE);
            }
        });

        // Baris Lembar Kerja yang mengikuti tanggal dikerjakan rencana ikut bergeser;
        // baris yang sengaja dimajukan sendiri (H-n lain) tidak disentuh.
        static::updated(function (self $order) {
            if (! $order->wasChanged(['work_date', 'production_date'])) {
                return;
            }

            $before = $order->replicate();
            $before->production_date = $order->getOriginal('production_date');
            $before->work_date = $order->getOriginal('work_date');

            $old = $before->defaultTaskDaysBefore();
            $new = $order->defaultTaskDaysBefore();

            if ($old !== $new) {
                $order->tasks()->where('days_before', $old)->update(['days_before' => $new]);
            }
        });
    }

    /**
     * Tanggal dapur mengerjakan: berbeda dari tanggal rencana/SPK, yang mengikuti
     * tanggal kirim PO. Rencana lama tanpa isian dianggap H-1, kebiasaan dapur.
     */
    public function workDate(): ?CarbonInterface
    {
        return $this->work_date ?? $this->production_date?->copy()->subDays(ProductionTask::DEFAULT_DAYS_BEFORE);
    }

    /** H-n bawaan baris Lembar Kerja SPK ini, dibatasi pada pilihan H s/d H-7. */
    public function defaultTaskDaysBefore(): int
    {
        $work = $this->workDate();

        if ($work === null || $this->production_date === null) {
            return ProductionTask::DEFAULT_DAYS_BEFORE;
        }

        return max(0, min(ProductionTask::MAX_DAYS_BEFORE, (int) $work->diffInDays($this->production_date)));
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

    /**
     * Rencana Masak (stok siap): disusun inventory sebelum PO masuk; porsinya
     * didistribusikan admin ke item PO bertanggal kirim sama.
     */
    public function scopeReadyStock(Builder $query): Builder
    {
        return $query->where('is_ready_stock', true);
    }

    /**
     * Boleh menyusun/mengisi SPK ini: pemegang production.manage untuk semua SPK,
     * pemegang plan.manage (gudang) hanya untuk Rencana Masak.
     */
    public function canBeManagedBy(?User $user): bool
    {
        return $user !== null
            && ($user->can('production.manage') || ($this->is_ready_stock && $user->can('plan.manage')));
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
