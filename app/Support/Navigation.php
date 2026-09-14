<?php

namespace App\Support;

use App\Models\User;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Route;

/**
 * Satu-satunya definisi menu 3S Business Control System.
 *
 * Dibaca oleh dua penyaji: layout Blade (`layouts.shell`) untuk halaman
 * aplikasi, dan panel Filament untuk halaman inventory/resep/produksi.
 * Keduanya menampilkan sidebar yang sama persis, jadi pengguna tidak merasa
 * berpindah "aplikasi" -- hanya berpindah halaman.
 *
 * Setiap item punya satu pemilik: 'blade' (route bernama) atau 'filament'
 * (resource/page panel). Tidak boleh ada dua item untuk domain yang sama.
 * Hak lihat mengikuti pagar sebenarnya: peran (middleware ensure.role) untuk
 * halaman Blade, izin modul (policy) untuk halaman Filament.
 */
class Navigation
{
    /** @var list<array{key: string, label: string, icon: string}> urutan grup */
    public const GROUPS = [
        ['key' => 'pesanan', 'label' => 'Pesanan', 'icon' => 'heroicon-o-clipboard-document-list'],
        ['key' => 'produksi', 'label' => 'Produksi', 'icon' => 'heroicon-o-fire'],
        ['key' => 'pengiriman', 'label' => 'Pengiriman', 'icon' => 'heroicon-o-truck'],
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'heroicon-o-shopping-bag'],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'heroicon-o-cube'],
        ['key' => 'resep', 'label' => 'Resep & HPP', 'icon' => 'heroicon-o-book-open'],
        ['key' => 'akunting', 'label' => 'Akunting', 'icon' => 'heroicon-o-banknotes'],
        ['key' => 'monitoring', 'label' => 'Monitoring', 'icon' => 'heroicon-o-eye'],
        ['key' => 'laporan', 'label' => 'Laporan Keuangan', 'icon' => 'heroicon-o-chart-bar'],
        ['key' => 'master', 'label' => 'Master Data', 'icon' => 'heroicon-o-archive-box'],
        ['key' => 'sistem', 'label' => 'Sistem', 'icon' => 'heroicon-o-cog-6-tooth'],
    ];

    /**
     * Item menu. `roles` = peran yang boleh (halaman Blade); `can` = izin
     * modul (halaman Filament). `match` = pola nama route (Blade) atau pola
     * path (Filament) untuk status aktif. `sort` mengurutkan di dalam grup.
     *
     * @return list<array{group: ?string, label: string, route?: string, url?: string, match: string|list<string>, sort: int, roles?: list<string>, can?: string, source: string, icon?: string}>
     */
    public static function items(): array
    {
        $blade = fn (string $group, string $label, string $route, string|array $match, int $sort, array $roles, ?string $icon = null) => [
            'group' => $group, 'label' => $label, 'route' => $route, 'match' => $match, 'sort' => $sort, 'roles' => $roles, 'source' => 'blade', 'icon' => $icon,
        ];
        $filament = fn (string $group, string $label, string $route, string $match, int $sort, string $can) => [
            'group' => $group, 'label' => $label, 'route' => $route, 'match' => $match, 'sort' => $sort, 'can' => $can, 'source' => 'filament',
        ];

        $admin = ['admin', 'owner', 'superadmin'];
        $akunting = ['accounting', 'owner', 'superadmin'];

        return [
            // Pesanan
            $blade('pesanan', 'Purchase Orders', 'adminapp.orders.index', 'adminapp.orders.*', 10, $admin),
            $blade('pesanan', 'Laporan PO', 'adminapp.reports.orders', 'adminapp.reports.orders*', 20, $admin),
            $blade('pesanan', 'Laporan Best Seller', 'adminapp.reports.bestseller', 'adminapp.reports.bestseller*', 30, $admin),

            // Produksi
            $blade('produksi', 'Slot SPK', 'adminapp.spk.index', 'adminapp.spk.*', 10, $admin),
            $blade('produksi', 'Produksi Harian', 'productionapp.dashboard', 'productionapp.*', 20, ['production', 'owner', 'superadmin']),
            $filament('produksi', 'SPK Produksi', 'filament.admin.resources.production-orders.index', 'inventory/production-orders*', 30, 'production.view'),
            $filament('produksi', 'Form Kebutuhan', 'filament.admin.resources.requisitions.index', 'inventory/requisitions*', 40, 'production.view'),
            $filament('produksi', 'Ledger Stok', 'filament.admin.resources.inventory-movements.index', 'inventory/inventory-movements*', 50, 'ledger.view'),
            $filament('produksi', 'Pelaksana', 'filament.admin.resources.production-workers.index', 'inventory/production-workers*', 60, 'production.view'),
            $blade('produksi', 'Laporan Produksi', 'adminapp.reports.production', 'adminapp.reports.production*', 70, $admin),

            // Pengiriman
            $blade('pengiriman', 'Delivery', 'adminapp.delivery.index', 'adminapp.delivery.*', 10, $admin),
            $blade('pengiriman', 'Pengiriman Harian', 'deliveryapp.dashboard', 'deliveryapp.*', 20, ['delivery', 'owner', 'superadmin']),
            $blade('pengiriman', 'Laporan Delivery', 'adminapp.reports.delivery', 'adminapp.reports.delivery*', 30, $admin),

            // Sales
            $blade('sales', 'Sales Harian', 'salesapp.dashboard', 'salesapp.dashboard', 10, ['sales', 'owner', 'superadmin']),
            $blade('sales', 'Laporan Sales Final & Retur', 'salesapp.reports.final-retur', ['salesapp.reports.final-retur', 'salesapp.reports'], 20, ['sales', 'owner', 'superadmin']),
            $blade('sales', 'Laporan Waste', 'salesapp.reports.waste', 'salesapp.reports.waste*', 30, ['sales', 'owner', 'superadmin']),
            $blade('sales', 'Laporan Penjualan (Sales)', 'salesapp.reports.sales', 'salesapp.reports.sales*', 40, ['sales', 'owner', 'superadmin']),

            // Inventory
            $filament('inventory', 'Item Inventaris', 'filament.admin.resources.inventory-items.index', 'inventory/inventory-items*', 10, 'inventory.view'),
            $filament('inventory', 'Pembelian Bahan Baku', 'filament.admin.resources.inventory-purchases.index', 'inventory/inventory-purchases*', 20, 'inventory.view'),
            $filament('inventory', 'Stock Opname', 'filament.admin.resources.stock-opnames.index', 'inventory/stock-opnames*', 30, 'inventory.view'),
            $filament('inventory', 'Saldo Awal Stok', 'filament.admin.resources.inventory-openings.index', 'inventory/inventory-openings*', 40, 'inventory.view'),
            $filament('inventory', 'Stok Minimum', 'filament.admin.pages.dashboard', 'inventory/stok-minimum', 50, 'inventory.view'),
            $blade('inventory', 'Laporan Pemakaian Bahan', 'accountingapp.reports.inventory-usage', 'accountingapp.reports.inventory-usage*', 60, $akunting),

            // Resep & HPP
            $filament('resep', 'Resep & Menu', 'filament.admin.resources.recipes.index', 'inventory/recipes*', 10, 'recipe.view'),
            $filament('resep', 'Konversi Satuan', 'filament.admin.resources.inventory-unit-conversions.index', 'inventory/inventory-unit-conversions*', 20, 'inventory.view'),
            $filament('resep', 'Bahan Belum Cocok', 'filament.admin.resources.recipe-mismatches.index', 'inventory/recipe-mismatches*', 30, 'recipe.view'),
            $filament('resep', 'Perbandingan HPP', 'filament.admin.pages.hpp-comparison-report', 'inventory/hpp-comparison-report*', 40, 'ledger.view'),
            $blade('resep', 'Analisa HPP Menu', 'ownerapp.hpp-analysis', 'ownerapp.hpp-analysis', 50, $admin),
            $blade('resep', 'Menu Tanpa HPP/OHC', 'adminapp.reports.missing-costs', 'adminapp.reports.missing-costs*', 60, $admin),

            // Akunting
            $blade('akunting', 'Akun Kas', 'accountingapp.cash-accounts.index', 'accountingapp.cash-accounts.*', 10, $akunting),
            $blade('akunting', 'Kategori Pemasukan', 'accountingapp.income-categories.index', 'accountingapp.income-categories.*', 20, $akunting),
            $blade('akunting', 'Kategori Pengeluaran', 'accountingapp.categories.index', 'accountingapp.categories.*', 30, $akunting),
            $blade('akunting', 'Saldo Awal Kas', 'accountingapp.opening-balances.index', 'accountingapp.opening-balances.*', 40, $akunting),
            $blade('akunting', 'Pemasukan Lain', 'accountingapp.other-incomes.index', 'accountingapp.other-incomes.*', 50, $akunting),
            $blade('akunting', 'Pengeluaran', 'accountingapp.expenses.index', 'accountingapp.expenses.*', 60, $akunting),
            $blade('akunting', 'Transfer Antar Akun', 'accountingapp.cash-account-transfers.index', 'accountingapp.cash-account-transfers.*', 70, $akunting),
            $blade('akunting', 'Closing Penjualan', 'accountingapp.sales-closings.index', 'accountingapp.sales-closings.*', 80, $akunting),
            $blade('akunting', 'Adjustment Neraca', 'accountingapp.balance-sheet-adjustments.index', 'accountingapp.balance-sheet-adjustments.*', 90, ['owner', 'superadmin']),
            $blade('akunting', 'Adjustment Laba Rugi', 'accountingapp.profit-loss-adjustments.index', 'accountingapp.profit-loss-adjustments.*', 100, ['owner', 'superadmin']),

            // Monitoring
            $blade('monitoring', 'Monitoring Hutang', 'accountingapp.payables.index', 'accountingapp.payables.*', 10, $akunting),
            $blade('monitoring', 'Monitoring Piutang', 'accountingapp.periods.index', 'accountingapp.periods.*', 20, $akunting),
            $blade('monitoring', 'Monitoring Pembelian Stok', 'accountingapp.inventory-purchases.index', 'accountingapp.inventory-purchases.*', 30, $akunting),
            $blade('monitoring', 'Status Periode', 'accountingapp.period-closings.index', 'accountingapp.period-closings.*', 40, $akunting),

            // Laporan Keuangan
            $blade('laporan', 'Laporan Cashflow', 'accountingapp.reports.cashflow', 'accountingapp.reports.cashflow', 10, $akunting),
            $blade('laporan', 'Laporan Penjualan', 'accountingapp.reports.sales', 'accountingapp.reports.sales*', 20, $akunting),
            $blade('laporan', 'Laporan Laba Rugi', 'accountingapp.reports.profit-loss', 'accountingapp.reports.profit-loss*', 30, $akunting),
            $blade('laporan', 'Laporan Neraca', 'accountingapp.reports.balance-sheet', 'accountingapp.reports.balance-sheet*', 40, $akunting),
            $blade('laporan', 'Laporan Final', 'accountingapp.reports.final', 'accountingapp.reports.final*', 50, $akunting),

            // Master Data
            $blade('master', 'Master Menu', 'adminapp.products.index', 'adminapp.products.*', 10, $admin),
            $blade('master', 'Master Customer', 'adminapp.customers.index', 'adminapp.customers.*', 20, $admin),

            // Sistem
            $blade('sistem', 'Pengguna', 'ownerapp.users.index', 'ownerapp.users.*', 10, ['owner', 'superadmin']),
            $filament('sistem', 'Pengaturan', 'filament.admin.pages.pengaturan', 'inventory/pengaturan*', 20, 'settings.manage'),
            $blade('sistem', 'Audit Logs', 'ownerapp.audit.index', 'ownerapp.audit.*', 30, $admin),
            $blade('sistem', 'Backup Database', 'superadmin.backup.index', 'superadmin.backup.*', 40, ['superadmin']),
        ];
    }

    /** Tautan "Dashboard" di atas semua grup: dashboard peran utama pengguna. */
    public static function dashboardUrl(User $user): string
    {
        $route = $user->preferredDashboardRouteName();

        return $route && Route::has($route) ? route($route) : url('/dashboard');
    }

    /** Aktif hanya di dashboard peran pengguna itu sendiri, bukan dashboard app lain. */
    public static function isDashboardActive(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && rtrim(request()->url(), '/') === rtrim(self::dashboardUrl($user), '/');
    }

    /**
     * Menu yang boleh dilihat pengguna, per grup, dengan url & status aktif
     * sudah dihitung -- siap dirender.
     *
     * @return list<array{key: string, label: string, icon: string, active: bool, items: list<array{label: string, url: string, active: bool, source: string}>}>
     */
    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $byGroup = [];

        foreach (self::items() as $item) {
            if (! self::allowed($user, $item) || ! Route::has($item['route'])) {
                continue;
            }

            $byGroup[$item['group']][] = [
                'label' => $item['label'],
                'url' => route($item['route']),
                'active' => self::isActive($item),
                'sort' => $item['sort'],
                'source' => $item['source'],
            ];
        }

        $groups = [];

        foreach (self::GROUPS as $group) {
            $items = $byGroup[$group['key']] ?? [];

            if ($items === []) {
                continue;
            }

            usort($items, fn ($a, $b) => $a['sort'] <=> $b['sort']);

            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'icon' => $group['icon'],
                'active' => collect($items)->contains('active', true),
                'items' => $items,
            ];
        }

        return $groups;
    }

    /**
     * Item Blade sebagai NavigationItem Filament, supaya sidebar panel memuat
     * menu yang sama. Item Filament sendiri didaftarkan oleh resource/page
     * masing-masing dengan nama grup & sort yang sama (lihat groupLabel()).
     *
     * @return list<NavigationItem>
     */
    public static function filamentItems(): array
    {
        $labels = self::groupLabels();

        // "Dashboard" di atas semua grup, sama seperti di layout Blade.
        $items = [
            NavigationItem::make('Dashboard')
                ->icon('heroicon-o-home')
                ->sort(-1)
                ->url(fn () => auth()->user() ? self::dashboardUrl(auth()->user()) : url('/'))
                ->isActiveWhen(fn () => self::isDashboardActive()),
        ];

        // Dipanggil saat panel dibangun (sebelum route terdaftar), jadi url
        // dan kelayakan dibungkus closure yang baru dievaluasi saat dirender.
        foreach (self::items() as $item) {
            if ($item['source'] !== 'blade') {
                continue;
            }

            $items[] = NavigationItem::make($item['label'])
                ->group($labels[$item['group']])
                ->sort($item['sort'])
                ->url(fn () => route($item['route']))
                ->isActiveWhen(fn () => self::isActive($item))
                ->visible(fn () => Route::has($item['route']) && self::allowed(auth()->user(), $item));
        }

        return $items;
    }

    /** @return array<string, string> key grup => label */
    public static function groupLabels(): array
    {
        return array_column(self::GROUPS, 'label', 'key');
    }

    public static function groupLabel(string $key): string
    {
        return self::groupLabels()[$key];
    }

    /** @param  array{roles?: list<string>, can?: string}  $item */
    protected static function allowed(?User $user, array $item): bool
    {
        if (! $user) {
            return false;
        }

        if (isset($item['roles'])) {
            return $user->hasAnyRole($item['roles']);
        }

        if (isset($item['can'])) {
            return $user->can($item['can']);
        }

        return false;
    }

    /** @param  array{match: string|list<string>, source: string}  $item */
    protected static function isActive(array $item): bool
    {
        if ($item['source'] === 'filament') {
            return request()->is($item['match']) || request()->is($item['match'].'/*');
        }

        return request()->routeIs(...(array) $item['match']);
    }
}
