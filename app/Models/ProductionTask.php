<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris lembar kerja SPK Produksi: siapa mengerjakan apa. */
class ProductionTask extends Model
{
    protected $fillable = [
        'production_order_id',
        'sort_order',
        'recipe_id',
        'menu_label',
        'worker_name',
        'task',
        'object',
        'quantity_text',
        'days_before',
        'is_done',
        'done_at',
    ];

    protected $casts = [
        'days_before' => 'integer',
        'is_done' => 'boolean',
        'done_at' => 'datetime',
    ];

    /** Paling jauh sebuah pekerjaan dimajukan dari tanggal SPK Produksi (H-7). */
    public const MAX_DAYS_BEFORE = 7;

    /** Bawaan baris baru: H-1, kebiasaan dapur memasak sehari sebelum tanggal SPK. */
    public const DEFAULT_DAYS_BEFORE = 1;

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Tanggal pekerjaan ini dikerjakan: tanggal SPK Produksi (H) dikurangi
     * days_before. Dipakai Rekap Lembar Kerja untuk mengumpulkan pekerjaan
     * dari banyak SPK ke satu hari kerja.
     */
    public function workDate(): ?CarbonInterface
    {
        $date = $this->productionOrder?->production_date;

        return $date?->copy()->subDays((int) ($this->days_before ?? self::DEFAULT_DAYS_BEFORE));
    }

    /**
     * Pilihan H-n untuk form; bila tanggal SPK diketahui, tanggal nyatanya
     * ikut ditulis ("H-2 · Sen 13/10") supaya tidak perlu dihitung di kepala.
     *
     * @return array<int, string>
     */
    public static function dayOptions(?CarbonInterface $productionDate = null): array
    {
        return collect(range(0, self::MAX_DAYS_BEFORE))
            ->mapWithKeys(fn (int $days) => [$days => trim(($days === 0 ? 'H' : 'H-'.$days)
                .($productionDate ? ' · '.$productionDate->copy()->subDays($days)->locale('id')->translatedFormat('D d/m') : ''))])
            ->all();
    }

    /** Kalimat kerja utuh, mis. "potong ayam 25 gr". */
    public function summary(): string
    {
        return trim(implode(' ', array_filter([$this->task, $this->object, $this->quantity_text])));
    }

    /**
     * Pilihan "Apa yang Dikerjakan": tugas yang sudah dipakai di template
     * menu dan lembar kerja -- seperti Master Menu, tanpa master tugas
     * terpisah yang harus diurus dan bisa kembar.
     *
     * @return list<string>
     */
    public static function taskOptions(): array
    {
        return RecipeTask::query()->whereNotNull('task')->distinct()->pluck('task')
            ->merge(static::query()->whereNotNull('task')->distinct()->pluck('task'))
            ->map(fn ($task) => trim((string) $task))
            ->filter()
            ->unique(fn ($task) => mb_strtolower($task))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
