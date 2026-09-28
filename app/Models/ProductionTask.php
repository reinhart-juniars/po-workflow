<?php

namespace App\Models;

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
        'is_done',
        'done_at',
    ];

    protected $casts = [
        'is_done' => 'boolean',
        'done_at' => 'datetime',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
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
