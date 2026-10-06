<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Pencarian global 3S ONE: satu kotak di bilah atas yang menemukan dokumen
 * (PO, DO, SPK Produksi) dan master (pelanggan, menu, bahan) dari seluruh
 * aplikasi, tanpa pengguna harus menebak ada di menu mana.
 *
 * Aturan keamanannya satu dan tidak boleh dilunakkan: **sebuah hasil hanya
 * muncul bila pengguna benar-benar boleh membuka halaman tujuannya.** Karena
 * itu setiap sumber tidak menyebut "peran yang boleh mencari", melainkan
 * daftar `targets` -- pasangan (syarat akses, route tujuan) yang disalin dari
 * pagar route/policy aslinya. Tujuan pertama yang cocok dengan pengguna
 * menentukan tautannya; bila tidak ada yang cocok, sumbernya tidak dicari
 * sama sekali (fail closed) -- bukan dicari lalu tautannya disembunyikan.
 *
 * Konsekuensinya disengaja: menambah entitas ke pencarian berarti menuliskan
 * pagarnya di sini, dan tes arsitektur di
 * tests/Feature/Security/GlobalSearchAccessTest.php membuat build merah bila
 * ada sumber tanpa pagar atau yang menunjuk route tidak terdaftar.
 */
class GlobalSearchService
{
    /** Kata kunci lebih pendek dari ini tidak dicari: hasilnya terlalu banyak untuk menolong. */
    public const MIN_LENGTH = 2;

    /** Hasil maksimum per jenis, supaya satu jenis tidak menenggelamkan yang lain. */
    public const PER_SOURCE = 5;

    /**
     * Karakter pelepas wildcard LIKE.
     *
     * Sengaja bukan backslash: di MySQL backslash juga dilepas oleh parser
     * string, sehingga `ESCAPE '\'` bermakna berbeda di MySQL (produksi) dan
     * SQLite (tes) -- persis jenis perbedaan yang lolos dari suite. Tanda seru
     * tidak istimewa di keduanya.
     */
    protected const LIKE_ESCAPE = '!';

    /**
     * Definisi sumber pencarian.
     *
     * - `targets`   : urut dari yang paling spesifik. `roles` dicek dengan
     *                 hasAnyRole, `permission` lewat Gate (superadmin lolos
     *                 dari Gate::before). Salah satunya wajib ada.
     * - `columns`   : kolom tabel sendiri, dicocokkan dengan LIKE.
     * - `relations` : relasi => kolom, dicocokkan lewat whereHas.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sources(): array
    {
        return [
            'purchase_order' => [
                'label' => 'Purchase Order',
                'icon' => 'heroicon-o-document-text',
                'model' => PurchaseOrder::class,
                // Admin App memegang detail PO; Production App punya halaman
                // progres untuk PO yang sama. Owner/superadmin cocok di baris
                // pertama, jadi mereka diarahkan ke detail Admin.
                'targets' => [
                    ['roles' => ['admin', 'owner', 'superadmin'], 'route' => 'adminapp.orders.show'],
                    ['roles' => ['production'], 'route' => 'productionapp.orders.show'],
                ],
                'columns' => ['po_number', 'recipient_name'],
                'relations' => ['customer' => ['name']],
                'with' => ['customer'],
                'order' => ['id', 'desc'],
            ],

            'delivery_order' => [
                'label' => 'Delivery Order',
                'icon' => 'heroicon-o-truck',
                'model' => DeliveryOrder::class,
                'targets' => [
                    ['roles' => ['delivery', 'owner', 'superadmin'], 'route' => 'deliveryapp.orders.show'],
                ],
                'columns' => ['do_code'],
                'relations' => ['area' => ['name']],
                'with' => ['area'],
                'order' => ['id', 'desc'],
            ],

            'production_order' => [
                'label' => 'SPK Produksi',
                'icon' => 'heroicon-o-fire',
                'model' => ProductionOrder::class,
                'targets' => [
                    ['permission' => 'production.view', 'route' => 'filament.admin.resources.production-orders.edit'],
                ],
                'columns' => ['number', 'title'],
                // Slot SPK lama tetap ketemu lewat kodenya (SPK-000123).
                'relations' => ['spk' => ['spk_code']],
                'with' => ['spk'],
                'order' => ['id', 'desc'],
            ],

            'customer' => [
                'label' => 'Pelanggan',
                'icon' => 'heroicon-o-user-group',
                'model' => Customer::class,
                'targets' => [
                    ['roles' => ['admin', 'owner', 'superadmin'], 'route' => 'adminapp.customers.edit'],
                ],
                'columns' => ['name', 'phone'],
                'relations' => ['area' => ['name']],
                'with' => ['area'],
                'order' => ['name', 'asc'],
            ],

            'product' => [
                'label' => 'Menu',
                'icon' => 'heroicon-o-shopping-bag',
                'model' => Product::class,
                'targets' => [
                    ['roles' => ['admin', 'owner', 'superadmin'], 'route' => 'adminapp.products.edit'],
                ],
                'columns' => ['name', 'sku'],
                'order' => ['name', 'asc'],
            ],

            'inventory_item' => [
                'label' => 'Bahan',
                'icon' => 'heroicon-o-cube',
                'model' => InventoryItem::class,
                'targets' => [
                    ['permission' => 'inventory.view', 'route' => 'filament.admin.resources.inventory-items.edit'],
                ],
                'columns' => ['name'],
                'order' => ['name', 'asc'],
            ],
        ];
    }

    /**
     * Cari `$term` pada semua sumber yang boleh dilihat `$user`.
     *
     * @return list<array{group: string, icon: string, title: string, meta: string, url: string}>
     */
    public function search(?User $user, ?string $term): array
    {
        $term = trim((string) $term);

        if ($user === null || mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $results = [];

        foreach (self::sources() as $source) {
            $route = $this->routeFor($user, $source);

            // Tidak ada tujuan yang boleh dibuka pengguna ini: jangan dicari.
            if ($route === null) {
                continue;
            }

            foreach ($this->records($source, $term) as $record) {
                $results[] = [
                    'group' => $source['label'],
                    'icon' => $source['icon'],
                    'title' => $this->titleOf($record),
                    'meta' => $this->metaOf($record),
                    'url' => route($route, $record),
                ];
            }
        }

        return $results;
    }

    /**
     * Route tujuan pertama yang boleh dibuka pengguna, atau null bila tak ada.
     *
     * @param  array<string, mixed>  $source
     */
    protected function routeFor(User $user, array $source): ?string
    {
        foreach ($source['targets'] as $target) {
            $allowed = isset($target['permission'])
                ? $user->can($target['permission'])
                : $user->hasAnyRole($target['roles']);

            if ($allowed) {
                return $target['route'];
            }
        }

        return null;
    }

    /**
     * Baris yang cocok untuk satu sumber.
     *
     * @param  array<string, mixed>  $source
     * @return Collection<int, Model>
     */
    protected function records(array $source, string $term): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        /** @var Builder $query */
        $query = $source['model']::query()->with($source['with'] ?? []);

        $query->where(function (Builder $q) use ($source, $like) {
            foreach ($source['columns'] as $column) {
                $this->whereLike($q, $column, $like);
            }

            foreach ($source['relations'] ?? [] as $relation => $columns) {
                $q->orWhereHas($relation, function (Builder $sub) use ($columns, $like) {
                    $sub->where(function (Builder $inner) use ($columns, $like) {
                        foreach ($columns as $column) {
                            $this->whereLike($inner, $column, $like);
                        }
                    });
                });
            }
        });

        [$column, $direction] = $source['order'] ?? ['id', 'desc'];

        return $query->orderBy($column, $direction)->limit(self::PER_SOURCE)->get();
    }

