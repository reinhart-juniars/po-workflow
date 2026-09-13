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
}
