@extends('layouts.accountingapp', ['title' => 'Dashboard'])

@section('content')
    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Accounting Dashboard</h1>
                <p class="dashboard-hero-subtitle xl:whitespace-nowrap">
                    Ringkasan posisi kas, monitoring piutang, dan aktivitas pengeluaran untuk periode
                    <strong>{{ $dateFrom->format('d M Y') }} sampai {{ $dateTo->format('d M Y') }}</strong>.
                </p>
            </div>
            <div class="{{ $reportPeriodClosing ? 'badge-soft-amber' : 'badge-soft-emerald' }}">
                {{ $reportPeriodClosing ? 'Periode tertutup' : 'Periode aktif' }}
            </div>
        </div>

        <div class="mt-4 {{ $rangePeriodStatus['has_closed_periods'] ? 'notice-soft-amber' : 'notice-soft-emerald' }}">
            <strong>Status rentang laporan:</strong> {{ $rangePeriodStatus['message'] }}
        </div>

        <form method="GET" action="{{ route('accountingapp.dashboard') }}" class="form-grid mt-6">
            <div class="md:col-span-3 flex flex-wrap gap-2">
                <button type="button" class="chip-filter js-date-preset" data-form-scope="dashboard"
                    data-preset="this_month">Bulan Ini</button>
                <button type="button" class="chip-filter js-date-preset" data-form-scope="dashboard"
                    data-preset="last_month">Bulan Lalu</button>
                <button type="button" class="chip-filter js-date-preset" data-form-scope="dashboard"
                    data-preset="this_year">Tahun Berjalan</button>
            </div>

            <div>
                <label class="form-label">Dari Tanggal</label>
                <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" data-form-scope="dashboard"
                    data-role="date-from">
            </div>

            <div>
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" data-form-scope="dashboard"
                    data-role="date-to">
            </div>

            <div class="flex items-end">
                <button class="btn-primary w-full">Terapkan Filter</button>
            </div>
        </form>
    </section>

    <section class="space-y-2">
        <div>
            <div>
                <h2 class="section-title">Status Periode</h2>
                <p class="section-subtitle">Pantau apakah periode laporan masih aktif atau sudah dikunci.</p>
                @if ($previousOpenPeriodWarning)
                    <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <strong>Warning:</strong> {{ $previousOpenPeriodWarning['message'] }}
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    {{-- <a href="{{ route('accountingapp.periods.index') }}" class="chip-link">
          Monitoring Piutang
        </a>
        <a href="{{ route('accountingapp.payables.index') }}" class="chip-link">
          Monitoring Hutang
        </a> --}}
                    <a href="{{ route('accountingapp.period-closings.index', ['year' => $reportPeriodYear]) }}"
                        class="chip-link">
                        Buka Status Periode
                    </a>
                </div>
            </div>
        </div>

        <div class="stats-grid xl:grid-cols-3">
            <div class="stat-card">
                <div class="stat-label">Periode Laporan</div>
                <div class="stat-value-compact">{{ $reportPeriodLabel }}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Status Periode</div>
                <div class="stat-value-compact {{ $reportPeriodClosing ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $reportPeriodClosing ? 'Tertutup' : 'Aktif' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    @if ($reportPeriodClosing)
                        Ditutup {{ optional($reportPeriodClosing->closed_at)->format('d-m-Y H:i') }}
                    @else
                        Belum ada penutupan untuk periode ini
                    @endif
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Penutupan Terakhir</div>
                <div class="stat-value-compact text-slate-900">{{ $latestClosedPeriodLabel ?? '-' }}</div>
            </div>
        </div>
    </section>

    <section class="space-y-2">
        <div>
            <h2 class="section-title">Posisi Kas</h2>
            <p class="section-subtitle">Kas dihitung dari saldo awal, penerimaan PO, pemasukan lain, dan pengeluaran.</p>
        </div>

        <div class="stats-grid xl:grid-cols-5">
            <div class="stat-card">
                <div class="stat-label">Saldo Awal Kas</div>
                <div class="stat-value-compact">
                    <span class="whitespace-nowrap">Rp {{ number_format($openingCash, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Penerimaan PO</div>
                <div class="stat-value-compact text-emerald-700">
                    <span class="whitespace-nowrap">Rp {{ number_format($totalCashIn, 0, ',', '.') }}</span>
                </div>
                <div class="mt-1 text-xs text-slate-500">Cash received dari PO pada periode</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Pemasukan Lain</div>
                <div class="stat-value-compact text-lime-700">
                    <span class="whitespace-nowrap">Rp {{ number_format($otherIncomeTotal, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Cash In</div>
                <div class="stat-value-compact text-brand-600">
                    <span class="whitespace-nowrap">Rp {{ number_format($totalCashInAll, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Cash Out</div>
                <div class="stat-value-compact text-rose-600">
                    <span class="whitespace-nowrap">Rp {{ number_format($totalExpense, 0, ',', '.') }}</span>
                </div>
            </div>
        </div>

        <div class="app-card p-4">
            <div class="page-toolbar">
                <div>
                    <div class="stat-label">Saldo Akhir Kas</div>
                    <div class="stat-value-compact {{ $endingCash >= 0 ? 'text-slate-900' : 'text-rose-600' }}">
                        <span class="whitespace-nowrap">Rp {{ number_format($endingCash, 0, ',', '.') }}</span>
                    </div>
                </div>
                {{-- <div class="chip {{ $endingCash >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
          {{ $endingCash >= 0 ? 'Positif' : 'Perlu perhatian' }}
        </div> --}}
            </div>
        </div>
    </section>

    <section class="space-y-2">
        <div>
            <h2 class="section-title">Ringkasan Neraca</h2>
            <p class="section-subtitle">
                Posisi aset, kewajiban, dan modal per <strong>{{ $balanceSheetSummary['reportDate']->format('d M Y') }}</strong>.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('accountingapp.reports.balance-sheet', ['report_date' => $balanceSheetSummary['reportDate']->toDateString()]) }}" class="chip-link">
                    Buka Laporan Neraca
                </a>
            </div>
        </div>

        <div class="stats-grid xl:grid-cols-3">
            <div class="stat-card">
                <div class="stat-label">Total Aset</div>
                <div class="stat-value-compact text-slate-900">
                    <span class="whitespace-nowrap">Rp {{ number_format((float) $balanceSheetSummary['totalAssets'], 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Kewajiban</div>
                <div class="stat-value-compact text-rose-600">
                    <span class="whitespace-nowrap">Rp {{ number_format((float) $balanceSheetSummary['totalLiabilities'], 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Modal</div>
                <div class="stat-value-compact {{ (float) $balanceSheetSummary['equityAmount'] >= 0 ? 'text-emerald-700' : 'text-rose-600' }}">
                    <span class="whitespace-nowrap">Rp {{ number_format(abs((float) $balanceSheetSummary['equityAmount']), 0, ',', '.') }}</span>
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    {{ (float) $balanceSheetSummary['equityAmount'] >= 0 ? 'Positif' : 'Negatif' }}
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="app-card p-4">
                <div class="stat-label">Komponen Aset Terbesar</div>
                <div class="mt-3 space-y-3 text-sm">
                    @foreach($balanceSheetSummary['assetGroups'] as $group)
                        <div class="flex items-start justify-between gap-3 border-t border-slate-200 pt-3 first:border-t-0 first:pt-0">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $group['title'] }}</div>
                                <div class="text-xs text-slate-500">{{ count($group['rows']) }} baris</div>
                            </div>
                            <div class="text-right font-semibold text-slate-800">
                                Rp {{ number_format((float) $group['total'], 0, ',', '.') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="app-card p-4">
                <div class="stat-label">Komponen Modal</div>
                <div class="mt-3 space-y-3 text-sm">
                    @foreach($balanceSheetSummary['equityRows'] as $row)
                        <div class="flex items-start justify-between gap-3 border-t border-slate-200 pt-3 first:border-t-0 first:pt-0">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $row['label'] }}</div>
                                <div class="text-xs text-slate-500">{{ $row['meta'] ?? '' }}</div>
                            </div>
                            <div class="text-right font-semibold {{ (float) $row['amount'] >= 0 ? 'text-slate-800' : 'text-rose-600' }}">
                                @if((float) $row['amount'] < 0)
                                    (Rp {{ number_format(abs((float) $row['amount']), 0, ',', '.') }})
                                @else
                                    Rp {{ number_format((float) $row['amount'], 0, ',', '.') }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if($balanceSheetSummary['warnings']->isNotEmpty())
            <div class="notice-soft-amber">
                Neraca punya <strong>{{ number_format($balanceSheetSummary['warnings']->count(), 0, ',', '.') }}</strong>
                catatan akurasi. Buka laporan neraca untuk melihat detail warning-nya.
            </div>
        @endif
    </section>

    <section class="space-y-2">
        <div>
            <h2 class="section-title">Monitoring Hutang</h2>
            <p class="section-subtitle">Pantau hutang outstanding dari pembelian kredit dan saldo awal supaya pembayaran
                tidak melewati jatuh tempo.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('accountingapp.payables.index') }}" class="chip-link">
                    Buka Monitoring Hutang
                </a>
            </div>
        </div>

        <div class="stats-grid xl:grid-cols-4">
            <div class="stat-card">
                <div class="stat-label">Outstanding Hutang</div>
                <div class="stat-value-compact text-rose-600">
                    <span class="whitespace-nowrap">Rp {{ number_format((float) $outstandingPayable, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Lewat Jatuh Tempo</div>
                <div class="stat-value text-red-600">{{ number_format($overduePayablesCount, 0, ',', '.') }}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Jatuh Tempo Hari Ini</div>
                <div class="stat-value text-amber-600">{{ number_format($dueTodayPayablesCount, 0, ',', '.') }}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">7 Hari Ke Depan</div>
                <div class="stat-value {{ $dueSoonPayablesCount > 0 ? 'text-amber-600' : 'text-slate-900' }}">
                    {{ number_format($dueSoonPayablesCount, 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-slate-500">
                    Tanpa jatuh tempo: {{ number_format($openPayablesWithoutDueDateCount, 0, ',', '.') }}
                </div>
            </div>
        </div>

        @if ($overduePayablesCount > 0 || $dueTodayPayablesCount > 0)
            <div class="notice-soft-amber">
                Ada <strong>{{ number_format($overduePayablesCount + $dueTodayPayablesCount, 0, ',', '.') }}</strong>
                hutang yang perlu segera dibayar.
            </div>
        @endif
    </section>

    <section class="space-y-2">
        <div>
            <h2 class="section-title">Monitoring Piutang</h2>
            <p class="section-subtitle">Pantau piutang outstanding dari saldo awal, piutang baru, dan pembayaran yang
                diterima.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('accountingapp.periods.index') }}" class="chip-link">
                    Buka Monitoring Piutang
                </a>
            </div>
        </div>

        <div class="stats-grid xl:grid-cols-4">
            <div class="stat-card">
                <div class="stat-label">Saldo Awal Piutang</div>
                <div class="stat-value-compact text-amber-700">
                    <span class="whitespace-nowrap">Rp {{ number_format($openingReceivable, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Piutang Baru</div>
                <div class="stat-value-compact text-orange-600">
                    <span class="whitespace-nowrap">Rp {{ number_format($newReceivables, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Pembayaran Piutang</div>
                <div class="stat-value-compact text-emerald-700">
                    <span class="whitespace-nowrap">Rp {{ number_format($receivableCollections, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Outstanding Piutang</div>
                <div class="stat-value-compact {{ $outstandingReceivable >= 0 ? 'text-slate-900' : 'text-rose-600' }}">
                    <span class="whitespace-nowrap">Rp {{ number_format($outstandingReceivable, 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Lewat Jatuh Tempo</div>
                <div class="stat-value text-red-600">{{ number_format($overdueReceivablesCount, 0, ',', '.') }}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Jatuh Tempo Hari Ini</div>
                <div class="stat-value text-amber-600">{{ number_format($dueTodayReceivablesCount, 0, ',', '.') }}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">7 Hari Ke Depan</div>
                <div class="stat-value {{ $dueSoonReceivablesCount > 0 ? 'text-amber-600' : 'text-slate-900' }}">
                    {{ number_format($dueSoonReceivablesCount, 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-slate-500">
                    Tanpa jatuh tempo: {{ number_format($openReceivablesWithoutDueDateCount, 0, ',', '.') }}
                </div>
            </div>
        </div>

        @if ($overdueReceivablesCount > 0 || $dueTodayReceivablesCount > 0)
            <div class="notice-soft-amber">
                Ada <strong>{{ number_format($overdueReceivablesCount + $dueTodayReceivablesCount, 0, ',', '.') }}</strong>
                piutang yang perlu ditagih atau dikonfirmasi pembayarannya.
            </div>
        @endif
    </section>

    <script>
        (() => {
            const applyPreset = (scope, preset) => {
                const fromInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-from"]`);
                const toInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-to"]`);

                if (!fromInput || !toInput) {
                    return;
                }

                const now = new Date();
                const pad = (value) => String(value).padStart(2, '0');
                const format = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

                let fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
                let toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

                if (preset === 'last_month') {
                    fromDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    toDate = new Date(now.getFullYear(), now.getMonth(), 0);
                } else if (preset === 'this_year') {
                    fromDate = new Date(now.getFullYear(), 0, 1);
                    toDate = new Date(now.getFullYear(), 11, 31);
                }

                fromInput.value = format(fromDate);
                toInput.value = format(toDate);
            };

            document.querySelectorAll('.js-date-preset').forEach((button) => {
                button.addEventListener('click', () => applyPreset(button.dataset.formScope, button.dataset
                    .preset));
            });
        })();
    </script>
@endsection
