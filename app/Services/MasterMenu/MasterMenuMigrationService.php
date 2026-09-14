<?php

namespace App\Services\MasterMenu;

use App\Models\InventoryItem;
use App\Models\InventoryItemPriceHistory;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\ProductionWorker;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeTask;
use App\Services\RecipeMismatchResolver;
use App\Support\Units\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan master data Master Menu Revamp ke Modul Inventory Terpadu.
 *
 * Dirancang untuk dijalankan berkali-kali, bukan sekali seumur hidup: data di
 * sisi klien masih terus diisi sampai hari cutover, jadi perpindahan ini harus
 * bisa diulang tanpa menggandakan apa pun. Pencocokannya lewat kolom jejak
 * (source_ingredient_id, source_recipe_id), bukan lewat nama, supaya bahan yang
 * namanya diperbaiki di sumber tetap dikenali sebagai baris yang sama.
 *
 * Sumber hanya dibaca; tidak ada satu pun tulisan balik ke app.db.
 */
class MasterMenuMigrationService
{
    /** Nama bucket tujuan untuk bahan berkategori kemasan. */
    protected const PACKAGING_KEYWORDS = ['kemasan', 'packaging'];

    public function __construct(
        protected MasterMenuSource $source
    ) {}

    /**
     * Jalankan seluruh perpindahan dalam satu transaksi.
     *
     * @return array<string, mixed> Ringkasan jumlah baris per tahap.
     */
    public function run(bool $dryRun = false): array
    {
        $summary = [];

        try {
            DB::transaction(function () use ($dryRun, &$summary) {
                $summary['bahan'] = $this->migrateIngredients();
                $summary['histori_harga'] = $this->migratePriceHistories();
                $summary['resep'] = $this->migrateRecipes();
                $summary['baris_resep'] = $this->migrateRecipeItems();
                // Baris resep disusun ulang setiap kali perpindahan dijalankan,
                // jadi tautan hasil rekonsiliasi bersama klien harus dipasang
                // kembali di sini. Tanpa langkah ini, tiap penyegaran data
                // menghapus pekerjaan itu dan mismatch-nya muncul lagi.
                $summary['keputusan'] = app(RecipeMismatchResolver::class)->reapplyAll();
                // Daftar bahan belum cocok disusun oleh layanan rekonsiliasi, yang
                // juga dipakai import resep -- daftarnya tidak boleh punya dua
                // penyusun yang bisa berbeda hasilnya.
                $summary['mismatch'] = app(RecipeMismatchResolver::class)->rebuild();
                $summary['pelaksana'] = $this->migrateWorkers();
                $summary['template_kerja'] = $this->migrateRecipeTasks();
                $summary['spk_produksi'] = $this->migrateProductionOrders();

                if ($dryRun) {
                    // Uji-jalan tetap menempuh seluruh jalur tulis supaya angkanya
                    // mencerminkan hasil sungguhan, lalu dibatalkan lewat exception.
                    //
                    // Sengaja bukan DB::rollBack() manual: pemanggilan itu membatalkan
                    // transaksi terluar apa pun yang sedang berjalan, termasuk transaksi
                    // pembungkus milik test, sehingga skema basis data ikut hilang.
                    // Dengan exception, Laravel yang mengurus tingkat transaksinya.
                    throw new DryRunCompleted;
                }
            });
        } catch (DryRunCompleted) {
            // Perubahan sudah dibatalkan oleh Laravel; ringkasannya tetap dipakai.
        }

        return $summary;
    }

