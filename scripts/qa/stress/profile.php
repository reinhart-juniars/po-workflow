<?php

/**
 * Profil query per halaman (staging saja): jumlah query, waktu server, waktu DB,
 * query terlambat, dan pola N+1 (SQL yang sama berulang > 10 kali).
 *
 *   DB_DATABASE=po_workflow_staging php scripts/qa/stress/profile.php [label] [filter]
 *
 * Hasil JSON ditulis ke storage/app/qa-stress/. Pengguna: QA_USER (nama), bawaan
 * Owner pertama. QA_ORDER = id SPK Produksi untuk halaman kebutuhan/bahan.
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'po_workflow_staging') {
    fwrite(STDERR, "Bukan staging, berhenti.\n");
    exit(1);
}

$label = $argv[1] ?? 'run';
$filter = $argv[2] ?? null;
$order = (int) (getenv('QA_ORDER') ?: App\Models\ProductionOrder::query()->latest('id')->value('id'));
$recipe = (int) (getenv('QA_RECIPE') ?: App\Models\Recipe::query()->value('id'));
$P = 'date_from=2026-09-01&date_to=2026-09-30';
$Y = 'date_from=2026-01-01&date_to=2026-12-31';

$pages = [
    // Owner
    '/owner-app', "/owner-app?$Y", '/owner-app/users', "/owner-app/hpp-analysis?$P", '/owner-app/audit-logs',
    // Admin
    '/admin-app', '/admin-app/orders', '/admin-app/spk', '/admin-app/products', '/admin-app/customers', '/admin-app/delivery',
    "/admin-app/reports/orders?$P", "/admin-app/reports/production?$P", "/admin-app/reports/delivery?$P", "/admin-app/reports/bestseller?$P", '/admin-app/reports/missing-costs', '/admin-app/audit-logs',
    // Accounting
    '/accounting-app', "/accounting-app/reports/cashflow?$P", "/accounting-app/reports/profit-loss?$P", '/accounting-app/reports/profit-loss/yearly?year=2026',
    '/accounting-app/reports/balance-sheet?date=2026-09-30', "/accounting-app/reports/final?$P", "/accounting-app/reports/sales?$P", "/accounting-app/reports/sales?$Y",
    "/accounting-app/reports/inventory-usage?$P", '/accounting-app/expenses', '/accounting-app/other-incomes', '/accounting-app/payables', '/accounting-app/periods', '/accounting-app/sales-closings',
    // Sales / Marketing / Production / Delivery
    '/sales-app', "/sales-app?$Y&status=all", '/sales-app/barang-sisa', "/sales-app/reports/final-retur?$P", "/sales-app/reports/waste?$P", "/sales-app/reports/sales?$P", "/sales-app/reports/sales?$P&view_mode=full",
    '/marketing-app', '/marketing-app/catalog', '/production-app', '/delivery-app',
    // Inventory (Filament)
    '/inventory/dashboard', '/inventory/inventory-items', '/inventory/suppliers', '/inventory/inventory-purchases', '/inventory/stock-opnames', '/inventory/opname-bahan',
    '/inventory/recipes', "/inventory/recipes/{$recipe}/hpp", '/inventory/pencocokan-menu', '/inventory/pekerjaan-menu', '/inventory/inventory-unit-conversions/butuh-aturan',
    '/inventory/recipe-mismatches', '/inventory/hpp-comparison-report', '/inventory/susut-bahan', '/inventory/menu-tidak-diproduksi', '/inventory/inventory-movements',
    '/inventory/production-orders', '/inventory/requisitions', '/inventory/pengaturan-inventory',
    "/inventory/production-orders/{$order}/kebutuhan", "/inventory/production-orders/{$order}/bahan",
    "/inventory/production-orders/{$order}/plating", "/inventory/production-orders/{$order}/edit",
    // Global
    '/search/suggest?q=nasi', '/search?q=nasi',
];

$owner = getenv('QA_USER')
    ? User::query()->where('name', getenv('QA_USER'))->firstOrFail()
    : User::role('owner')->firstOrFail();
$results = [];

foreach ($pages as $uri) {
    if ($filter && ! str_contains($uri, $filter)) {
        continue;
    }

    $queries = [];
    DB::flushQueryLog();
    $listener = function ($q) use (&$queries) {
        $queries[] = ['sql' => $q->sql, 'ms' => $q->time];
    };
    DB::listen($listener);

    $request = Request::create('http://po-workflow.test'.$uri, 'GET');
    $app['auth']->guard('web')->setUser($owner);
    $request->setUserResolver(fn () => $owner);

    $t = microtime(true);
    try {
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
    } catch (Throwable $e) {
        $status = 'EXC '.substr($e->getMessage(), 0, 80);
    }
    $ms = (microtime(true) - $t) * 1000;
    $kernel->terminate($request, $response ?? null);

    // Listener DB tidak bisa dilepas satu per satu: ganti dispatcher event query lewat flag.
    $dbMs = array_sum(array_column($queries, 'ms'));
    $norm = array_count_values(array_map(fn ($q) => preg_replace('/\s+/', ' ', $q['sql']), $queries));
    arsort($norm);
    $repeat = array_filter($norm, fn ($c) => $c > 10);
    usort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);

    $results[] = [
        'uri' => $uri, 'status' => $status, 'ms' => round($ms), 'db_ms' => round($dbMs), 'queries' => count($queries),
        'n_plus_1' => array_slice($repeat, 0, 3, true),
        'slowest' => array_map(fn ($q) => ['ms' => round($q['ms'], 1), 'sql' => substr(preg_replace('/\s+/', ' ', $q['sql']), 0, 220)], array_slice($queries, 0, 3)),
    ];

    printf("%-70s %6s %6d ms %6d db %5d q%s\n", substr($uri, 0, 70), $status, $ms, $dbMs, count($queries), $repeat ? '  N+1x'.max($repeat) : '');

    // Reset listener: kosongkan array referensi dengan listener baru pada iterasi berikut.
    $app['events']->forget(Illuminate\Database\Events\QueryExecuted::class);
    $app['auth']->forgetGuards();
}

@mkdir(storage_path('app/qa-stress'), 0775, true);
file_put_contents(storage_path("app/qa-stress/profile-$label.json"), json_encode($results, JSON_PRETTY_PRINT));
