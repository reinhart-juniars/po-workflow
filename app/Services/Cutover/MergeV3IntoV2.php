<?php

namespace App\Services\Cutover;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Cutover ulang 9 Nov 2026: tim tetap memakai V2 selama 1–7 Nov, sementara tim
 * menu, inventory, dan produksi sudah bekerja di V3. Database tujuan dibuat dari
 * dump V2 final lalu dimigrasi; layanan ini menempelkan pekerjaan V3 ke atasnya.
 *
 * Dasar pemetaannya: V3 produksi dibangun dari dump V2 31 Okt, jadi baris yang
 * dibuat sebelum 1 Nov ber-ID sama di kedua database. Karena itu:
 *   - tabel milik 3S ONE (resep, bahan, SPK Produksi, form kebutuhan, pergerakan
 *     stok, supplier, ...) kosong di tujuan dan disalin utuh dengan ID yang sama;
 *   - yang bisa bentrok hanya baris yang dibuat dua sistem sesudah 1 Nov: menu,
 *     customer, dan PO. Ketiganya dipetakan ulang di sini.
 *
 * Aturan bentrok (keputusan owner, 8 Nov 2026): V3 menang untuk resep, harga,
 * dan HPP menu; V2 menang untuk transaksi. PO yang diinput di kedua sistem
 * memakai versi V2; PO draft yang hanya ada di V3 dibawa dengan nomor baru.
 * Database sumber (V3) hanya dibaca.
 */
class MergeV3IntoV2
{
    public const DEFAULT_SINCE = '2026-11-01 00:00:00';

    private const CHUNK = 500;

    /**
     * Tabel milik 3S ONE yang disalin utuh dengan ID yang sama, berurutan sesuai
     * rujukan antartabel (induk lebih dulu).
     */
    private const VERBATIM_TABLES = [
        'suppliers',
        'inventory_item_supplier',
        'inventory_item_price_histories',
        'inventory_unit_conversions',
        'production_workers',
        'recipes',
        'recipe_items',
        'recipe_tasks',
        'recipe_mismatches',
        'spk_component_rules',
        'production_orders',
        'production_order_lines',
        'production_tasks',
        'production_order_component_overrides',
        'requisitions',
        'requisition_lines',
        'inventory_movements',
        'menu_name_aliases',
        'website_publishes',
        'daily_chores',
    ];

    /**
     * Tabel 3S ONE yang merujuk baris transaksi V2 (hutang, kas keluar, sales
     * actual). Barisnya tidak bisa dipindah dengan aman, jadi harus kosong di V3.
     */
    private const MUST_BE_EMPTY_TABLES = [
        'purchase_bills',
        'leftover_breakdowns',
        'leftover_components',
        'leftover_disposals',
        'ready_stock_closings',
    ];

