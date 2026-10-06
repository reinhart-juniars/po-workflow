<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pelaksana di dapur; bukan pengguna sistem. */
class ProductionWorker extends Model
{
    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Pilihan pelaksana: daftar master digabung nama yang sudah dipakai di
     * template kerja, supaya nama yang datang dari import tidak hilang dari
     * dropdown hanya karena belum didaftarkan.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $names = static::query()->where('is_active', true)->pluck('name')
            ->merge(RecipeTask::query()->whereNotNull('pic')->where('pic', '<>', '')->distinct()->pluck('pic'))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $names->mapWithKeys(fn ($name) => [$name => $name])->all();
    }
}
