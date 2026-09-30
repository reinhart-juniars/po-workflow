<?php

namespace App\Support\Settings;

use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Pembaca/penulis pengaturan modul. Nilai yang belum pernah diubah jatuh ke
 * bawaan SettingRegistry; yang tersimpan di-cache (dibersihkan setiap kali
 * ada yang disimpan). Didaftarkan sebagai singleton.
 */
class Settings
{
    protected const CACHE_KEY = 'app_settings.all';

    /** @var array<string, mixed>|null */
    protected ?array $stored = null;

    public function get(string $key): mixed
    {
        $definition = SettingRegistry::definition($key);
        $stored = $this->stored();

        // Bawaan ikut di-cast supaya tipenya sama dengan nilai tersimpan
        // (40 dan 40.0 adalah pengaturan yang sama, bukan perubahan).
        return $this->cast($definition['type'], array_key_exists($key, $stored) ? $stored[$key] : $definition['default']);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** Persen disimpan sebagai angka persen (40), bukan pecahan (0,40). */
    public function percentAsFraction(string $key): float
    {
        return round((float) $this->get($key) / 100, 4);
    }

    /**
     * Simpan satu nilai; perubahan dicatat ke AuditLog supaya bisa ditelusuri
     * siapa yang menggeser angka HPP/produksi dan kapan.
     */
    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $definition = SettingRegistry::definition($key);
        $value = $this->cast($definition['type'], $value);
        $this->validate($key, $definition, $value);

        $before = $this->get($key);

        $row = AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        $this->flush();

        if ($before !== $value) {
            AuditLog::query()->create([
                'user_id' => $userId,
                'entity' => 'app_setting',
                'entity_id' => $row->id,
                'action' => 'updated',
                'message' => "Pengaturan {$key} diubah dari ".json_encode($before).' menjadi '.json_encode($value),
                'before_json' => ['key' => $key, 'value' => $before],
                'after_json' => ['key' => $key, 'value' => $value],
            ]);
        }
    }

    /** @param  array<string, mixed>  $values */
    public function setMany(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $userId);
        }
    }

    /** @return array<string, mixed> seluruh kunci terdaftar beserta nilai berlakunya */
    public function all(): array
    {
        $rows = [];

        foreach (array_keys(SettingRegistry::all()) as $key) {
            $rows[$key] = $this->get($key);
        }

        return $rows;
    }

    public function flush(): void
    {
        $this->stored = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    protected function stored(): array
    {
        return $this->stored ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn () => AppSetting::query()->pluck('value', 'key')->all(),
        );
    }

    protected function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            SettingRegistry::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            SettingRegistry::TYPE_PERCENT => (float) $value,
            SettingRegistry::TYPE_INT => (int) $value,
            SettingRegistry::TYPE_DATE => $this->castDate($value),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /** @param  array{type: string, min?: int|float, max?: int|float, options?: array<string, string>}  $definition */
    protected function validate(string $key, array $definition, mixed $value): void
    {
        if (in_array($definition['type'], [SettingRegistry::TYPE_PERCENT, SettingRegistry::TYPE_INT], true)) {
            if (isset($definition['min']) && $value < $definition['min']) {
                throw new InvalidArgumentException("Pengaturan {$key} minimal {$definition['min']}.");
            }
            if (isset($definition['max']) && $value > $definition['max']) {
                throw new InvalidArgumentException("Pengaturan {$key} maksimal {$definition['max']}.");
            }
        }

        if ($definition['type'] === SettingRegistry::TYPE_SELECT && ! array_key_exists($value, $definition['options'] ?? [])) {
            throw new InvalidArgumentException("Pilihan '{$value}' tidak dikenal untuk {$key}.");
        }

        if ($definition['type'] === SettingRegistry::TYPE_TEXT && $value === '') {
            throw new InvalidArgumentException("Pengaturan {$key} tidak boleh kosong.");
        }

        if ($definition['type'] === SettingRegistry::TYPE_DATE && $value === '') {
            throw new InvalidArgumentException("Pengaturan {$key} harus berupa tanggal yang valid.");
        }
    }

    /** Tanggal apa pun formatnya disimpan Y-m-d; yang tidak terbaca jadi '' (ditolak validate). */
    protected function castDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
