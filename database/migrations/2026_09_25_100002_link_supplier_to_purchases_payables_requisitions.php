<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menautkan pembelian bahan, form kebutuhan, dan hutang ke Master Supplier.
 *
 * supplier_name tetap ada sebagai salinan nama saat transaksi: laporan hutang,
 * neraca, dan export membacanya langsung, dan nama di transaksi lama tidak
 * boleh berubah hanya karena master supplier diganti namanya.
 *
 * Master awal diisi dari nama supplier yang sudah dipakai di pembelian dan
 * form kebutuhan (nama yang sama beda huruf besar/kecil dianggap satu).
 * Hutang hanya ditautkan bila namanya cocok -- nama di hutang saldo awal
 * sering berupa keterangan ("Saldo Awal Hutang #3"), bukan supplier.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['inventory_purchases', 'requisitions', 'payables'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                // restrict: supplier yang sudah bertransaksi dinonaktifkan,
                // bukan dihapus, supaya tautannya tidak hilang diam-diam.
                $table->foreignId('supplier_id')->nullable()->after('supplier_name')->constrained()->restrictOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('supplier_id');
            });
        }
    }

    private function backfill(): void
    {
        $key = fn (?string $name) => mb_strtolower(trim((string) $name));
        $now = now();

        /** @var array<string, int> $ids nama (huruf kecil) => supplier_id */
        $ids = [];

        foreach (['inventory_purchases', 'requisitions'] as $source) {
            DB::table($source)
                ->whereNotNull('supplier_name')
                ->orderBy('id')
                ->pluck('supplier_name')
                ->each(function (string $name) use (&$ids, $key, $now) {
                    $name = trim($name);

                    if ($name === '' || isset($ids[$key($name)])) {
                        return;
                    }

                    $ids[$key($name)] = DB::table('suppliers')->insertGetId([
                        'name' => mb_substr($name, 0, 255),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        }

        if ($ids === []) {
            return;
        }

        foreach ($this->tables as $tableName) {
            DB::table($tableName)
                ->whereNotNull('supplier_name')
                ->select(['id', 'supplier_name'])
                ->lazyById()
                ->each(function (object $row) use ($tableName, $ids, $key) {
                    $supplierId = $ids[$key($row->supplier_name)] ?? null;

                    if ($supplierId !== null) {
                        DB::table($tableName)->where('id', $row->id)->update(['supplier_id' => $supplierId]);
                    }
                });
        }

        // Bahan yang pernah dibeli dari supplier otomatis tercatat sebagai
        // bahan yang dipasoknya.
        $pairs = DB::table('inventory_purchases')
            ->whereNotNull('supplier_id')
            ->select(['supplier_id', 'inventory_item_id'])
            ->distinct()
            ->get()
            ->map(fn (object $pair) => [
                'supplier_id' => $pair->supplier_id,
                'inventory_item_id' => $pair->inventory_item_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($pairs, 500) as $chunk) {
            DB::table('inventory_item_supplier')->insert($chunk);
        }
    }
};
