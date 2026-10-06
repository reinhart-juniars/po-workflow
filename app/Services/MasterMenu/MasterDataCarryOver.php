<?php

namespace App\Services\MasterMenu;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Membawa data master dari database latihan ke database resmi.
 *
 * Selama Oktober 2026 tim memakai database latihan untuk mencoba alur sekaligus
 * memetakan menu dan bahan baku. Database resmi November dibuat ulang dari dump
 * v2 akhir Oktober, lalu layanan ini memindahkan hasil pemetaan ke sana.
 * Transaksi latihan (SPK Produksi, Form Kebutuhan, kartu stok, pembelian, kas)
 * sengaja tidak dibawa.
 *
 * Kenapa tidak cukup disalin: inventory_items dipakai bersama oleh v2 (baris
 * kategori tanpa induk, "bucket") dan modul inventory (bahan, berinduk bucket).
 * v2 bisa menambah item selama Oktober, sehingga ID bahan latihan bisa sudah
 * dipakai baris lain di tujuan. Bahan disisipkan dengan ID baru dan setiap
 * rujukan ke bahan dipetakan ulang. Pengguna juga dipetakan (lewat email),
 * karena kolom created_by dan sejenisnya merujuk ke ID pengguna.
 *
 * Baris yang sama di kedua database dikenali dari ID + (created_at atau nama):
 * keduanya berasal dari dump v2 yang sama, jadi baris lama punya pasangan,
 * sedangkan baris yang dibuat terpisah di masing-masing database tidak.
 *
 * Database sumber hanya dibaca; seluruh penulisan berjalan dalam satu transaksi.
 */
class MasterDataCarryOver
{
    private const CHUNK = 500;

    /** Awalan sumber histori harga yang ditulis tombol Periksa Form Kebutuhan. */
    private const REQUISITION_PRICE_SOURCE = 'Form ';

    /** Kolom menu jual yang diisi di 3S ONE, bukan di v2. */
    private const PRODUCT_COLUMNS = ['recipe_id', 'needs_recipe', 'photo_path', 'photo_updated_at', 'show_on_website'];

    /** @var array<int, int> id pengguna latihan => id pengguna tujuan */
    private array $userMap = [];

    /** @var array<int, int> id item latihan => id item tujuan */
    private array $itemMap = [];