    /**
     * Bucket induk untuk sebuah kategori bahan.
     *
     * Bahan kemasan bergabung ke bucket Packaging, sisanya ke Bahan Baku --
     * mengikuti pembagian yang sudah dipakai Laba Rugi selama ini.
     */
    protected function bucketFor(?string $category): InventoryItem
    {
        $needle = mb_strtolower(trim((string) $category));

        $isPackaging = collect(self::PACKAGING_KEYWORDS)
            ->contains(fn (string $keyword) => str_contains($needle, $keyword));

        $target = $isPackaging
            ? InventoryItem::CATEGORY_PACKAGING
            : InventoryItem::CATEGORY_RAW_MATERIAL;

        $bucket = InventoryItem::query()
            ->whereNull('parent_id')
            ->where('category', $target)
            ->orderBy('id')
            ->first();

        if ($bucket) {
            return $bucket;
        }

        // Bucket dibuat hanya bila memang belum ada, mis. di basis data uji.
        return InventoryItem::query()->create([
            'name' => $target === InventoryItem::CATEGORY_PACKAGING ? 'Packaging' : 'Bahan Baku',
            'unit' => 'All',
            'category' => $target,
            'is_active' => true,
        ]);
    }

    /**
     * Satuan bahan, dinormalkan bila dikenali registri.
     *
     * Yang tidak dikenali disimpan apa adanya, bukan diganti tebakan -- satuan
     * asing lebih baik terlihat mencolok saat rekonsiliasi.
     */
    protected function normalizeUnit(?string $raw): string
    {
        $unit = Unit::tryFromAlias($raw);

        if ($unit !== null) {
            return $unit->value;
        }

        $text = trim((string) $raw);

        return $text !== '' ? $text : 'pcs';
    }