    /** Tidak dibawa: catatan sementara/teknis, atau riwayat milik sistem masing-masing. */
    private const IGNORED_TABLES = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches',
        'failed_jobs', 'password_reset_tokens', 'notifications', 'audit_logs',
    ];

    /** Ditangani langkah khusus di bawah. */
    private const HANDLED_TABLES = [
        'users', 'roles', 'permissions', 'model_has_roles', 'role_has_permissions',
        'model_has_permissions', 'inventory_items', 'products', 'product_price_histories',
        'customers', 'purchase_orders', 'purchase_order_items', 'app_settings',
    ];

    /** @var array<int, int> id menu V3 => id menu tujuan */
    private array $productMap = [];

    /** @var array<int, int> id customer V3 => id customer tujuan */
    private array $customerMap = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: string}> kelompok, di V3, dibawa, keterangan */
    private array $summary = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private Connection $source,
        private Connection $target,
        private string $since = self::DEFAULT_SINCE,
    ) {}

    /**
     * @return array{summary: list<array{0: string, 1: int, 2: int, 3: string}>, warnings: list<string>}
     */
    public function run(bool $dryRun = false): array
    {
        $this->assertSameSchema();
        $this->assertTargetIsFresh();
        $this->assertNoUnclassifiedTables();
        $this->assertMustBeEmptyTables();
        $this->assertNoUnhandledNewRows();
        $this->assertV2LeftInventoryAlone();

        try {
            $this->target->transaction(function () use ($dryRun) {
                $this->mergeUsers();
                $this->mergeRoles();
                $this->mergeInventoryItems();
                $this->copyVerbatimTables(productLinked: false);
                $this->mergeSettings();
                $this->mergeProducts();
                $this->copyVerbatimTables(productLinked: true);
                $this->mergeProductPriceHistories();
                $this->mergeCustomers();
                $this->mergePurchaseOrders();

                if ($dryRun) {
                    throw new MergeDryRunCompleted;
                }
            });
        } catch (MergeDryRunCompleted) {
            // Seluruh perubahan dibatalkan; ringkasannya tetap dilaporkan.
        }

        return ['summary' => $this->summary, 'warnings' => $this->warnings];
    }

    // ------------------------------------------------------------------
    // Pagar
    // ------------------------------------------------------------------

    private function assertSameSchema(): void
    {
        $source = $this->source->table('migrations')->pluck('migration')->all();
        $target = $this->target->table('migrations')->pluck('migration')->all();

        $onlySource = array_values(array_diff($source, $target));
        $onlyTarget = array_values(array_diff($target, $source));

        if ($onlySource || $onlyTarget) {
            $detail = collect([
                $onlySource ? 'hanya di V3: '.implode(', ', array_slice($onlySource, 0, 3)) : null,
                $onlyTarget ? 'hanya di tujuan: '.implode(', ', array_slice($onlyTarget, 0, 3)) : null,
            ])->filter()->implode('; ');

            throw new RuntimeException("Skema kedua database berbeda ({$detail}). Deploy kode yang sama lalu jalankan php artisan migrate di keduanya.");
        }
    }

    /** Hanya ke database yang baru dibuat dari dump V2; dijalankan dua kali akan menggandakan data. */
    private function assertTargetIsFresh(): void
    {
        foreach (['recipes', 'production_orders', 'requisitions', 'suppliers'] as $table) {
            if ($this->target->table($table)->exists()) {
                throw new RuntimeException("Tujuan sudah berisi data {$table}. Penggabungan hanya ke database yang baru dibuat dari dump V2 dan tidak dijalankan dua kali.");
            }
        }
    }

    /**
     * Tabel yang di V3 berisi tetapi di tujuan kosong dan belum diputuskan
     * nasibnya berarti ada fitur baru yang belum dipetakan: berhenti, jangan
     * diam-diam ditinggal.
     */
    private function assertNoUnclassifiedTables(): void
    {
        $known = array_merge(self::VERBATIM_TABLES, self::MUST_BE_EMPTY_TABLES, self::IGNORED_TABLES, self::HANDLED_TABLES);
        $unclassified = [];

        foreach ($this->source->getSchemaBuilder()->getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (in_array($table, $known, true) || ! $this->source->table($table)->exists()) {
                continue;
            }

            if (! $this->target->table($table)->exists()) {
                $unclassified[] = $table;
            }
        }

        if ($unclassified) {
            throw new RuntimeException('Tabel berisi di V3 tetapi kosong di tujuan, belum ada aturan penggabungannya: '.implode(', ', $unclassified).'.');
        }
    }

    /**
     * Baris yang dibuat di V3 sejak tanggal pisah pada tabel tanpa aturan
     * (mis. sales actual, kas keluar) akan hilang. Owner menyatakan tidak ada
     * transaksi semacam itu di V3; kalau ternyata ada, berhenti.
     */
    private function assertNoUnhandledNewRows(): void
    {
        $handled = array_merge(self::VERBATIM_TABLES, self::IGNORED_TABLES, self::HANDLED_TABLES);
        $found = [];

        foreach ($this->source->getSchemaBuilder()->getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (in_array($table, $handled, true) || ! $this->source->getSchemaBuilder()->hasColumn($table, 'created_at')) {
                continue;
            }

            // Baris yang isinya (selain ID & waktu) juga ada di tujuan tidak hilang,
            // mis. data bawaan yang ditanam migrasi di kedua database.
            $count = $this->source->table($table)->where('created_at', '>=', $this->since)->get()
                ->reject(function ($row) use ($table) {
                    $query = $this->target->table($table);

                    foreach (collect((array) $row)->except(['id', 'created_at', 'updated_at']) as $column => $value) {
                        $value === null ? $query->whereNull($column) : $query->where($column, $value);
                    }

                    return $query->exists();
                })
                ->count();

            if ($count > 0) {
                $found[] = "{$table} ({$count})";
            }
        }

        if ($found) {
            throw new RuntimeException('Ada data yang dibuat di V3 sejak '.$this->since.' tanpa aturan penggabungan, akan hilang bila diteruskan: '.implode(', ', $found).'.');
        }
    }

    private function assertMustBeEmptyTables(): void
    {
        foreach (self::MUST_BE_EMPTY_TABLES as $table) {
            if ($this->source->getSchemaBuilder()->hasTable($table) && $this->source->table($table)->exists()) {
                throw new RuntimeException("Tabel {$table} di V3 berisi data yang merujuk transaksi V2; belum ada aturan memindahkannya dengan aman.");
            }
        }
    }

    /**
     * Item inventory diambil utuh dari V3. Itu hanya benar bila V2 tidak
     * mengubah atau menambah item sejak tanggal pisah.
     */
    private function assertV2LeftInventoryAlone(): void
    {
        $touched = $this->target->table('inventory_items')
            ->where(fn ($query) => $query->where('created_at', '>=', $this->since)->orWhere('updated_at', '>=', $this->since))
            ->pluck('name');

        if ($touched->isNotEmpty()) {
            throw new RuntimeException('Item inventory diubah di V2 sejak '.$this->since.': '.self::list($touched->all()).'. Gabungkan manual dulu.');
        }
    }

    // ------------------------------------------------------------------
    // Akun & peran
    // ------------------------------------------------------------------

    /**
     * Akun V2 tidak diubah (password V2 tetap berlaku). Akun yang hanya ada di
     * V3 dibuat dengan ID yang sama supaya rujukan created_by tetap benar.
     */
    private function mergeUsers(): void
    {
        $target = $this->target->table('users')->get(['id', 'name'])->keyBy('id');
        $created = [];

        foreach ($this->source->table('users')->orderBy('id')->get() as $user) {
            $same = $target->get($user->id);

            if ($same === null) {
                $this->target->table('users')->insert((array) $user);
                $created[] = $user->name;

                continue;
            }

            if (self::norm($same->name) !== self::norm($user->name)) {
                throw new RuntimeException("Akun #{$user->id} berbeda: V2 \"{$same->name}\", V3 \"{$user->name}\". Rujukan pengguna tidak bisa dipindah dengan aman.");
            }
        }

        $this->record('Pengguna', $this->source->table('users')->count(), count($created),
            $created ? 'akun hanya di V3: '.self::list($created) : 'semua akun sudah ada di V2');
    }

    /** Peran, izin, dan pembagian peran dari V3 ditambahkan; yang dari V2 tidak dicabut. */
    private function mergeRoles(): void
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        $roleMap = $this->mapByNameAndGuard($tables['roles']);
        $permissionMap = $this->mapByNameAndGuard($tables['permissions']);

        $existingRoles = $this->target->table($tables['model_has_roles'])->get()
            ->map(fn ($row) => $row->role_id.':'.$row->model_type.':'.$row->{$modelKey})->flip();
        $assigned = 0;

        foreach ($this->source->table($tables['model_has_roles'])->get() as $row) {
            $key = $roleMap[$row->role_id].':'.$row->model_type.':'.$row->{$modelKey};

            if (! $existingRoles->has($key)) {
                $this->target->table($tables['model_has_roles'])->insert(['role_id' => $roleMap[$row->role_id], 'model_type' => $row->model_type, $modelKey => $row->{$modelKey}]);
                $existingRoles->put($key, true);
                $assigned++;
            }
        }

        $existingGrants = $this->target->table($tables['role_has_permissions'])->get()
            ->map(fn ($row) => $row->permission_id.':'.$row->role_id)->flip();
        $granted = 0;

        foreach ($this->source->table($tables['role_has_permissions'])->get() as $row) {
            $key = $permissionMap[$row->permission_id].':'.$roleMap[$row->role_id];

            if (! $existingGrants->has($key)) {
                $this->target->table($tables['role_has_permissions'])->insert(['permission_id' => $permissionMap[$row->permission_id], 'role_id' => $roleMap[$row->role_id]]);
                $existingGrants->put($key, true);
                $granted++;
            }
        }

        $this->record('Peran & izin', $this->source->table($tables['roles'])->count(), $assigned + $granted,
            "{$assigned} pembagian peran, {$granted} izin peran ditambahkan");
    }

    /** @return array<int, int> id V3 => id tujuan, dicocokkan lewat nama + guard; yang belum ada dibuat. */
    private function mapByNameAndGuard(string $table): array
    {
        $target = $this->target->table($table)->get()->keyBy(fn ($row) => $row->name.'|'.$row->guard_name);
        $map = [];

        foreach ($this->source->table($table)->orderBy('id')->get() as $row) {
            $match = $target->get($row->name.'|'.$row->guard_name);
            $map[$row->id] = $match ? $match->id : $this->target->table($table)->insertGetId(self::withoutId($row));
        }

        return $map;
    }

    // ------------------------------------------------------------------
    // Inventory & tabel 3S ONE
    // ------------------------------------------------------------------

    /**
     * Item inventory: milik V3 (V2 tidak mengubahnya, lihat pagar). Item yang
     * ada di keduanya diperbarui dari V3, sisanya disisipkan dengan ID yang
     * sama -- induk lebih dulu karena parent_id dirujuk dengan kunci asing.
     */
    private function mergeInventoryItems(): void
    {
        $existing = $this->target->table('inventory_items')->get(['id', 'name', 'created_at'])->keyBy('id');
        $pending = $this->source->table('inventory_items')->orderBy('id')->get()->keyBy('id');
        $updated = 0;
        $inserted = 0;

        foreach ($pending as $id => $item) {
            if ($match = $existing->get($id)) {
                if (! $this->sameOrigin($match, $item)) {
                    throw new RuntimeException("Item inventory #{$id} berbeda: V2 \"{$match->name}\", V3 \"{$item->name}\".");
                }

                $this->target->table('inventory_items')->where('id', $id)->update(self::withoutId($item));
                $updated++;
                $pending->forget($id);
            }
        }

        $present = $existing->keys()->flip();

        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn ($item) => $item->parent_id === null || $present->has($item->parent_id));

            if ($ready->isEmpty()) {
                throw new RuntimeException('Induk item inventory tidak ditemukan untuk: '.self::list($pending->pluck('name')->all()));
            }

            foreach ($ready->chunk(self::CHUNK) as $chunk) {
                $this->target->table('inventory_items')->insert($chunk->map(fn ($item) => (array) $item)->values()->all());
            }

            foreach ($ready as $id => $item) {
                $present->put($id, true);
                $pending->forget($id);
                $inserted++;
            }
        }

        $this->record('Item inventory & bahan', $existing->count() + $inserted, $updated + $inserted,
            "{$inserted} disisipkan (ID sama), {$updated} item V2 diperbarui dari V3");
    }

    /**
     * Tabel yang merujuk menu (product_id) disalin setelah pemetaan menu
     * selesai, supaya rujukan ke menu yang digabung ikut dipetakan.
     */
    private function copyVerbatimTables(bool $productLinked): void
    {
        foreach (self::VERBATIM_TABLES as $table) {
            if (! $this->source->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $hasProduct = $this->source->getSchemaBuilder()->hasColumn($table, 'product_id');

            if ($hasProduct !== $productLinked) {
                continue;
            }

            // Tabel 3S ONE milik V3. Isinya di tujuan hanya mungkin data bawaan
            // yang ditanam migrasi (mis. setelan komponen SPK), jadi diganti.
            $seeded = $this->target->table($table)->count();

            if ($seeded > 0) {
                $this->target->table($table)->delete();
            }

            $count = 0;

            $this->source->table($table)->orderBy($this->source->getSchemaBuilder()->hasColumn($table, 'id') ? 'id' : $this->firstColumn($table))
                ->chunk(self::CHUNK, function ($rows) use ($table, $hasProduct, &$count) {
                    $batch = $rows->map(function ($row) use ($hasProduct) {
                        $data = (array) $row;

                        if ($hasProduct && $data['product_id'] !== null) {
                            $data['product_id'] = $this->productMap[$data['product_id']] ?? $data['product_id'];
                        }

                        return $data;
                    })->all();

                    $this->target->table($table)->insert($batch);
                    $count += count($batch);
                });

            if ($count > 0 || $seeded > 0) {
                $this->record($table, $count, $count, 'disalin utuh, ID sama'.($seeded ? "; {$seeded} baris bawaan migrasi diganti" : ''));
            }
        }
    }

    /** Pengaturan 3S ONE dari V3 menimpa nilai di tujuan. */
    private function mergeSettings(): void
    {
        $settings = $this->source->table('app_settings')->get();

        foreach ($settings as $setting) {
            $this->target->table('app_settings')->updateOrInsert(['key' => $setting->key], self::withoutId($setting));
        }

        $this->record('Pengaturan', $settings->count(), $settings->count(), '');
    }

    // ------------------------------------------------------------------
    // Menu
    // ------------------------------------------------------------------

    /**
     * Menu yang disentuh V3 sejak tanggal pisah: seluruh kolomnya dari V3 (V3
     * menang untuk resep, harga, HPP). Menu yang tidak disentuh V3 tetap versi
     * V2. Menu baru V3 dicocokkan ke menu baru V2 yang namanya sama (urutan kata
     * boleh beda); bila tidak ada, dibuat baru.
     */
    private function mergeProducts(): void
    {
        $target = $this->target->table('products')->get()->keyBy('id');
        $newInTarget = $target->filter(fn ($row) => (string) $row->created_at >= $this->since);
        $updated = 0;
        $merged = [];
        $created = [];
        $conflicts = [];
        $wantedSku = [];

        foreach ($this->source->table('products')->orderBy('id')->get() as $product) {
            $match = $target->get($product->id);

            if ($match !== null && in_array($match->id, $this->productMap, true)) {
                $match = null; // sudah dipakai sebagai pasangan menu V3 lain
            }

            if ($match === null || ! $this->sameOrigin($match, $product)) {
                $twin = $newInTarget->first(fn ($row) => self::nameKey($row->name) === self::nameKey($product->name)
                    && ! in_array($row->id, $this->productMap, true));

                if ($twin === null) {
                    $row = self::withoutId($product);
                    $row['sku'] = null;
                    $this->productMap[$product->id] = $this->target->table('products')->insertGetId($row);
                    $wantedSku[$this->productMap[$product->id]] = $product->sku;
                    $created[] = $product->name;

                    continue;
                }

                $match = $twin;
                $merged[] = "{$product->name} (V3 #{$product->id} → #{$twin->id})";
            }

            $this->productMap[$product->id] = $match->id;

            if ((string) $product->updated_at < $this->since && $match->id === $product->id) {
                continue; // tidak disentuh V3: versi V2 tetap berlaku
            }

            if ((string) $match->updated_at >= $this->since && $match->id === $product->id) {
                $conflicts[] = $product->name;
            }

            $row = collect((array) $product)->except(['id', 'created_at', 'sku'])->all();
            $this->target->table('products')->where('id', $match->id)->update($row);
            $wantedSku[$match->id] = $product->sku;
            $updated++;
        }

        $skuChanged = $this->applySkus($wantedSku);

        if ($conflicts) {
            $this->warnings[] = 'Menu diubah di V2 dan V3 sejak '.$this->since.', dipakai versi V3 (periksa harganya): '.self::list($conflicts);
        }

        $this->record('Menu jual', $this->source->table('products')->count(), $updated + count($created),
            "{$updated} diperbarui dari V3; digabung: ".(self::list($merged) ?: '-').'; baru: '.(self::list($created) ?: '-')."; {$skuChanged} SKU diperbarui");
    }

    /**
     * Pasang SKU tanpa melanggar index unik: dikosongkan dulu lalu diisi, dan
     * SKU yang tetap dipakai menu lain dilewati dan dilaporkan.
     *
     * @param  array<int, ?string>  $wanted  SKU per id menu tujuan
     */
    private function applySkus(array $wanted): int
    {
        $current = $this->target->table('products')->pluck('sku', 'id')->all();
        $wanted = array_filter($wanted, fn ($sku, $id) => (string) $sku !== (string) ($current[$id] ?? ''), ARRAY_FILTER_USE_BOTH);
        $final = array_replace($current, $wanted);
        $counts = array_count_values(array_filter($final, fn ($sku) => $sku !== null && $sku !== ''));

        foreach ($wanted as $id => $sku) {
            if ($sku !== null && $sku !== '' && $counts[$sku] > 1) {
                unset($wanted[$id]);
                $this->warnings[] = "SKU \"{$sku}\" tidak dipasang ke menu #{$id}: sudah dipakai menu lain.";
            }
        }

        if ($wanted === []) {
            return 0;
        }

        $this->target->table('products')->whereIn('id', array_keys($wanted))->update(['sku' => null]);

        foreach ($wanted as $id => $sku) {
            $this->target->table('products')->where('id', $id)->update(['sku' => $sku]);
        }

        return count($wanted);
    }

    /** Riwayat harga menu yang tercatat di V3 sejak tanggal pisah ditambahkan (ID baru). */
    private function mergeProductPriceHistories(): void
    {
        $rows = $this->source->table('product_price_histories')->where('created_at', '>=', $this->since)->orderBy('id')->get();

        foreach ($rows->chunk(self::CHUNK) as $chunk) {
            $this->target->table('product_price_histories')->insert($chunk->map(function ($row) {
                $data = self::withoutId($row);
                $data['product_id'] = $this->productMap[$data['product_id']] ?? $data['product_id'];

                return $data;
            })->values()->all());
        }

        $this->record('Riwayat harga menu', $rows->count(), $rows->count(), 'sejak '.$this->since);
    }

    // ------------------------------------------------------------------
    // Customer & PO
    // ------------------------------------------------------------------

    /** Customer yang dibuat di V3 sejak tanggal pisah dicocokkan lewat nama; yang belum ada dibuat. */
    private function mergeCustomers(): void
    {
        $byName = $this->target->table('customers')->get(['id', 'name'])->groupBy(fn ($row) => self::norm($row->name));
        $created = [];

        $this->source->table('customers')->orderBy('id')->each(function ($customer) use ($byName, &$created) {
            if ((string) $customer->created_at < $this->since) {
                $this->customerMap[$customer->id] = $customer->id;

                return;
            }

            $match = $byName->get(self::norm($customer->name));

            if ($match && $match->count() > 1) {
                throw new RuntimeException("Customer \"{$customer->name}\" ada lebih dari satu di V2; tidak bisa dicocokkan dengan pasti.");
            }

            if ($match) {
                $this->customerMap[$customer->id] = $match->first()->id;
            } else {
                $this->customerMap[$customer->id] = $this->target->table('customers')->insertGetId(self::withoutId($customer));
                $created[] = $customer->name;
            }
        });

        $this->record('Customer baru V3', count(array_filter($this->customerMap, fn ($to, $from) => $to !== $from, ARRAY_FILTER_USE_BOTH)) + count($created), count($created),
            $created ? 'dibuat: '.self::list($created) : 'semua sudah ada di V2 (dicocokkan lewat nama)');
    }

    /**
     * PO yang dibuat di V3 sejak tanggal pisah. Yang customer + tanggal kirimnya
     * sudah ada PO di V2 dianggap diinput dua kali: versi V2 yang dipakai. Sisanya
     * dibawa sebagai draft dengan ID dan nomor baru (nomor lamanya bisa sudah
     * dipakai V2 pada tanggal yang sama). Hanya draft yang boleh dibawa.
     */
    private function mergePurchaseOrders(): void
    {
        $orders = $this->source->table('purchase_orders')->where('created_at', '>=', $this->since)->orderBy('id')->get();
        $carried = [];
        $duplicates = [];

        foreach ($orders as $order) {
            if ($order->status !== 'draft') {
                throw new RuntimeException("PO V3 {$order->po_number} berstatus {$order->status}; hanya PO draft yang bisa dibawa otomatis.");
            }

            $customerId = $order->customer_id === null ? null : ($this->customerMap[$order->customer_id] ?? $order->customer_id);

            $twin = $this->target->table('purchase_orders')
                ->where('customer_id', $customerId)
                ->whereDate('delivery_date', substr((string) $order->delivery_date, 0, 10))
                ->value('po_number');

            if ($twin !== null) {
                $duplicates[] = "{$order->po_number} = V2 {$twin}";

                continue;
            }

            $row = self::withoutId($order);
            $row['customer_id'] = $customerId;
            $row['po_number'] = $this->nextPoNumber((string) $order->po_number, (string) $order->created_at);
            $newId = $this->target->table('purchase_orders')->insertGetId($row);

            $items = $this->source->table('purchase_order_items')->where('purchase_order_id', $order->id)->orderBy('id')->get()
                ->map(function ($item) use ($newId) {
                    $data = self::withoutId($item);
                    $data['purchase_order_id'] = $newId;
                    $data['product_id'] = $data['product_id'] === null ? null : ($this->productMap[$data['product_id']] ?? $data['product_id']);

                    return $data;
                })->all();

            if ($items) {
                $this->target->table('purchase_order_items')->insert($items);
            }

            $carried[] = "{$order->po_number} → {$row['po_number']}";
        }

        if ($duplicates) {
            $this->warnings[] = 'PO V3 yang juga diinput di V2, memakai versi V2: '.self::list($duplicates);
        }

        $this->record('PO draft V3', $orders->count(), count($carried),
            count($duplicates).' dobel dilewati; dibawa: '.(self::list($carried) ?: '-'));
    }

    /** Nomor PO berikutnya pada tanggal PO itu dibuat, di database tujuan. */
    private function nextPoNumber(string $original, string $createdAt): string
    {
        $prefix = preg_match('/^(.*)-\d{8}-\d{4}$/', $original, $m) ? $m[1] : 'PO';
        $date = date('Ymd', strtotime($createdAt));

        $max = $this->target->table('purchase_orders')->where('po_number', 'like', "{$prefix}-{$date}-%")->max('po_number');
        $seq = $max && preg_match('/(\d{4})$/', $max, $m) ? (int) $m[1] : 0;

        return sprintf('%s-%s-%04d', $prefix, $date, $seq + 1);
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    private function firstColumn(string $table): string
    {
        return $this->source->getSchemaBuilder()->getColumnListing($table)[0];
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
     * Baris ber-ID sama dianggap baris yang sama dari dump V2 bila namanya sama,
     * atau waktu dibuatnya sama dan sebelum tanggal pisah (nama bisa diganti tim
     * menu). Baris yang dibuat sesudah tanggal pisah dibuat terpisah di dua
     * sistem, jadi waktu yang kebetulan sama bukan bukti.
     */
    private function sameOrigin(object $a, object $b): bool
    {
        return self::norm($a->name) === self::norm($b->name)
            || ((string) $a->created_at === (string) $b->created_at && (string) $a->created_at < $this->since);
    }

    /** Kunci nama menu yang tidak peka urutan kata: "Ayam Geprek Fillet" = "Ayam Fillet Geprek". */
    private static function nameKey(?string $name): string
    {
        $words = preg_split('/\s+/', self::norm($name), -1, PREG_SPLIT_NO_EMPTY);
        sort($words);

        return implode(' ', $words);
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
