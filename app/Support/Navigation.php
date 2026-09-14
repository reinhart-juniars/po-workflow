<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Satu-satunya definisi navigasi 3S Business Control System.
 *
 * Dua tingkat, seperti yang sudah dikenal pengguna: **aplikasi** di bilah
 * atas (Owner, Admin, Accounting, Inventory, Sales, Production, Delivery,
 * Superadmin) dan **menu aplikasi** di sidebar, dikelompokkan per seksi.
 *
 * Dibaca oleh dua penyaji: layout Blade (`layouts.shell`) dan panel
 * Filament (modul inventory). Bilah aplikasinya sama persis di keduanya;
 * sidebar Filament diisi resource/page-nya sendiri dengan nama grup yang
 * sama dengan seksi di sini (tes SystemShellTest menjaga keduanya sinkron).
 *
 * Setiap halaman punya satu pemilik: 'blade' (route bernama, dijaga peran
 * lewat ensure.role) atau 'filament' (dijaga izin modul lewat policy).
 */
class Navigation
{
    /**
     * Aplikasi, urut seperti bilah atas. `roles` = siapa yang melihat tabnya
     * (Inventory mengikuti User::PANEL_ROLES). `pattern` = path yang menandai
     * aplikasi ini sedang dibuka.
     *
     * @return array<string, array{label: string, icon: string, dashboard: string, pattern: string, roles: list<string>}>
     */
    public static function apps(): array
    {
        return [
            'superadmin' => ['label' => 'Superadmin', 'icon' => 'heroicon-o-shield-check', 'dashboard' => 'superadmin.dashboard', 'pattern' => 'superadmin*', 'roles' => ['superadmin']],
            'owner' => ['label' => 'Owner', 'icon' => 'heroicon-o-building-storefront', 'dashboard' => 'ownerapp.dashboard', 'pattern' => 'owner-app*', 'roles' => ['owner', 'superadmin']],
            'admin' => ['label' => 'Admin', 'icon' => 'heroicon-o-clipboard-document-list', 'dashboard' => 'adminapp.dashboard', 'pattern' => 'admin-app*', 'roles' => ['admin', 'owner', 'superadmin']],
            'accounting' => ['label' => 'Accounting', 'icon' => 'heroicon-o-banknotes', 'dashboard' => 'accountingapp.dashboard', 'pattern' => 'accounting-app*', 'roles' => ['accounting', 'owner', 'superadmin']],
            'inventory' => ['label' => 'Inventory', 'icon' => 'heroicon-o-cube', 'dashboard' => 'filament.admin.resources.inventory-items.index', 'pattern' => 'inventory*', 'roles' => User::PANEL_ROLES],
            'sales' => ['label' => 'Sales', 'icon' => 'heroicon-o-shopping-bag', 'dashboard' => 'salesapp.dashboard', 'pattern' => 'sales-app*', 'roles' => ['sales', 'owner', 'superadmin']],
            'production' => ['label' => 'Production', 'icon' => 'heroicon-o-fire', 'dashboard' => 'productionapp.dashboard', 'pattern' => 'production-app*', 'roles' => ['production', 'owner', 'superadmin']],
            'delivery' => ['label' => 'Delivery', 'icon' => 'heroicon-o-truck', 'dashboard' => 'deliveryapp.dashboard', 'pattern' => 'delivery-app*', 'roles' => ['delivery', 'owner', 'superadmin']],
        ];
    }