    /** @return array<string, int> */
    protected function migrateIngredients(): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->source->table('ingredients')->orderBy('id')->cursor() as $row) {
            $bucket = $this->bucketFor($row->category);

            $attributes = [
                'parent_id' => $bucket->id,
                'name' => trim((string) $row->name),
                'unit' => $this->normalizeUnit($row->pack_unit),
                'category' => $bucket->category,
                'ingredient_group' => trim((string) ($row->category ?? '')) ?: null,
                'pack_qty' => (float) ($row->pack_qty ?? 0) ?: null,
                'pack_price' => (float) ($row->pack_price ?? 0) ?: null,
                'unit_price' => (float) ($row->unit_price ?? 0) ?: null,
                'is_prepared' => (bool) ($row->is_prepared ?? false),
                'description' => $row->notes ?: null,
                'is_active' => true,
            ];

            $existing = InventoryItem::query()
                ->where('source_ingredient_id', $row->id)
                ->first();

            // Histori harga dibawa dari tabel sumber (migratePriceHistories),
            // jadi pencatatan otomatis dimatikan supaya tidak berganda.
            if ($existing) {
                InventoryItem::withoutPriceHistory(fn () => $existing->update($attributes));
                $updated++;

                continue;
            }

            InventoryItem::withoutPriceHistory(fn () => InventoryItem::query()->create($attributes + ['source_ingredient_id' => $row->id]));
            $created++;
        }

        return ['baru' => $created, 'diperbarui' => $updated];
    }

    /**
     * Bulatkan angka ke skala kolom tujuan sebelum disimpan.
     *
     * MySQL memangkas sendiri kelebihan desimalnya, SQLite tidak. Tanpa
     * pembulatan di sini, kedua basis data menyimpan angka yang berbeda untuk
     * baris yang sama, dan pemeriksaan duplikat hanya bekerja di salah satunya.
     */
    protected function scale(mixed $value, int $decimals): ?float
    {
        return $value === null ? null : round((float) $value, $decimals);
    }

    /** @return array<string, int> */
    protected function migratePriceHistories(): array
    {
        $itemBySource = InventoryItem::query()
            ->whereNotNull('source_ingredient_id')
            ->pluck('id', 'source_ingredient_id');

        $inserted = 0;
        $skipped = 0;

        foreach ($this->source->table('ingredient_price_history')->orderBy('id')->cursor() as $row) {
            $itemId = $itemBySource[$row->ingredient_id] ?? null;

            if ($itemId === null) {
                $skipped++;

                continue;
            }

            // Histori tidak punya kolom jejak sendiri, jadi kesamaannya dinilai
            // dari kombinasi bahan, waktu, dan nilai -- cukup untuk mencegah
            // penggandaan saat perpindahan dijalankan ulang.
            $exists = InventoryItemPriceHistory::query()
                ->where('inventory_item_id', $itemId)
                ->where('action', $row->action)
                ->where('created_at', $row->created_at)
                ->where(function ($query) use ($row) {
                    // Dibandingkan lewat kolomnya langsung, bukan lewat ekspresi:
                    // membungkus kolom dalam COALESCE menghilangkan type affinity
                    // di SQLite, sehingga angka diadu dengan teks dan tidak pernah
                    // cocok -- pemeriksaan duplikatnya lolos diam-diam.
                    // Dibulatkan ke skala kolom (4 desimal) sebelum diadu:
                    // harga seperti 3291,666666... tersimpan sebagai 3291,6667,
                    // sehingga membandingkan nilai mentahnya tidak pernah cocok.
                    $row->new_unit_price === null
                        ? $query->whereNull('new_unit_price')
                        : $query->where('new_unit_price', round((float) $row->new_unit_price, 4));
                })
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            InventoryItemPriceHistory::query()->create([
                'inventory_item_id' => $itemId,
                'old_unit_price' => $this->scale($row->old_unit_price, 4),
                'new_unit_price' => $this->scale($row->new_unit_price, 4),
                'old_pack_price' => $this->scale($row->old_pack_price, 2),
                'new_pack_price' => $this->scale($row->new_pack_price, 2),
                'action' => $row->action,
                'source' => 'migrasi',
                'note' => $row->note,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);

            $inserted++;
        }

        return ['dipindahkan' => $inserted, 'dilewati' => $skipped];
    }

    /** @return array<string, int> */
    protected function migrateRecipes(): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->source->table('recipes')->orderBy('id')->cursor() as $row) {
            $attributes = [
                'name' => trim((string) $row->name),
                'jenis' => ($row->jenis ?? 'utama') === 'sub' ? Recipe::JENIS_SUB : Recipe::JENIS_UTAMA,
                'kategori' => $row->kategori ?: null,
                'yield_qty' => (float) ($row->yield_qty ?? 1) ?: 1,
                'yield_unit' => $row->yield_unit ?: 'porsi',
                'ohc_pct' => (float) ($row->ohc_pct ?? 0.40),
                'profit_pct' => (float) ($row->profit_pct ?? 0.25),
                'target_price' => $row->target_price,
                'snapshot_hpp' => $row->snapshot_hpp,
                'snapshot_ohc' => $row->snapshot_ohc,
                'snapshot_profit' => $row->snapshot_profit,
                'notes' => $row->notes,
                'source_sheet' => $row->source_sheet,
                'is_active' => true,
            ];

            $existing = Recipe::query()->where('source_recipe_id', $row->id)->first();

            if ($existing) {
                // product_id sengaja tidak disentuh: itu hasil keputusan mapping
                // bersama klien, bukan data yang datang dari sumber.
                $existing->update($attributes);
                $updated++;

                continue;
            }

            Recipe::query()->create($attributes + ['source_recipe_id' => $row->id]);
            $created++;
        }

        return ['baru' => $created, 'diperbarui' => $updated];
    }

    /** @return array<string, int> */
    protected function migrateRecipeItems(): array
    {
        $recipeBySource = Recipe::query()
            ->whereNotNull('source_recipe_id')
            ->pluck('id', 'source_recipe_id');

        $itemBySource = InventoryItem::query()
            ->whereNotNull('source_ingredient_id')
            ->pluck('id', 'source_ingredient_id');

        $inserted = 0;
        $unmatched = 0;

        // Baris resep tidak punya jejak sendiri di tujuan, dan menyamakannya
        // baris-per-baris rapuh karena urutannya bisa berubah di sumber. Lebih
        // aman menyusun ulang seluruh baris milik resep yang ikut berpindah.
        RecipeItem::query()
            ->whereIn('recipe_id', $recipeBySource->values())
            ->delete();

        foreach ($this->source->table('recipe_items')->orderBy('recipe_id')->orderBy('sort_order')->orderBy('id')->cursor() as $row) {
            $recipeId = $recipeBySource[$row->recipe_id] ?? null;

            if ($recipeId === null) {
                continue;
            }

            $inventoryItemId = $row->ingredient_id ? ($itemBySource[$row->ingredient_id] ?? null) : null;
            $refRecipeId = $row->ref_recipe_id ? ($recipeBySource[$row->ref_recipe_id] ?? null) : null;

            if ($inventoryItemId === null && $refRecipeId === null) {
                $unmatched++;
            }

            RecipeItem::query()->create([
                'recipe_id' => $recipeId,
                'sort_order' => (int) ($row->sort_order ?? 0),
                'section' => $row->section,
                'inventory_item_id' => $inventoryItemId,
                'ref_recipe_id' => $refRecipeId,
                'raw_name' => trim((string) $row->raw_name),
                'qty' => (float) ($row->qty ?? 0),
                'unit' => $row->unit,
                'unit_price_snapshot' => $row->unit_price_snapshot,
                'notes' => $row->notes,
            ]);

            $inserted++;
        }

        return ['dipindahkan' => $inserted, 'belum_tertaut' => $unmatched];
    }

    /**
     * Pelaksana dapur dari master_person.
     *
     * Nama tugas (master_task) sengaja tidak dipindahkan sebagai master
     * sendiri: di Master Menu pun pilihannya sudah diambil dari tugas yang
     * benar-benar dipakai di template kerja, dan itulah yang dipakai di sini.
     *
     * @return array<string, int>
     */
    protected function migrateWorkers(): array
    {
        if (! $this->source->connection()->getSchemaBuilder()->hasTable('master_person')) {
            return ['baru' => 0];
        }

        $created = 0;

        foreach ($this->source->table('master_person')->orderBy('id')->cursor() as $row) {
            $name = trim((string) $row->name);

            if ($name === '') {
                continue;
            }

            $worker = ProductionWorker::query()->firstOrCreate(['name' => $name], ['is_active' => true]);

            if ($worker->wasRecentlyCreated) {
                $created++;
            }
        }

        return ['baru' => $created];
    }

    /**
     * Template kerja per menu (menu_tasks).
     *
     * Seperti baris resep, disusun ulang seluruhnya untuk resep yang ikut
     * berpindah: template tidak punya jejak sendiri di tujuan.
     *
     * @return array<string, int>
     */
    protected function migrateRecipeTasks(): array
    {
        if (! $this->source->connection()->getSchemaBuilder()->hasTable('menu_tasks')) {
            return ['dipindahkan' => 0];
        }

        $recipeBySource = Recipe::query()->whereNotNull('source_recipe_id')->pluck('id', 'source_recipe_id');

        RecipeTask::query()->whereIn('recipe_id', $recipeBySource->values())->delete();

        $inserted = 0;

        foreach ($this->source->table('menu_tasks')->orderBy('recipe_id')->orderBy('sort_order')->orderBy('id')->cursor() as $row) {
            $recipeId = $recipeBySource[$row->recipe_id] ?? null;

            if ($recipeId === null) {
                continue;
            }

            RecipeTask::query()->create([
                'recipe_id' => $recipeId,
                'sort_order' => (int) ($row->sort_order ?? 0),
                'task' => $row->tugas ?: null,
                'object' => $row->objek ?: null,
                'quantity_text' => $row->jumlah ?: null,
                'pic' => $row->pic ?: null,
            ]);

            $inserted++;
        }

        return ['dipindahkan' => $inserted];
    }

    /**
     * SPK Master Menu beserta barisnya, lembar kerja produksinya, dan
     * keterangan order yang melahirkannya -- semuanya sebagai histori.
     *
     * Ke depan SPK Produksi lahir dari PO po-workflow, jadi yang dipindahkan
     * di sini tidak punya spk_id dan barisnya bertanda master_menu. Statusnya
     * ditandai selesai: ini catatan masa lalu, bukan pekerjaan yang menunggu
     * form kebutuhan.
     *
     * @return array<string, int>
     */
    protected function migrateProductionOrders(): array
    {
        $schema = $this->source->connection()->getSchemaBuilder();

        if (! $schema->hasTable('spk')) {
            return ['baru' => 0, 'diperbarui' => 0, 'baris' => 0, 'tugas' => 0];
        }

        $recipeBySource = Recipe::query()->whereNotNull('source_recipe_id')->pluck('id', 'source_recipe_id');

        // Order Master Menu hanya menyumbang keterangan (judul, pelanggan) ke
        // SPK yang di-generate darinya.
        $orderBySpk = $schema->hasTable('orders')
            ? $this->source->table('orders')->whereNotNull('spk_id')->get()->keyBy('spk_id')
            : collect();

        $created = 0;
        $updated = 0;
        $lines = 0;
        $tasks = 0;

        foreach ($this->source->table('spk')->orderBy('id')->cursor() as $row) {
            $order = $orderBySpk->get($row->id);
            $notes = trim(implode("\n", array_filter([
                $row->notes,
                $order ? 'Order Master Menu #'.$order->id.($order->customer ? ' — '.$order->customer : '') : null,
            ])));

            $attributes = [
                'title' => $row->title ?: 'SPK Master Menu #'.$row->id,
                'production_date' => $row->spk_date ?: ($row->created_at ? substr($row->created_at, 0, 10) : now()->toDateString()),
                'production_time' => $row->spk_time ?: null,
                'status' => ProductionOrder::STATUS_COMPLETED,
                'notes' => $notes !== '' ? $notes : null,
            ];

            $production = ProductionOrder::query()->where('source_spk_id', $row->id)->first();

            if ($production) {
                $production->update($attributes);
                $updated++;
            } else {
                $production = ProductionOrder::query()->create($attributes + [
                    'source_spk_id' => $row->id,
                    'completed_at' => $row->spk_date ? $row->spk_date.' 00:00:00' : now(),
                ]);
                $created++;
            }

            // Baris & lembar kerja disusun ulang seluruhnya, seperti baris resep.
            $production->lines()->where('source', ProductionOrderLine::SOURCE_MASTER_MENU)->delete();

            foreach ($this->source->table('spk_orders')->where('spk_id', $row->id)->orderBy('sort_order')->orderBy('id')->cursor() as $line) {
                $recipeId = $line->recipe_id ? ($recipeBySource[$line->recipe_id] ?? null) : null;

                $production->lines()->create([
                    'sort_order' => (int) ($line->sort_order ?? 0),
                    'kind' => ($line->kind === 'menu' && $recipeId) ? ProductionOrderLine::KIND_MENU : ProductionOrderLine::KIND_MANUAL,
                    'source' => ProductionOrderLine::SOURCE_MASTER_MENU,
                    'recipe_id' => $recipeId,
                    'label' => $line->label ?: ($recipeId ? Recipe::query()->whereKey($recipeId)->value('name') : null),
                    'qty' => (float) ($line->qty ?? 0),
                    'unit' => $line->unit ?: null,
                    'remark' => $line->remark ?: null,
                ]);

                $lines++;
            }

            if ($schema->hasTable('produksi')) {
                $production->tasks()->delete();

                $produksiIds = $this->source->table('produksi')->where('spk_id', $row->id)->pluck('id');

                foreach ($this->source->table('produksi_rows')->whereIn('produksi_id', $produksiIds)->orderBy('sort_order')->orderBy('id')->cursor() as $task) {
                    $production->tasks()->create([
                        'sort_order' => (int) ($task->sort_order ?? 0),
                        'recipe_id' => $task->recipe_id ? ($recipeBySource[$task->recipe_id] ?? null) : null,
                        'menu_label' => $task->menu_label ?: null,
                        'worker_name' => $task->nama ?: null,
                        'task' => $task->tugas ?: null,
                        'object' => $task->objek ?: null,
                        'quantity_text' => $task->jumlah ?: null,
                        'is_done' => true,
                    ]);

                    $tasks++;
                }
            }
        }

        return ['baru' => $created, 'diperbarui' => $updated, 'baris' => $lines, 'tugas' => $tasks];
    }
}