    /** @var array<int, int> id supplier latihan => id supplier tujuan */
    private array $supplierMap = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: string}> kelompok, di latihan, dibawa, keterangan */
    private array $summary = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private Connection $source,
        private Connection $target,
    ) {}

    /**
     * @return array{summary: list<array{0: string, 1: int, 2: int, 3: string}>, warnings: list<string>}
     */
    public function run(bool $dryRun = false): array
    {
        $this->assertSameSchema();
        $this->assertTargetIsFresh();

        try {
            $this->target->transaction(function () use ($dryRun) {
                $this->carryUsers();
                $this->carryRoles();
                $this->carryBuckets();
                $this->carryIngredients();
                $this->carryPriceHistories();
                $this->carrySuppliers();
                $this->carryRecipes();
                $this->carryMismatches();
                $this->carryUnitConversions();
                $this->carryWorkers();
                $this->carryProductionHistory();
                $this->carrySettings();
                $this->carryProducts();

                if ($dryRun) {
                    // Lewat exception, bukan rollBack() manual: transaksi
                    // pembungkus di luar (mis. milik test) tidak ikut batal.
                    throw new DryRunCompleted;
                }
            });
        } catch (DryRunCompleted) {
            // Perubahan sudah dibatalkan; ringkasannya tetap dilaporkan.
        }

        return ['summary' => $this->summary, 'warnings' => $this->warnings];
    }

    /**
     * Kedua database harus berada di versi skema yang sama, supaya baris bisa
     * dipindah kolom demi kolom tanpa tebakan.
     */
    private function assertSameSchema(): void
    {
        $source = $this->source->table('migrations')->pluck('migration')->all();
        $target = $this->target->table('migrations')->pluck('migration')->all();

        $onlySource = array_values(array_diff($source, $target));
        $onlyTarget = array_values(array_diff($target, $source));

        if ($onlySource === [] && $onlyTarget === []) {
            return;
        }

        $detail = collect([
            $onlySource ? 'hanya di latihan: '.implode(', ', array_slice($onlySource, 0, 3)) : null,
            $onlyTarget ? 'hanya di tujuan: '.implode(', ', array_slice($onlyTarget, 0, 3)) : null,
        ])->filter()->implode('; ');

        throw new RuntimeException("Skema kedua database berbeda ({$detail}). Jalankan php artisan migrate di keduanya dulu.");
    }

    /**
     * Data master hanya dibawa ke database yang baru dimigrasi dari dump v2.
     * Menjalankannya dua kali akan menggandakan bahan, jadi ditolak.
     */
    private function assertTargetIsFresh(): void
    {
        $ingredients = $this->target->table('inventory_items')->whereNotNull('parent_id')->count();
        $recipes = $this->target->table('recipes')->count();

        if ($ingredients > 0 || $recipes > 0) {
            throw new RuntimeException("Tujuan sudah berisi {$ingredients} bahan dan {$recipes} resep. Data master hanya dibawa ke database yang baru dibuat dari dump v2, dan tidak dijalankan dua kali.");
        }
    }

    /**
     * Akun yang sudah ada di v2 tidak diubah (password v2 tetap berlaku); akun
     * yang hanya ada di latihan dibuat.
     *
     * Nama pengguna tidak dijamin unik dan email boleh kosong (sebagian besar
     * akun dapur tidak punya email), jadi pencocokannya berlapis: ID + nama
     * sama (baris dari dump yang sama), lalu email bila terisi, lalu nama bila
     * hanya satu akun yang cocok. Kalau masih ambigu, berhenti alih-alih menebak.
     */
    private function carryUsers(): void
    {
        $target = $this->target->table('users')->get(['id', 'name', 'email']);
        $byId = $target->keyBy('id');
        $byEmail = $target->filter(fn ($user) => self::norm($user->email) !== '')->groupBy(fn ($user) => self::norm($user->email));
        $byName = $target->groupBy(fn ($user) => self::norm($user->name));

        $users = $this->source->table('users')->orderBy('id')->get();
        $created = [];
        $ambiguous = [];

        foreach ($users as $user) {
            $same = $byId->get($user->id);

            if ($same && self::norm($same->name) === self::norm($user->name)) {
                $this->userMap[$user->id] = $same->id;

                continue;
            }

            $email = self::norm($user->email);
            $candidates = $email !== '' ? $byEmail->get($email, collect()) : collect();

            if ($candidates->isEmpty()) {
                $candidates = $byName->get(self::norm($user->name), collect());
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = $user->name;

                continue;
            }

            if ($candidates->count() === 1) {
                $this->userMap[$user->id] = $candidates->first()->id;

                continue;
            }

            $row = self::withoutId($user);
            $row['remember_token'] = null;
            $this->userMap[$user->id] = $this->target->table('users')->insertGetId($row);
            $created[] = $user->name;
        }

        if ($ambiguous) {
            throw new RuntimeException('Akun latihan tidak bisa dicocokkan dengan pasti karena nama/emailnya dipakai lebih dari satu akun di v2: '.self::list($ambiguous).'. Bedakan namanya di Master User, lalu ulangi.');
        }

        $this->record('Pengguna', $users->count(), count($created),
            count($created) ? 'akun baru: '.self::list($created) : 'semua akun sudah ada di v2');
    }

    /**
     * Peran dan izin hanya ditambahkan, tidak dicabut: peran baru yang dibagi
     * di latihan (Production, Inventory, Supervisor Gudang, Marketing) ikut,
     * peran dari v2 tetap.
     */
    private function carryRoles(): void
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        $roleMap = $this->mapByNameAndGuard($tables['roles'], create: true);
        $permissionMap = $this->mapByNameAndGuard($tables['permissions'], create: false);

        $existing = $this->target->table($tables['model_has_roles'])
            ->where('model_type', User::class)->get()
            ->map(fn ($row) => $row->role_id.':'.$row->{$modelKey})->flip();

        $assignments = $this->source->table($tables['model_has_roles'])->where('model_type', User::class)->get();
        $added = 0;

        foreach ($assignments as $row) {
            $userId = $this->userMap[$row->{$modelKey}] ?? null;
            $roleId = $roleMap[$row->role_id] ?? null;

            if ($userId === null || $roleId === null || $existing->has($roleId.':'.$userId)) {
                continue;
            }

            $this->target->table($tables['model_has_roles'])->insert([
                'role_id' => $roleId, 'model_type' => User::class, $modelKey => $userId,
            ]);
            $existing->put($roleId.':'.$userId, true);
            $added++;
        }

        $grants = $this->target->table($tables['role_has_permissions'])->get()
            ->map(fn ($row) => $row->permission_id.':'.$row->role_id)->flip();
        $granted = 0;

        foreach ($this->source->table($tables['role_has_permissions'])->get() as $row) {
            $permissionId = $permissionMap[$row->permission_id] ?? null;
            $roleId = $roleMap[$row->role_id] ?? null;

            if ($permissionId === null || $roleId === null || $grants->has($permissionId.':'.$roleId)) {
                continue;
            }

            $this->target->table($tables['role_has_permissions'])->insert([
                'permission_id' => $permissionId, 'role_id' => $roleId,
            ]);
            $grants->put($permissionId.':'.$roleId, true);
            $granted++;
        }

        $this->record('Peran pengguna', $assignments->count(), $added,
            "hanya menambah; {$granted} izin peran ditambahkan");
    }

    /**
     * Petakan baris roles/permissions latihan ke tujuan lewat nama + guard.
     *
     * @return array<int, int>
     */
    private function mapByNameAndGuard(string $table, bool $create): array
    {
        $target = $this->target->table($table)->get(['id', 'name', 'guard_name'])
            ->keyBy(fn ($row) => $row->name.'|'.$row->guard_name);
        $map = [];

        foreach ($this->source->table($table)->get() as $row) {
            if ($match = $target->get($row->name.'|'.$row->guard_name)) {
                $map[$row->id] = $match->id;
            } elseif ($create) {
                $map[$row->id] = $this->target->table($table)->insertGetId(self::withoutId($row));
            }
        }

        return $map;
    }

    /**
     * Item tanpa induk (bucket) milik v2 dipetakan ke pasangannya di tujuan.
     *
     * Item tanpa induk yang hanya ada di latihan dibawa bila dipakai resep,
     * konversi, keputusan bahan belum cocok, atau supplier (biasanya bahan yang
     * lupa diberi induk), dan dilewati bila tidak dipakai apa pun.
     */
    private function carryBuckets(): void
    {
        $target = $this->target->table('inventory_items')->whereNull('parent_id')
            ->get(['id', 'name', 'category', 'created_at', 'minimum_stock_value']);
        $byId = $target->keyBy('id');
        $byName = $target->keyBy(fn ($row) => self::norm($row->name).'|'.$row->category);

        $buckets = $this->source->table('inventory_items')->whereNull('parent_id')->orderBy('id')->get();
        $practiceOnly = collect();
        $minimumUpdated = 0;

        foreach ($buckets as $bucket) {
            $same = $byId->get($bucket->id);
            $match = ($same && $same->category === $bucket->category && self::sameOrigin($same, $bucket))
                ? $same
                : $byName->get(self::norm($bucket->name).'|'.$bucket->category);

            if ($match === null) {
                $practiceOnly->push($bucket);

                continue;
            }

            $this->itemMap[$bucket->id] = $match->id;

            // Batas stok minimum adalah fitur 3S ONE, jadi nilai latihan yang dipakai.
            if ($bucket->minimum_stock_value !== null && (string) $bucket->minimum_stock_value !== (string) $match->minimum_stock_value) {
                $this->target->table('inventory_items')->where('id', $match->id)
                    ->update(['minimum_stock_value' => $bucket->minimum_stock_value]);
                $minimumUpdated++;
            }
        }

        $referenced = $this->referencedItemIds();
        $parents = $this->source->table('inventory_items')->whereNotNull('parent_id')
            ->distinct()->pluck('parent_id')->map(fn ($id) => (int) $id)->flip();
        $carried = [];
        $skipped = [];

        foreach ($practiceOnly as $item) {
            // Induk bahan tidak dibawa diam-diam: kategori menentukan pengelompokan
            // Laba Rugi, jadi keputusan itu diserahkan ke manusia (carryIngredients).
            if ($parents->has($item->id)) {
                continue;
            }

            if (! $referenced->has($item->id)) {
                $skipped[] = $item->name;

                continue;
            }

            $this->itemMap[$item->id] = $this->target->table('inventory_items')->insertGetId(self::withoutId($item));
            $carried[] = $item->name;
        }

        if ($carried) {
            $this->warnings[] = 'Dibawa tanpa induk (dipakai resep/konversi tetapi tidak berinduk kategori), periksa di Item Inventaris: '.self::list($carried);
        }

        if ($skipped) {
            $this->warnings[] = 'Item tanpa induk yang hanya ada di latihan dan tidak dipakai apa pun, tidak dibawa: '.self::list($skipped);
        }

        $this->record('Kategori stok v2 (bucket)', $buckets->count(), count($carried),
            ($buckets->count() - $practiceOnly->count())." cocok dengan v2; {$minimumUpdated} batas stok minimum diperbarui");
    }

    /**
     * ID item yang dirujuk data master lain (bukan sebagai induk bahan).
     *
     * @return Collection<int, true>
     */
    private function referencedItemIds(): Collection
    {
        return collect()
            ->merge($this->source->table('recipe_items')->whereNotNull('inventory_item_id')->distinct()->pluck('inventory_item_id'))
            ->merge($this->source->table('inventory_unit_conversions')->whereNotNull('inventory_item_id')->distinct()->pluck('inventory_item_id'))
            ->merge($this->source->table('recipe_mismatches')->whereNotNull('resolved_inventory_item_id')->distinct()->pluck('resolved_inventory_item_id'))
            ->merge($this->source->table('inventory_item_supplier')->distinct()->pluck('inventory_item_id'))
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => true]);
    }

    /** Bahan (item berinduk) disisipkan dengan ID baru di bawah bucket yang sama. */
    private function carryIngredients(): void
    {
        $ingredients = $this->source->table('inventory_items')->whereNotNull('parent_id')->orderBy('id')->get();

        $orphans = $ingredients->reject(fn ($row) => isset($this->itemMap[$row->parent_id]));

        if ($orphans->isNotEmpty()) {
            $parents = $this->source->table('inventory_items')
                ->whereIn('id', $orphans->pluck('parent_id')->unique())->pluck('name')->all();

            throw new RuntimeException('Kategori induk tidak ada di v2: '.self::list($parents)." (induk {$orphans->count()} bahan). Buat kategori bernama sama di v2, atau pindahkan bahannya ke kategori yang ada di database latihan, lalu ulangi.");
        }

        foreach ($ingredients as $ingredient) {
            $row = self::withoutId($ingredient);
            $row['parent_id'] = $this->itemMap[$ingredient->parent_id];
            $this->itemMap[$ingredient->id] = $this->target->table('inventory_items')->insertGetId($row);
        }

        $this->record('Bahan', $ingredients->count(), $ingredients->count(), 'ID baru; rujukan dipetakan ulang');
    }

    /** Histori harga ikut bahannya, kecuali yang berasal dari Form Kebutuhan latihan. */
    private function carryPriceHistories(): void
    {
        $total = 0;
        $fromPractice = 0;
        $unmapped = 0;

        $carried = $this->copyRows('inventory_item_price_histories', function (array $row) use (&$total, &$fromPractice, &$unmapped) {
            $total++;

            if (str_starts_with((string) $row['source'], self::REQUISITION_PRICE_SOURCE)) {
                $fromPractice++;

                return null;
            }

            if (! isset($this->itemMap[$row['inventory_item_id']])) {
                $unmapped++;

                return null;
            }

            unset($row['id']);
            $row['inventory_item_id'] = $this->itemMap[$row['inventory_item_id']];
            $row['created_by'] = $this->user($row['created_by']);

            return $row;
        });

        $this->record('Histori harga bahan', $total, $carried,
            "{$fromPractice} dari Form Kebutuhan latihan dilewati".($unmapped ? "; {$unmapped} milik item yang tidak dibawa" : ''));
    }

    /**
     * Supplier dicocokkan lewat nama: migrasi sudah membuat supplier dari
     * pembelian v2, jadi yang sama dilengkapi dari latihan, sisanya ditambah.
     */
    private function carrySuppliers(): void
    {
        $byName = $this->target->table('suppliers')->get(['id', 'name'])->keyBy(fn ($row) => self::norm($row->name));
        $suppliers = $this->source->table('suppliers')->orderBy('id')->get();
        $created = 0;
        $completed = 0;

        foreach ($suppliers as $supplier) {
            $row = self::withoutId($supplier);
            $row['created_by'] = $this->user($row['created_by']);
            $row['updated_by'] = $this->user($row['updated_by']);

            if ($match = $byName->get(self::norm($supplier->name))) {
                $details = collect($row)->except(['name', 'created_at', 'created_by'])->reject(fn ($value) => $value === null)->all();
                $this->target->table('suppliers')->where('id', $match->id)->update($details);
                $this->supplierMap[$supplier->id] = $match->id;
                $completed++;
            } else {
                $this->supplierMap[$supplier->id] = $this->target->table('suppliers')->insertGetId($row);
                $created++;
            }
        }

        $existing = $this->target->table('inventory_item_supplier')->get()
            ->map(fn ($row) => $row->supplier_id.':'.$row->inventory_item_id)->flip();

        $links = $this->copyRows('inventory_item_supplier', function (array $row) use ($existing) {
            $supplierId = $this->supplierMap[$row['supplier_id']] ?? null;
            $itemId = $this->itemMap[$row['inventory_item_id']] ?? null;

            if ($supplierId === null || $itemId === null || $existing->has($supplierId.':'.$itemId)) {
                return null;
            }

            $existing->put($supplierId.':'.$itemId, true);
            unset($row['id']);

            return ['supplier_id' => $supplierId, 'inventory_item_id' => $itemId] + $row;
        });

        $this->record('Supplier', $suppliers->count(), $created + $completed,
            "{$created} baru, {$completed} dilengkapi dari latihan; {$links} tautan bahan");
    }

    /**
     * Resep, baris resep, dan Pekerjaan Menu. Tabel resep kosong di tujuan,
     * jadi ID resep dipertahankan dan tautan menu ke resep tetap berlaku.
     */
    private function carryRecipes(): void
    {
        $recipes = $this->copyRows('recipes', function (array $row) {
            $row['created_by'] = $this->user($row['created_by']);
            $row['updated_by'] = $this->user($row['updated_by']);

            return $row;
        });

        $lostLinks = 0;
        $items = $this->copyRows('recipe_items', function (array $row) use (&$lostLinks) {
            if ($row['inventory_item_id'] !== null) {
                $mapped = $this->itemMap[$row['inventory_item_id']] ?? null;
                $lostLinks += $mapped === null ? 1 : 0;
                $row['inventory_item_id'] = $mapped;
            }

            return $row;
        });

        if ($lostLinks) {
            $this->warnings[] = "{$lostLinks} baris resep kehilangan tautan bahan karena bahannya tidak dibawa; muncul lagi di Bahan Belum Cocok.";
        }

        $tasks = $this->copyRows('recipe_tasks', fn (array $row) => $row);

        $this->record('Resep', $this->source->table('recipes')->count(), $recipes, '');
        $this->record('Baris resep', $this->source->table('recipe_items')->count(), $items, 'tautan bahan dipetakan ulang');
        $this->record('Pekerjaan Menu (template)', $this->source->table('recipe_tasks')->count(), $tasks, '');
    }

    private function carryMismatches(): void
    {
        $carried = $this->copyRows('recipe_mismatches', function (array $row) {
            if ($row['resolved_inventory_item_id'] !== null) {
                $row['resolved_inventory_item_id'] = $this->itemMap[$row['resolved_inventory_item_id']] ?? null;
            }

            $row['resolved_by'] = $this->user($row['resolved_by']);

            return $row;
        });

        $this->record('Bahan Belum Cocok (keputusan)', $this->source->table('recipe_mismatches')->count(), $carried, '');
    }

    private function carryUnitConversions(): void
    {
        $skipped = 0;
        $carried = $this->copyRows('inventory_unit_conversions', function (array $row) use (&$skipped) {
            // Tanpa bahan = aturan umum yang berlaku untuk semua bahan; dibawa apa adanya.
            if ($row['inventory_item_id'] !== null) {
                if (! isset($this->itemMap[$row['inventory_item_id']])) {
                    $skipped++;

                    return null;
                }

                $row['inventory_item_id'] = $this->itemMap[$row['inventory_item_id']];
            }
            $row['created_by'] = $this->user($row['created_by']);
            $row['updated_by'] = $this->user($row['updated_by']);

            return $row;
        });

        $this->record('Konversi Satuan', $this->source->table('inventory_unit_conversions')->count(), $carried,
            $skipped ? "{$skipped} milik item yang tidak dibawa" : '');
    }

    private function carryWorkers(): void
    {
        $carried = $this->copyRows('production_workers', fn (array $row) => $row);

        $this->record('Pelaksana', $this->source->table('production_workers')->count(), $carried, '');
    }

    /**
     * Riwayat produksi dari Master Menu Revamp (arsip, bertanda source_spk_id /
     * source_produksi_id). SPK Produksi yang dibuat saat latihan tidak dibawa.
     */
    private function carryProductionHistory(): void
    {
        $orderIds = $this->source->table('production_orders')
            ->where(fn ($query) => $query->whereNotNull('source_spk_id')->orWhereNotNull('source_produksi_id'))
            ->pluck('id')->flip();

        $orders = $this->copyRows('production_orders', function (array $row) use ($orderIds) {
            if (! $orderIds->has($row['id'])) {
                return null;
            }

            // Arsip Master Menu tidak menempel ke slot SPK v2.
            $row['spk_id'] = null;
            $row['completed_by'] = $this->user($row['completed_by']);
            $row['created_by'] = $this->user($row['created_by']);
            $row['updated_by'] = $this->user($row['updated_by']);

            return $row;
        });

        $lines = $this->copyRows('production_order_lines', function (array $row) use ($orderIds) {
            if (! $orderIds->has($row['production_order_id'])) {
                return null;
            }

            $row['purchase_order_item_id'] = null;

            return $row;
        });

        $tasks = $this->copyRows('production_tasks', fn (array $row) => $orderIds->has($row['production_order_id']) ? $row : null);

        $practice = $this->source->table('production_orders')->count() - $orderIds->count();

        $this->record('Riwayat produksi Master Menu', $orderIds->count(), $orders,
            "{$lines} baris menu, {$tasks} baris kerja; {$practice} SPK Produksi latihan tidak dibawa");
    }

    /** Pengaturan Inventory menimpa nilai bawaan di tujuan. */
    private function carrySettings(): void
    {
        $settings = $this->source->table('app_settings')->get();

        foreach ($settings as $setting) {
            $row = self::withoutId($setting);
            $row['updated_by'] = $this->user($row['updated_by']);

            $this->target->table('app_settings')->updateOrInsert(['key' => $setting->key], $row);
        }

        $this->record('Pengaturan Inventory', $settings->count(), $settings->count(), '');
    }

    /**
     * Menu jual tetap milik v2 (nama, harga, aktif); dari latihan hanya kolom
     * yang diisi di 3S ONE: tautan resep, tanpa resep, foto, tampil di website,
     * dan SKU. Menu yang dibuat di latihan saja tidak dibuat ulang.
     */
    private function carryProducts(): void
    {
        $target = $this->target->table('products')
            ->get(array_merge(['id', 'name', 'created_at', 'sku'], self::PRODUCT_COLUMNS))->keyBy('id');
        $products = $this->source->table('products')
            ->get(array_merge(['id', 'name', 'created_at', 'sku'], self::PRODUCT_COLUMNS));

        $updated = 0;
        $practiceOnly = [];
        $wantedSku = [];

        foreach ($products as $product) {
            $match = $target->get($product->id);

            if ($match === null || ! self::sameOrigin($match, $product)) {
                $practiceOnly[] = $product->name;

                continue;
            }

            $changes = collect(self::PRODUCT_COLUMNS)
                ->filter(fn ($column) => (string) $product->{$column} !== (string) $match->{$column})
                ->mapWithKeys(fn ($column) => [$column => $product->{$column}])
                ->all();

            if ($changes) {
                $this->target->table('products')->where('id', $product->id)->update($changes);
                $updated++;
            }

            if ((string) $product->sku !== (string) $match->sku) {
                $wantedSku[$product->id] = $product->sku;
            }
        }

        $skuChanged = $this->applySkus($target->map(fn ($row) => $row->sku)->all(), $wantedSku);

        if ($practiceOnly) {
            $this->warnings[] = 'Menu yang hanya ada di latihan, tidak dibuat (menu jual dikelola di v2): '.self::list($practiceOnly);
        }

        $this->record('Menu jual', $products->count(), $updated,
            "tautan resep, foto, website; {$skuChanged} SKU diperbarui");
    }

    /**
     * Pasang SKU dari latihan tanpa melanggar keunikan. SKU yang di tujuan
     * dipakai menu lain dilewati dan dilaporkan, bukan dipaksa.
     *
     * @param  array<int, ?string>  $current  SKU di tujuan per id menu
     * @param  array<int, ?string>  $wanted  SKU dari latihan per id menu
     */
    private function applySkus(array $current, array $wanted): int
    {
        $final = array_replace($current, $wanted);
        $counts = array_count_values(array_filter($final, fn ($sku) => $sku !== null && $sku !== ''));

        foreach ($wanted as $id => $sku) {
            if ($sku !== null && $sku !== '' && $counts[$sku] > 1) {
                unset($wanted[$id]);
                $this->warnings[] = "SKU \"{$sku}\" tidak dipasang ke menu #{$id}: sudah dipakai menu lain di v2.";
            }
        }

        if ($wanted === []) {
            return 0;
        }

        // Dua tahap supaya pertukaran SKU antar menu tidak tersandung indeks unik.
        $this->target->table('products')->whereIn('id', array_keys($wanted))->update(['sku' => null]);

        foreach ($wanted as $id => $sku) {
            $this->target->table('products')->where('id', $id)->update(['sku' => $sku]);
        }

        return count($wanted);
    }

    /**
     * Salin seluruh baris sebuah tabel latihan ke tujuan, per potongan.
     * $transform mengembalikan baris yang akan disisipkan, atau null untuk melewatinya.
     */
    private function copyRows(string $table, callable $transform): int
    {
        $count = 0;

        $this->source->table($table)->orderBy('id')->chunk(self::CHUNK, function ($rows) use ($table, $transform, &$count) {
            $batch = $rows->map(fn ($row) => $transform((array) $row))->filter()->values()->all();

            if ($batch !== []) {
                $this->target->table($table)->insert($batch);
                $count += count($batch);
            }
        });

        return $count;
    }

    private function user(mixed $id): ?int
    {
        return $id === null ? null : ($this->userMap[$id] ?? null);
    }

    private function record(string $group, int $source, int $carried, string $note): void
    {
        $this->summary[] = [$group, $source, $carried, $note];
    }

    /** @return array<string, mixed> */
    private static function withoutId(object $row): array
    {
        $data = (array) $row;
        unset($data['id']);

        return $data;
    }

    /**
     * Dua baris ber-ID sama dianggap baris yang sama dari dump v2 bila waktu
     * dibuatnya sama, atau namanya sama (waktu bisa bergeser bila salah satu
     * database pernah disalin dengan zona waktu berbeda).
     */
    private static function sameOrigin(object $a, object $b): bool
    {
        return (string) $a->created_at === (string) $b->created_at
            || self::norm($a->name) === self::norm($b->name);
    }

    private static function norm(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /** @param  list<string>  $names */
    private static function list(array $names): string
    {
        $shown = array_slice($names, 0, 8);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " (+{$rest} lainnya)" : '');
    }
}
