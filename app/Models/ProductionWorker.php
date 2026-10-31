<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Orang di dapur/gudang yang tidak memegang sistem: pelaksana lembar kerja,
 * pemeriksa barang masuk, atau keduanya.
 */
class ProductionWorker extends Model
{
    protected $fillable = [
        'name',
        'is_executor',
        'is_inspector',
        'is_active',
    ];

    protected $casts = [
        'is_executor' => 'boolean',
        'is_inspector' => 'boolean',
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
        $names = static::query()->where('is_active', true)->where('is_executor', true)->pluck('name')
            ->merge(RecipeTask::query()->whereNotNull('pic')->where('pic', '<>', '')->distinct()->pluck('pic'))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $names->mapWithKeys(fn ($name) => [$name => $name])->all();
    }

    /**
     * Pilihan pemeriksa barang masuk (Form Kebutuhan › Periksa).
     *
     * @return array<string, string>
     */
    public static function inspectorOptions(): array
    {
        return static::query()
            ->where('is_active', true)
            ->where('is_inspector', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /** Label tugas untuk tabel master, mis. "Pelaksana, Pemeriksa". */
    public function rolesLabel(): string
    {
        return collect([
            $this->is_executor ? 'Pelaksana' : null,
            $this->is_inspector ? 'Pemeriksa' : null,
        ])->filter()->implode(', ') ?: '-';
    }
}
