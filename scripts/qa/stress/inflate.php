<?php

/**
 * Gelembungkan data po_workflow_staging ±12x untuk stress test.
 *
 *   php artisan db:clone-to-staging --force   (salinan segar dulu)
 *   DB_DATABASE=po_workflow_staging php scripts/qa/stress/inflate.php
 *
 * Salinan k=1..9 : riwayat mundur 6*k bulan (±5 tahun ke belakang).
 * Salinan k=10,11: tanggal sama (kepadatan bulan berjalan 3x -- lebih banyak customer).
 * ID salinan = id + k*10.000.000 supaya relasi antar-salinan tinggal digeser.
 * Inventory (kartu stok, pembelian, opname) dibuat sintetis 5 tahun.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'po_workflow_staging') {
    fwrite(STDERR, "Bukan staging, berhenti.\n");
    exit(1);
}
if (DB::table('purchase_orders')->where('id', '>', 10000000)->exists()) {
    fwrite(STDERR, "Sudah digelembungkan.\n");
    exit(1);
}

DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::statement('SET SESSION unique_checks=0');

$K = 10000000;
$shift = fn (int $k) => $k <= 9 ? 6 * $k : 0; // bulan

/**
 * @param  array<string, string>  $fk  kolom => 'shift' (ikut digeser +k*K) | 'null' | 'keep-if-dense'
 * @param  list<string>  $unique  kolom teks unik yang diberi akhiran -k
 * @param  list<string>|null  $nullCols  kolom yang dikosongkan
 */
function copyTable(string $table, int $k, array $fk = [], array $unique = [], array $nullCols = [], bool $hasId = true, ?int $onlyUpToK = null, bool $ignore = false): void
{
    global $K, $shift;
    if ($onlyUpToK !== null && $k > $onlyUpToK) {
        return;
    }
    $months = $shift($k);
    $cols = collect(Schema::getColumns($table));
    $select = $cols->map(function ($c) use ($k, $K, $fk, $unique, $nullCols, $months) {
        $n = $c['name'];
        $q = "`$n`";
        if ($n === 'id') {
            return "$q + ".($k * $K);
        }
        if (in_array($n, $nullCols, true)) {
            return 'NULL';
        }
        if (isset($fk[$n])) {
            return match ($fk[$n]) {
                'shift' => "IF($q IS NULL, NULL, $q + ".($k * $K).')',
                'keep-if-dense' => $months === 0 ? $q : 'NULL',
            };
        }
        if (in_array($n, $unique, true)) {
            return "IF($q IS NULL, NULL, CONCAT($q, '-$k'))";
        }
        if ($months > 0 && in_array($c['type_name'], ['date', 'datetime', 'timestamp'], true)) {
            return "IF($q IS NULL, NULL, DATE_SUB($q, INTERVAL $months MONTH))";
        }

        return $q;
    })->implode(', ');
    $names = $cols->map(fn ($c) => "`{$c['name']}`")->implode(', ');
    $where = $hasId ? 'WHERE id < '.$K : '';
    $n = DB::affectingStatement('INSERT '.($ignore ? 'IGNORE ' : '')."INTO `$table` ($names) SELECT $select FROM `$table` $where");
    echo "  $table k=$k +$n\n";
}

