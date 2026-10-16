<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Master Supplier bahan baku & kemasan.
 *
 * Transaksi (pembelian, form kebutuhan, hutang) menyimpan supplier_id beserta
 * salinan namanya di supplier_name -- lihat App\Models\Concerns\LinksSupplier.
 */
class Supplier extends Model
{
    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'website',
        'address',
        'bank_account',
        'payment_term_days',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'payment_term_days' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $supplier) {
            if ($supplier->isDirty('name')) {
                $supplier->name = trim((string) $supplier->name);
            }

            if ($supplier->isDirty('website')) {
                $supplier->website = static::normalizeWebsite($supplier->website);
            }
        });
    }

    /**
     * Rapikan link/website yang diketik: tambahkan https:// bila skemanya
     * tidak ditulis ("tokopedia.com/tokomakmur"). Hasilnya null bila bukan
     * link http(s) yang sah -- skema lain (javascript:, data:) tidak boleh
     * tersimpan karena kolom ini ditampilkan sebagai tautan yang bisa diklik.
     */
    public static function normalizeWebsite(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            $value = 'https://'.ltrim($value, '/');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = (string) parse_url($value, PHP_URL_HOST);

        // Host wajib bertitik supaya teks biasa ("ada", "-") tidak lolos sebagai link.
        if (! in_array($scheme, ['http', 'https'], true)
            || ! str_contains($host, '.')
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    /** Bahan yang dipasok supplier ini. */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class)->withTimestamps();
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Cari supplier dari nama yang diketik, tanpa peduli huruf besar/kecil
     * dan spasi di ujung.
     */
    public static function findByName(?string $name): ?self
    {
        $name = mb_strtolower(trim((string) $name));

        if ($name === '') {
            return null;
        }

        return static::query()->whereRaw('LOWER(name) = ?', [$name])->first();
    }

    /**
     * Pilihan untuk form: supplier aktif, ditambah supplier yang sedang
     * terpilih walau sudah nonaktif supaya transaksi lama tetap terbaca.
     *
     * @return array<int, string>
     */
    public static function options(?int $currentId = null): array
    {
        return static::query()
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->when($currentId, fn (Builder $q) => $q->orWhere('id', $currentId)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Jatuh tempo bawaan untuk pembelian kredit, bila supplier punya termin. */
    public function dueDateFor(mixed $transactionDate): ?string
    {
        if (! $this->payment_term_days || blank($transactionDate)) {
            return null;
        }

        return Carbon::parse($transactionDate)->addDays($this->payment_term_days)->toDateString();
    }

    /**
     * Transaksi yang menahan penghapusan supplier.
     *
     * @return list<string>
     */
    public function transactionBlockers(): array
    {
        $this->loadCount(['purchases', 'payables', 'requisitions']);

        return collect([
            'pembelian bahan' => (int) $this->purchases_count,
            'hutang' => (int) $this->payables_count,
            'form kebutuhan' => (int) $this->requisitions_count,
        ])
            ->filter(fn (int $count) => $count > 0)
            ->map(fn (int $count, string $label) => $label.' ('.$count.')')
            ->values()
            ->all();
    }
}