    /**
     * Menu tiap aplikasi: seksi => item. `roles` (Blade) atau `can` (Filament)
     * boleh menyempitkan lebih jauh dari peran aplikasinya; tanpa keduanya,
     * item mengikuti peran aplikasi.
     *
     * @return array<string, array<string, list<array{label: string, route: string, match?: string|list<string>, roles?: list<string>, can?: string}>>>
     */
    public static function menus(): array
    {
        $ownerOnly = ['owner', 'superadmin'];

        return [
            'superadmin' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard', 'route' => 'superadmin.dashboard'],
                ],
                'Sistem' => [
                    ['label' => 'Backup Database', 'route' => 'superadmin.backup.index', 'match' => 'superadmin.backup.*'],
                ],
            ],
            'owner' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard', 'route' => 'ownerapp.dashboard'],
                ],
                'Manajemen' => [
                    ['label' => 'Master User', 'route' => 'ownerapp.users.index', 'match' => 'ownerapp.users.*', 'roles' => $ownerOnly],
                    ['label' => 'Analisa HPP', 'route' => 'ownerapp.hpp-analysis'],
                    ['label' => 'Audit Logs', 'route' => 'ownerapp.audit.index', 'match' => 'ownerapp.audit.*'],
                ],
            ],
            'admin' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard', 'route' => 'adminapp.dashboard'],
                ],
                'Master Data' => [
                    ['label' => 'Master Menu', 'route' => 'adminapp.products.index', 'match' => 'adminapp.products.*'],
                    ['label' => 'Master Customer', 'route' => 'adminapp.customers.index', 'match' => 'adminapp.customers.*'],
                ],
                'Operasional' => [
                    ['label' => 'Purchase Orders', 'route' => 'adminapp.orders.index', 'match' => 'adminapp.orders.*'],
                    ['label' => 'SPK', 'route' => 'adminapp.spk.index', 'match' => 'adminapp.spk.*'],
                    ['label' => 'Delivery', 'route' => 'adminapp.delivery.index', 'match' => 'adminapp.delivery.*'],
                    ['label' => 'Menu Tanpa HPP/OHC', 'route' => 'adminapp.reports.missing-costs', 'match' => 'adminapp.reports.missing-costs*'],
                ],
                'Laporan' => [
                    ['label' => 'Laporan PO', 'route' => 'adminapp.reports.orders', 'match' => 'adminapp.reports.orders*'],
                    ['label' => 'Laporan Produksi', 'route' => 'adminapp.reports.production', 'match' => 'adminapp.reports.production*'],
                    ['label' => 'Laporan Delivery', 'route' => 'adminapp.reports.delivery', 'match' => 'adminapp.reports.delivery*'],
                    ['label' => 'Laporan Best Seller', 'route' => 'adminapp.reports.bestseller', 'match' => 'adminapp.reports.bestseller*'],
                ],
            ],
            'accounting' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard', 'route' => 'accountingapp.dashboard'],
                ],
                'Master Data' => [
                    ['label' => 'Akun Kas', 'route' => 'accountingapp.cash-accounts.index', 'match' => 'accountingapp.cash-accounts.*'],
                    ['label' => 'Kategori Pemasukan', 'route' => 'accountingapp.income-categories.index', 'match' => 'accountingapp.income-categories.*'],
                    ['label' => 'Kategori Pengeluaran', 'route' => 'accountingapp.categories.index', 'match' => 'accountingapp.categories.*'],
                ],
                'Setup Awal' => [
                    ['label' => 'Saldo Awal', 'route' => 'accountingapp.opening-balances.index', 'match' => 'accountingapp.opening-balances.*'],
                    ['label' => 'Adjustment Neraca', 'route' => 'accountingapp.balance-sheet-adjustments.index', 'match' => 'accountingapp.balance-sheet-adjustments.*', 'roles' => $ownerOnly],
                    ['label' => 'Adjustment Laba Rugi', 'route' => 'accountingapp.profit-loss-adjustments.index', 'match' => 'accountingapp.profit-loss-adjustments.*', 'roles' => $ownerOnly],
                ],
                'Transaksi' => [
                    ['label' => 'Pemasukan Lain', 'route' => 'accountingapp.other-incomes.index', 'match' => 'accountingapp.other-incomes.*'],
                    ['label' => 'Closing Penjualan', 'route' => 'accountingapp.sales-closings.index', 'match' => 'accountingapp.sales-closings.*'],
                    ['label' => 'Pengeluaran', 'route' => 'accountingapp.expenses.index', 'match' => 'accountingapp.expenses.*'],
                    ['label' => 'Transfer Antar Akun', 'route' => 'accountingapp.cash-account-transfers.index', 'match' => 'accountingapp.cash-account-transfers.*'],
                ],
                'Monitoring' => [
                    ['label' => 'Monitoring Hutang', 'route' => 'accountingapp.payables.index', 'match' => 'accountingapp.payables.*'],
                    ['label' => 'Monitoring Piutang', 'route' => 'accountingapp.periods.index', 'match' => 'accountingapp.periods.*'],
                    ['label' => 'Monitoring Pembelian Stok', 'route' => 'accountingapp.inventory-purchases.index', 'match' => 'accountingapp.inventory-purchases.*'],
                ],
                'Kontrol Periode' => [
                    ['label' => 'Status Periode', 'route' => 'accountingapp.period-closings.index', 'match' => 'accountingapp.period-closings.*'],
                ],
                'Laporan' => [
                    ['label' => 'Laporan Cashflow', 'route' => 'accountingapp.reports.cashflow'],
                    ['label' => 'Laporan Penjualan', 'route' => 'accountingapp.reports.sales', 'match' => 'accountingapp.reports.sales*'],
                    ['label' => 'Laporan Laba Rugi', 'route' => 'accountingapp.reports.profit-loss', 'match' => 'accountingapp.reports.profit-loss*'],
                    ['label' => 'Laporan Neraca', 'route' => 'accountingapp.reports.balance-sheet', 'match' => 'accountingapp.reports.balance-sheet*'],
                    ['label' => 'Laporan Pemakaian Bahan', 'route' => 'accountingapp.reports.inventory-usage', 'match' => 'accountingapp.reports.inventory-usage*'],
                    ['label' => 'Laporan Final', 'route' => 'accountingapp.reports.final', 'match' => 'accountingapp.reports.final*'],
                ],
            ],
            // Nama seksi = $navigationGroup resource/page Filament; urutan item
            // = $navigationSort. Sidebar panel dirender Filament sendiri.
            'inventory' => [
                'Inventory' => [
                    ['label' => 'Item Inventaris', 'route' => 'filament.admin.resources.inventory-items.index', 'can' => 'inventory.view'],
                    ['label' => 'Pembelian Bahan Baku', 'route' => 'filament.admin.resources.inventory-purchases.index', 'can' => 'inventory.view'],
                    ['label' => 'Stock Opname', 'route' => 'filament.admin.resources.stock-opnames.index', 'can' => 'inventory.view'],
                    ['label' => 'Saldo Awal Stok', 'route' => 'filament.admin.resources.inventory-openings.index', 'can' => 'inventory.view'],
                    ['label' => 'Stok Minimum', 'route' => 'filament.admin.pages.dashboard', 'can' => 'inventory.view'],
                ],
                'Resep & HPP' => [
                    ['label' => 'Resep & Menu', 'route' => 'filament.admin.resources.recipes.index', 'can' => 'recipe.view'],
                    ['label' => 'Konversi Satuan', 'route' => 'filament.admin.resources.inventory-unit-conversions.index', 'can' => 'inventory.view'],
                    ['label' => 'Bahan Belum Cocok', 'route' => 'filament.admin.resources.recipe-mismatches.index', 'can' => 'recipe.view'],
                    ['label' => 'Perbandingan HPP', 'route' => 'filament.admin.pages.hpp-comparison-report', 'can' => 'ledger.view'],
                ],
                'Produksi' => [
                    ['label' => 'SPK Produksi', 'route' => 'filament.admin.resources.production-orders.index', 'can' => 'production.view'],
                    ['label' => 'Form Kebutuhan', 'route' => 'filament.admin.resources.requisitions.index', 'can' => 'production.view'],
                    ['label' => 'Ledger Stok', 'route' => 'filament.admin.resources.inventory-movements.index', 'can' => 'ledger.view'],
                    ['label' => 'Pelaksana', 'route' => 'filament.admin.resources.production-workers.index', 'can' => 'production.view'],
                ],
                'Sistem' => [
                    ['label' => 'Pengaturan', 'route' => 'filament.admin.pages.pengaturan', 'can' => 'settings.manage'],
                ],
            ],
            'sales' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard', 'route' => 'salesapp.dashboard'],
                ],
                'Laporan' => [
                    ['label' => 'Laporan Sales Final & Retur', 'route' => 'salesapp.reports.final-retur', 'match' => ['salesapp.reports.final-retur', 'salesapp.reports']],
                    ['label' => 'Laporan Waste', 'route' => 'salesapp.reports.waste', 'match' => 'salesapp.reports.waste*'],
                    ['label' => 'Laporan Penjualan', 'route' => 'salesapp.reports.sales', 'match' => 'salesapp.reports.sales*'],
                ],
            ],
            'production' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard Produksi', 'route' => 'productionapp.dashboard', 'match' => 'productionapp.*'],
                ],
            ],
            'delivery' => [
                'Ringkasan' => [
                    ['label' => 'Dashboard Pengiriman', 'route' => 'deliveryapp.dashboard', 'match' => 'deliveryapp.*'],
                ],
            ],
        ];
    }

    /**
     * Tab aplikasi untuk pengguna ini, dengan url & status aktif.
     *
     * @return list<array{key: string, label: string, icon: string, url: string, active: bool}>
     */
    public static function tabs(?User $user, ?string $currentApp = null): array
    {
        if (! $user) {
            return [];
        }

        $current = $currentApp ?? self::currentApp();
        $tabs = [];

        foreach (self::apps() as $key => $app) {
            if (! in_array($key, $user->accessibleAppKeys(), true) || ! Route::has($app['dashboard'])) {
                continue;
            }

            $tabs[] = ['key' => $key, 'label' => $app['label'], 'icon' => $app['icon'], 'url' => route($app['dashboard']), 'active' => $key === $current];
        }

        return $tabs;
    }

    /**
     * Sidebar satu aplikasi untuk pengguna ini: seksi yang tidak kosong saja.
     *
     * @return list<array{label: string, items: list<array{label: string, url: string, active: bool}>}>
     */
    public static function sidebar(string $app, ?User $user): array
    {
        if (! $user || ! isset(self::apps()[$app]) || ! $user->hasAnyRole(self::apps()[$app]['roles'])) {
            return [];
        }

        $sections = [];

        foreach (self::menus()[$app] ?? [] as $label => $items) {
            $visible = [];

            foreach ($items as $item) {
                if (! Route::has($item['route']) || ! self::allowed($user, $item)) {
                    continue;
                }

                $visible[] = ['label' => $item['label'], 'url' => route($item['route']), 'active' => self::isActive($item)];
            }

            if ($visible !== []) {
                $sections[] = ['label' => $label, 'items' => $visible];
            }
        }

        return $sections;
    }

    /** Aplikasi yang sedang dibuka, ditebak dari path. */
    public static function currentApp(): ?string
    {
        foreach (self::apps() as $key => $app) {
            if (request()->is($app['pattern'])) {
                return $key;
            }
        }

        return null;
    }

    public static function appLabel(?string $app): string
    {
        return self::apps()[$app]['label'] ?? '3S BCS';
    }

    /** Beranda pengguna: dashboard peran utamanya. */
    public static function dashboardUrl(User $user): string
    {
        $route = $user->preferredDashboardRouteName();

        return $route && Route::has($route) ? route($route) : url('/dashboard');
    }

    /** @param  array{roles?: list<string>, can?: string}  $item */
    protected static function allowed(User $user, array $item): bool
    {
        if (isset($item['roles']) && ! $user->hasAnyRole($item['roles'])) {
            return false;
        }

        if (isset($item['can']) && ! $user->can($item['can'])) {
            return false;
        }

        return true;
    }

    /** @param  array{route: string, match?: string|list<string>}  $item */
    protected static function isActive(array $item): bool
    {
        return request()->routeIs(...(array) ($item['match'] ?? $item['route']));
    }
}