    /**
     * `orWhere(..., 'like', ...)` bawaan Laravel tidak bisa menyertakan klausa
     * ESCAPE, padahal tanpa itu pelepasan di escapeLike() tidak berarti apa-apa
     * di SQLite. Nama kolom berasal dari sources() di berkas ini, bukan dari
     * permintaan pengguna, dan tetap dibungkus grammar sebelum masuk SQL.
     */
    protected function whereLike(Builder $query, string $column, string $like): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $query->orWhereRaw($wrapped." like ? escape '".self::LIKE_ESCAPE."'", [$like]);
    }

    /**
     * `%` dan `_` adalah wildcard LIKE. Tanpa dilepas, mencari "50%" cocok
     * dengan semua baris, bukan dengan teks "50%".
     */
    protected function escapeLike(string $term): string
    {
        $e = self::LIKE_ESCAPE;

        return str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], $term);
    }

    /** Nomor dokumen kalau ada, kalau tidak namanya. */
    protected function titleOf(Model $record): string
    {
        foreach (['po_number', 'do_code', 'number', 'name'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return '#'.$record->getKey();
    }

    /** Baris kedua: konteks secukupnya untuk membedakan dua hasil yang mirip. */
    protected function metaOf(Model $record): string
    {
        $parts = match (true) {
            $record instanceof PurchaseOrder => [
                $record->customer?->name,
                $record->recipient_name,
            ],
            $record instanceof DeliveryOrder => [
                $record->area?->name,
                $record->status,
            ],
            $record instanceof ProductionOrder => [
                $record->title,
                $record->spk?->spk_code,
            ],
            $record instanceof Customer => [
                $record->area?->name,
                $record->phone,
            ],
            $record instanceof Product => [
                $record->sku,
                $record->unit,
            ],
            $record instanceof InventoryItem => [
                $record->unit,
            ],
            default => [],
        };

        return collect($parts)->filter()->unique()->implode(' · ');
    }

    /**
     * Route tujuan yang dipakai seluruh sumber -- dibaca tes arsitektur.
     *
     * @return list<string>
     */
    public static function routeNames(): array
    {
        return collect(self::sources())
            ->flatMap(fn (array $source) => array_column($source['targets'], 'route'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Route tujuan yang tidak terdaftar di aplikasi (seharusnya selalu kosong).
     *
     * @return list<string>
     */
    public static function missingRoutes(): array
    {
        return array_values(array_filter(
            self::routeNames(),
            fn (string $name) => Route::has($name) === false,
        ));
    }
}
