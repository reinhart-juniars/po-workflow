<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Accounting App - {{ $title ?? '' }}</title>
    @vite('resources/css/app.css')
    @stack('styles')
</head>

<body class="app-shell">
    @include('partials.app-header', ['currentApp' => 'accounting'])

    @php
        $canManageBalanceSheetAdjustments = auth()
            ->user()
            ?->hasAnyRole(['owner', 'superadmin']);
        $canManageProfitLossAdjustments = auth()
            ->user()
            ?->hasAnyRole(['owner', 'superadmin']);
        $accountingNav = [
            [
                'section' => 'Ringkasan',
                'items' => [
                    [
                        'route' => 'accountingapp.dashboard',
                        'label' => 'Dashboard',
                        'match' => 'accountingapp.dashboard',
                    ],
                ],
            ],
            [
                'section' => 'Master Data',
                'items' => [
                    [
                        'route' => 'accountingapp.cash-accounts.index',
                        'label' => 'Akun Kas',
                        'match' => 'accountingapp.cash-accounts.*',
                    ],
                    [
                        'route' => 'accountingapp.inventory-items.index',
                        'label' => 'Master Item',
                        'match' => 'accountingapp.inventory-items.*',
                    ],
                    [
                        'route' => 'accountingapp.income-categories.index',
                        'label' => 'Kategori Pemasukan',
                        'match' => 'accountingapp.income-categories.*',
                    ],
                    [
                        'route' => 'accountingapp.categories.index',
                        'label' => 'Kategori Pengeluaran',
                        'match' => 'accountingapp.categories.*',
                    ],
                ],
            ],
            [
                'section' => 'Setup Awal',
                'items' => array_values(
                    array_filter([
                        [
                            'route' => 'accountingapp.opening-balances.index',
                            'label' => 'Saldo Awal',
                            'match' => 'accountingapp.opening-balances.*',
                        ],
                        [
                            'route' => 'accountingapp.inventory-openings.index',
                            'label' => 'Opening Inventory',
                            'match' => 'accountingapp.inventory-openings.*',
                        ],
                        $canManageBalanceSheetAdjustments
                            ? [
                                'route' => 'accountingapp.balance-sheet-adjustments.index',
                                'label' => 'Adjustment Neraca',
                                'match' => 'accountingapp.balance-sheet-adjustments.*',
                            ]
                            : null,
                        $canManageProfitLossAdjustments
                            ? [
                                'route' => 'accountingapp.profit-loss-adjustments.index',
                                'label' => 'Adjustment Laba Rugi',
                                'match' => 'accountingapp.profit-loss-adjustments.*',
                            ]
                            : null,
                    ]),
                ),
            ],
            [
                'section' => 'Transaksi',
                'items' => [
                    [
                        'route' => 'accountingapp.other-incomes.index',
                        'label' => 'Pemasukan Lain',
                        'match' => 'accountingapp.other-incomes.*',
                    ],
                    [
                        'route' => 'accountingapp.sales-closings.index',
                        'label' => 'Closing Penjualan',
                        'match' => 'accountingapp.sales-closings.*',
                    ],
                    [
                        'route' => 'accountingapp.expenses.index',
                        'label' => 'Pengeluaran',
                        'match' => 'accountingapp.expenses.*',
                    ],
                    [
                        'route' => 'accountingapp.cash-account-transfers.index',
                        'label' => 'Transfer Antar Akun',
                        'match' => 'accountingapp.cash-account-transfers.*',
                    ],
                    [
                        'route' => 'accountingapp.stock-opnames.index',
                        'label' => 'Stock Opname',
                        'match' => 'accountingapp.stock-opnames.*',
                    ],
                ],
            ],
            [
                'section' => 'Monitoring',
                'items' => [
                    [
                        'route' => 'accountingapp.payables.index',
                        'label' => 'Monitoring Hutang',
                        'match' => 'accountingapp.payables.*',
                    ],
                    [
                        'route' => 'accountingapp.periods.index',
                        'label' => 'Monitoring Piutang',
                        'match' => 'accountingapp.periods.*',
                    ],
                    [
                        'route' => 'accountingapp.inventory-purchases.index',
                        'label' => 'Monitoring Pembelian Stok',
                        'match' => 'accountingapp.inventory-purchases.*',
                    ],
                ],
            ],
            [
                'section' => 'Kontrol Periode',
                'items' => [
                    [
                        'route' => 'accountingapp.period-closings.index',
                        'label' => 'Status Periode',
                        'match' => 'accountingapp.period-closings.*',
                    ],
                ],
            ],
            [
                'section' => 'Laporan',
                'items' => [
                    [
                        'route' => 'accountingapp.reports.cashflow',
                        'label' => 'Laporan Cashflow',
                        'match' => 'accountingapp.reports.cashflow',
                    ],
                    [
                        'route' => 'accountingapp.reports.sales',
                        'label' => 'Laporan Penjualan',
                        'match' => 'accountingapp.reports.sales*',
                    ],
                    [
                        'route' => 'accountingapp.reports.profit-loss',
                        'label' => 'Laporan Laba Rugi',
                        'match' => 'accountingapp.reports.profit-loss*',
                    ],
                    [
                        'route' => 'accountingapp.reports.balance-sheet',
                        'label' => 'Laporan Neraca',
                        'match' => 'accountingapp.reports.balance-sheet*',
                    ],
                    [
                        'route' => 'accountingapp.reports.inventory-usage',
                        'label' => 'Laporan Pemakaian Bahan',
                        'match' => 'accountingapp.reports.inventory-usage',
                    ],
                    [
                        'route' => 'accountingapp.reports.final',
                        'label' => 'Laporan Final',
                        'match' => 'accountingapp.reports.final*',
                    ],
                ],
            ],
        ];
    @endphp

    <div class="page-wrap mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
        <aside class="lg:self-start">
            <div class="app-sidebar sidebar-scroll-panel sidebar-natural-panel">
                <nav class="space-y-2">
                    @foreach ($accountingNav as $group)
                        <div>
                            <div class="nav-section-title">{{ $group['section'] }}</div>
                            <div class="space-y-1">
                                @foreach ($group['items'] as $item)
                                    <a href="{{ route($item['route']) }}"
                                        class="nav-link {{ request()->routeIs($item['match']) ? 'nav-link-active' : '' }}">
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>
            </div>
        </aside>

        <main class="app-main-panel space-y-4">
            @if (session('success'))
                <div class="flash-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="flash-error">{{ session('error') }}</div>
            @endif
            @if (session('warning'))
                <div class="flash-error">{{ session('warning') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4">
                    <p class="font-semibold mb-1">Gagal menyimpan data:</p>
                    <ul class="list-disc pl-5 text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</body>

</html>
