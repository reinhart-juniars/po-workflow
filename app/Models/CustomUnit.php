<?php

namespace App\Models;

use App\Support\Units\UnitCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Satuan hitung tambahan dari Master Satuan (roll, karung, ...).
 *
 * Satuan ini tidak punya pembanding tetap, sama seperti pcs atau ikat: satu
 * roll bukan kelipatan tetap dari satu meter. Hitungannya ke satuan lain diatur
 * per bahan di Konversi Satuan. Satuan yang punya pembanding tetap (cm, meter)
 * didaftarkan di App\Support\Units\Unit supaya ikut terkonversi otomatis.
 */
class CustomUnit extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Kolom yang menyimpan teks satuan. */
    public const USAGE_COLUMNS = [
        'inventory_items' => ['unit'],
        'recipe_items' => ['unit'],
        'recipes' => ['yield_unit'],
        'inventory_unit_conversions' => ['from_unit', 'to_unit'],
    ];

    protected static function booted(): void
    {
        // Ejaan disimpan seragam supaya "Roll" dan "roll" tidak jadi dua satuan.
        static::saving(function (CustomUnit $unit) {
            $unit->name = self::normalize($unit->name);
        });

        static::saved(fn () => UnitCatalog::flush());
        static::deleted(fn () => UnitCatalog::flush());
    }

    public static function normalize(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name) ?? ''));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Berapa baris bahan/resep/konversi yang sudah memakai satuan ini.
     * Satuan yang sudah dipakai tidak boleh diganti nama atau dihapus:
     * baris lama tetap menulis nama lamanya dan jadi satuan yatim.
     */
    public function usageCount(): int
    {
        $total = 0;

        foreach (self::USAGE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $total += DB::table($table)->whereRaw("LOWER(TRIM({$column})) = ?", [$this->getOriginal('name') ?? $this->name])->count();
            }
        }

        return $total;
    }
}
