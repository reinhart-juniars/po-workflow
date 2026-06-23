@extends('layouts.ownerapp', ['title' => 'Dashboard'])

@section('content')
    <section class="dashboard-hero">
        <div>
            <div>
                <h1 class="dashboard-hero-title">Owner Dashboard</h1>
                <p class="dashboard-hero-subtitle xl:whitespace-nowrap">
                    Rangkuman penjualan, arus kas, piutang, dan sinyal profit untuk periode
                    <strong>{{ $periodLabel }}</strong>.
                </p>
            </div>

            <form method="GET" action="{{ route('ownerapp.dashboard') }}"
                class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3 xl:max-w-3xl">
                <div>
                    <label class="mb-1.5 block">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}">
                </div>
                <div>
                    <label class="mb-1.5 block">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}">
                </div>
                <div class="flex items-end gap-2 sm:col-span-3 xl:col-span-1">
                    <button class="btn-primary w-full" type="submit">Terapkan</button>
                    <a href="{{ route('ownerapp.dashboard') }}" class="btn-ghost w-full">Reset</a>
                </div>
            </form>
        </div>
    </section>

    @if ($hppCategoryCount === 0)
        <div class="notice-soft notice-soft-amber mt-4">
            Estimasi margin saat ini belum akurat karena belum ada kategori pengeluaran yang ditandai <strong>Masuk
                HPP</strong>.
            Atur dulu di menu Accounting App ke Kategori Pengeluaran.
        </div>
    @endif

    <section class="dashboard-kpi-grid mt-4">
        <article class="metric-panel metric-panel-accent">
            <p class="metric-label text-white/80">Total Penjualan</p>
            <p class="metric-value-compact text-white">
                <span class="whitespace-nowrap">Rp {{ number_format($totalSales, 0, ',', '.') }}</span>
            </p>
            <p class="stat-meta text-white/80">PO selesai pada periode ini</p>
        </article>

        <article class="metric-panel">
            <p class="metric-label">Sales Delivery vs Actual</p>
            <div class="mt-3 grid gap-2 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-slate-500">Delivery</span>
                    <strong class="whitespace-nowrap text-slate-900">Rp
                        {{ number_format($salesDeliveryValue, 0, ',', '.') }}</strong>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-slate-500">Actual</span>
                    <strong class="whitespace-nowrap text-brand-600">Rp
                        {{ number_format($salesActualValue, 0, ',', '.') }}</strong>
                </div>
            </div>
            <p class="stat-meta">
                @if ($salesActualRate !== null)
                    Actual {{ number_format($salesActualRate, 2, ',', '.') }}% dari delivery.
                    Selisih Rp {{ number_format($salesActualGap, 0, ',', '.') }}
                @else
                    Belum ada sales actual submitted pada periode ini
                @endif
            </p>
        </article>

        <article class="metric-panel">
            <p class="metric-label">Total Pengeluaran</p>
            <p class="metric-value-compact text-slate-900">
                <span class="whitespace-nowrap">Rp {{ number_format($totalExpense, 0, ',', '.') }}</span>
            </p>
            <p class="stat-meta">Semua cash out pada periode ini</p>
        </article>

        <article class="metric-panel">
            <p class="metric-label">Net Cashflow</p>
            <p class="metric-value-compact {{ $netCashflow >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                <span class="whitespace-nowrap">Rp {{ number_format($netCashflow, 0, ',', '.') }}</span>
            </p>
            <p class="stat-meta">Cash in dikurangi cash out</p>
        </article>

        <article class="metric-panel">
            <p class="metric-label">Piutang Outstanding</p>
            <p class="metric-value-compact text-slate-900">
                <span class="whitespace-nowrap">Rp {{ number_format($outstandingReceivable, 0, ',', '.') }}</span>
            </p>
            <p class="stat-meta">{{ number_format($receivableCount) }} transaksi piutang masih terbuka</p>
        </article>

        <article class="metric-panel">
            <p class="metric-label">Estimasi Margin</p>
            <p class="metric-value-compact {{ $estimatedMargin >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                <span class="whitespace-nowrap">Rp {{ number_format($estimatedMargin, 0, ',', '.') }}</span>
            </p>
            <p class="stat-meta">Penjualan dikurangi total HPP Rp {{ number_format($totalHpp, 0, ',', '.') }}</p>
        </article>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-[1.4fr_1fr]">
        <div class="grid gap-4 md:grid-cols-3">
            <article class="section-card">
                <p class="metric-label">Avg Penjualan Harian</p>
                <p class="metric-value-compact">
                    <span class="whitespace-nowrap">Rp {{ number_format($avgDailySales, 0, ',', '.') }}</span>
                </p>
                <p class="stat-meta">Rata-rata {{ (int) $periodDays }} hari terakhir pada filter aktif</p>
            </article>

            <article class="section-card">
                <p class="metric-label">Jumlah Transaksi</p>
                <p class="metric-value">{{ number_format($transactionCount) }}</p>
                <p class="stat-meta">Jumlah PO completed pada periode ini</p>
            </article>

            <article class="section-card">
                <p class="metric-label">Growth vs Bulan Lalu</p>
                <p class="metric-value {{ ($growthVsPreviousMonth ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    {{ $growthVsPreviousMonth !== null ? number_format($growthVsPreviousMonth, 2, ',', '.') . '%' : '-' }}
                </p>
                <p class="stat-meta">Dibanding rentang {{ $comparisonLabel }}</p>
            </article>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <article class="signal-card signal-card-{{ $cashHealth['tone'] }}">
                <div>
                    <p class="metric-label">{{ $cashHealth['title'] }}</p>
                    <p class="text-xl font-display text-slate-900">{{ $cashHealth['status'] }}</p>
                    <span
                        class="mt-3 inline-flex badge-soft {{ $cashHealth['tone'] === 'emerald' ? 'badge-soft-emerald' : ($cashHealth['tone'] === 'amber' ? 'badge-soft-amber' : ($cashHealth['tone'] === 'rose' ? 'badge-soft-rose' : 'badge-soft-slate')) }}">
                        {{ $cashHealth['meta'] }}
                    </span>
                </div>
                <p class="mt-3 text-sm text-slate-600">{{ $cashHealth['description'] }}</p>
                <div class="signal-track mt-4">
                    <div class="signal-bar signal-bar-{{ $cashHealth['tone'] }}"
                        style="width: {{ $cashHealth['progress'] }}%"></div>
                </div>
                <div class="mt-3 flex items-center justify-between text-sm">
                    <span class="text-slate-500">Selisih</span>
                    <strong class="{{ $cashHealth['difference'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        Rp {{ number_format($cashHealth['difference'], 0, ',', '.') }}
                    </strong>
                </div>
            </article>

            <article class="signal-card signal-card-{{ $profitSignal['tone'] }}">
                <div>
                    <p class="metric-label">{{ $profitSignal['title'] }}</p>
                    <p class="text-xl font-display text-slate-900">{{ $profitSignal['status'] }}</p>
                    <span
                        class="mt-3 inline-flex badge-soft {{ $profitSignal['tone'] === 'emerald' ? 'badge-soft-emerald' : ($profitSignal['tone'] === 'amber' ? 'badge-soft-amber' : ($profitSignal['tone'] === 'rose' ? 'badge-soft-rose' : 'badge-soft-slate')) }}">
                        {{ $profitSignal['meta'] }}
                    </span>
                </div>
                <p class="mt-3 text-sm text-slate-600">{{ $profitSignal['description'] }}</p>
                <div class="signal-track mt-4">
                    <div class="signal-bar signal-bar-{{ $profitSignal['tone'] }}"
                        style="width: {{ $profitSignal['progress'] }}%"></div>
                </div>
                <div class="mt-3 flex items-center justify-between text-sm">
                    <span class="text-slate-500">Margin kotor</span>
                    <strong class="{{ $profitSignal['difference'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        {{ $profitSignal['percent'] !== null ? number_format($profitSignal['percent'], 2, ',', '.') . '%' : '-' }}
                    </strong>
                </div>
            </article>
        </div>
    </section>

    <section class="mt-4">
        @include('ownerapp.partials.donut-chart', [
            'title' => 'Pie Chart Kategori Pengeluaran',
            'subtitle' => 'Komposisi pengeluaran berdasarkan seluruh kategori pada periode aktif.',
            'chart' => $expenseCategoryChart,
        ])
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-2">
        @include('ownerapp.partials.line-chart', [
            'title' => 'Penjualan vs Pengeluaran',
            'subtitle' => 'Tren pendapatan dan biaya operasional pada periode aktif.',
            'chart' => $salesVsExpenseChart,
        ])

        @include('ownerapp.partials.line-chart', [
            'title' => 'Sales Delivery vs Sales Actual',
            'subtitle' => 'Perbandingan nilai delivery dan nilai final yang disubmit.',
            'chart' => $salesDeliveryActualChart,
        ])
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-[1.1fr_1fr_1fr]">
        <article class="section-card">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="panel-title">Piutang Outstanding</h2>
                    <p class="section-subtitle mt-1">Aging dari jatuh tempo piutang yang masih terbuka.</p>
                </div>
                <div class="text-right">
                    <p class="metric-label">Total</p>
                    <p class="text-lg font-display text-slate-900">Rp
                        {{ number_format($outstandingReceivable, 0, ',', '.') }}</p>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($receivableAging as $bucket)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-4 text-sm">
                            <span class="font-medium text-slate-700">{{ $bucket['label'] }}</span>
                            <span class="text-slate-500">{{ $bucket['count'] }} trx</span>
                        </div>
                        <div class="mini-track">
                            <div class="mini-track-fill mini-track-fill-{{ $bucket['tone'] }}"
                                style="width: {{ $bucket['bar_width'] }}%"></div>
                        </div>
                        <div class="mt-1.5 text-sm font-semibold text-slate-900">
                            Rp {{ number_format($bucket['amount'], 0, ',', '.') }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Belum ada piutang terbuka pada periode ini.</p>
                @endforelse
            </div>
        </article>

        <article class="section-card">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="panel-title">Top 5 Produk Terlaris</h2>
                    <p class="section-subtitle mt-1">Berdasarkan total qty terjual.</p>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($topProducts as $index => $item)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $index + 1 }}.
                                    {{ $item->item_name }}</p>
                                <p class="text-xs text-slate-500">Omzet Rp
                                    {{ number_format($item->total_sales, 0, ',', '.') }}</p>
                            </div>
                            <div class="text-right text-sm font-semibold text-slate-700">
                                {{ number_format($item->total_qty) }} pcs</div>
                        </div>
                        <div class="mini-track">
                            <div class="mini-track-fill mini-track-fill-brand" style="width: {{ $item->bar_width }}%">
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Belum ada data produk pada periode ini.</p>
                @endforelse
            </div>
        </article>

        <article class="section-card">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="panel-title">Top 5 Customer Terbesar</h2>
                    <p class="section-subtitle mt-1">Berdasarkan total nilai penjualan.</p>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($topCustomers as $index => $customer)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $index + 1 }}.
                                    {{ $customer->customer_name }}</p>
                                <p class="text-xs text-slate-500">{{ number_format($customer->total_orders) }} order</p>
                            </div>
                            <div class="text-right text-sm font-semibold text-slate-700">
                                Rp {{ number_format($customer->total_sales, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="mini-track">
                            <div class="mini-track-fill mini-track-fill-emerald"
                                style="width: {{ $customer->bar_width }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Belum ada data customer pada periode ini.</p>
                @endforelse
            </div>
        </article>
    </section>
@endsection
