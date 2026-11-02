<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tugas umum harian dapur (cek stok gudang, buang sampah, bersihkan alat),
 * dicetak di bawah setiap Rekap Lembar Kerja. Bukan milik menu atau SPK mana
 * pun, jadi disimpan sekali sebagai daftar tetap.
 */
class DailyChore extends Model
{
    protected $fillable = [
        'sort_order',
        'worker_name',
        'task',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