$t0 = microtime(true);
for ($k = 1; $k <= 11; $k++) {
    echo "salinan $k\n";
    copyTable('purchase_orders', $k, [], ['po_number']);
    copyTable('purchase_order_items', $k, ['purchase_order_id' => 'shift']);
    copyTable('spks', $k, [], ['spk_code']);
    // pivot tanpa id
    DB::affectingStatement('INSERT INTO spk_purchase_orders (spk_id, purchase_order_id'.(Schema::hasColumn('spk_purchase_orders', 'created_at') ? ', created_at, updated_at' : '').')
        SELECT spk_id + '.($k * $K).', purchase_order_id + '.($k * $K).(Schema::hasColumn('spk_purchase_orders', 'created_at') ? ', created_at, updated_at' : '').' FROM spk_purchase_orders WHERE spk_id < '.$K);
    copyTable('delivery_orders', $k, [], ['do_code']);
    DB::affectingStatement('INSERT INTO delivery_order_purchase_orders (delivery_order_id, purchase_order_id)
        SELECT delivery_order_id + '.($k * $K).', purchase_order_id + '.($k * $K).' FROM delivery_order_purchase_orders WHERE delivery_order_id < '.$K);
    copyTable('sales_daily_closings', $k, [], [], [], true, 9, true);
    copyTable('sales_actuals', $k, ['delivery_order_id' => 'shift', 'sales_daily_closing_id' => $k <= 9 ? 'shift' : 'keep-if-dense']);
    copyTable('sales_actual_items', $k, ['sales_actual_id' => 'shift', 'purchase_order_item_id' => 'shift', 'purchase_order_id' => 'shift', 'source_sales_actual_item_id' => 'shift']);
    copyTable('cash_outs', $k, ['payable_id' => 'keep-if-dense']);
    copyTable('other_incomes', $k);
    copyTable('audit_logs', $k, ['purchase_order_id' => 'shift'], [], ['before_json', 'after_json']);
}

// ---- Inventory sintetis: 5 tahun ------------------------------------------
echo "inventory sintetis\n";
$items = DB::table('inventory_items')->whereNotNull('parent_id')->pluck('unit_price', 'id')->all();
$ids = array_keys($items);
mt_srand(42);
$start = new DateTime('2021-10-01');
$end = new DateTime('2026-09-30');
$mov = $pur = $opn = [];
$flush = function () use (&$mov, &$pur, &$opn) {
    foreach (['inventory_movements' => &$mov, 'inventory_purchases' => &$pur, 'stock_opnames' => &$opn] as $t => &$rows) {
        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table($t)->insert($chunk);
        }
        $rows = [];
    }
};
for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $date = $d->format('Y-m-d');
    $now = $date.' 12:00:00';
    // ±120 baris pemakaian & 20 pembelian per hari
    foreach (array_rand(array_flip($ids), 120) as $id) {
        $qty = mt_rand(1, 5000) / 10;
        $price = (float) ($items[$id] ?: 10);
        $mov[] = ['inventory_item_id' => $id, 'moved_at' => $date, 'type' => 'usage', 'qty' => -$qty, 'unit' => 'gram', 'unit_price' => $price, 'total_value' => -round($qty * $price, 2), 'notes' => 'sintetis', 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_rand(array_flip($ids), 20) as $id) {
        $qty = mt_rand(10, 20000) / 10;
        $price = (float) ($items[$id] ?: 10);
        $pur[] = ['inventory_item_id' => $id, 'transaction_date' => $date, 'qty' => $qty, 'unit_cost' => $price, 'total_value' => round($qty * $price, 2), 'condition' => 'good', 'payment_type' => 'cash', 'supplier_name' => 'Pasar Induk', 'notes' => 'sintetis', 'created_at' => $now, 'updated_at' => $now];
        $mov[] = ['inventory_item_id' => $id, 'moved_at' => $date, 'type' => 'purchase', 'qty' => $qty, 'unit' => 'gram', 'unit_price' => $price, 'total_value' => round($qty * $price, 2), 'notes' => 'sintetis', 'created_at' => $now, 'updated_at' => $now];
    }
    if ($d->format('d') === $d->format('t')) { // opname akhir bulan tiap bahan
        foreach ($ids as $id) {
            $opn[] = ['inventory_item_id' => $id, 'opname_date' => $date, 'qty' => mt_rand(0, 5000) / 10, 'unit_cost' => (float) ($items[$id] ?: 10), 'total_value' => mt_rand(0, 500000), 'notes' => 'sintetis', 'created_at' => $now, 'updated_at' => $now];
        }
    }
    if (count($mov) > 20000) {
        $flush();
    }
}
$flush();

DB::statement('SET FOREIGN_KEY_CHECKS=1');
foreach (['purchase_orders', 'purchase_order_items', 'sales_actuals', 'sales_actual_items', 'delivery_orders', 'spks', 'cash_outs', 'other_incomes', 'audit_logs', 'inventory_movements', 'inventory_purchases', 'stock_opnames'] as $t) {
    DB::statement("ANALYZE TABLE `$t`");
    printf("%-26s %8d\n", $t, DB::table($t)->count());
}
printf("selesai %.0f dtk\n", microtime(true) - $t0);
