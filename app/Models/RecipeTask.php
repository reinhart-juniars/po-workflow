<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Template kerja paten sebuah menu. */
class RecipeTask extends Model
{
    protected $fillable = [
        'recipe_id',
        'sort_order',
        'task',
        'object',
        'quantity_text',
        'pic',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
