@extends('layouts.accountingapp', ['title' => 'Laporan Cashflow'])

@push('styles')
    <style>
        .cashflow-detail summary::-webkit-details-marker {
            display: none;
        }

        .cashflow-detail-summary {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
            font-weight: 700;
            color: rgb(29 78 216);
        }

        .cashflow-detail-summary::before {
            content: "+";
            display: inline-flex;
            width: 1rem;
            height: 1rem;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: rgb(219 234 254);
            color: rgb(29 78 216);
            font-size: 0.7rem;
            line-height: 1;
        }

        .cashflow-detail[open] .cashflow-detail-summary::before {
            content: "-";
        }

        .cashflow-detail-list {
            margin-top: 0.55rem;
            display: grid;
            gap: 0.55rem;
        }

        .cashflow-detail-item {
            padding-top: 0.55rem;
            border-top: 1px solid rgb(226 232 240);
        }

        .cashflow-detail-ref {
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .cashflow-detail-amount {
            font-weight: 700;
            color: rgb(15 23 42);
        }
    </style>
@endpush

@section('content')
    @php
        $granularityLabels = [
            'day' => 'Harian',
            'week' => 'Mingguan',
            'month' => 'Bulanan',
        ];
        $monthLabels = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];
        $activeGranularityLabel = $granularityLabels[$chartGranularity] ?? 'Harian';
        $summaryIncomeRows = $cashflowSummary['income_rows'] ?? [];
        $summaryExpenseRows = $cashflowSummary['expense_rows'] ?? [];
        $summaryDetailRowCount = max(count($summaryIncomeRows), count($summaryExpenseRows), 1);
        $openingBalanceReference = $dateFrom->copy();
        $openingBalanceLabel =
            'Saldo Awal Bulan ' .
            ($monthLabels[(int) $openingBalanceReference->month] ?? $openingBalanceReference->format('m')) .
            ' ' .
            $openingBalanceReference->year;
    @endphp

    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Laporan Cashflow</h1>
                <p class="dashboard-hero-subtitle">
                    Format laporan diubah menjadi worksheet pemasukan vs pengeluaran. Semua angka mengikuti filter tanggal
                    yang dipilih, sedangkan rincian bisa ditampilkan dalam mode {{ strtolower($activeGranularityLabel) }}.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('accountingapp.reports.cashflow') }}" class="form-grid mt-6">
            <div>
                <label class="form-label">Dari Tanggal</label>
                <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
            </div>

            <div>
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
            </div>

            <input type="hidden" name="chart_granularity" value="{{ $chartGranularity }}">
            @if ($chartGranularity === 'week' && $activeWeekNumber)
                <input type="hidden" name="week_number" value="{{ $activeWeekNumber }}">
            @endif

            <div class="flex items-end">
                <button type="submit" class="btn-primary w-full">Terapkan Filter</button>
            </div>

            <div class="flex items-end">
                <a href="{{ route('accountingapp.reports.cashflow', ['chart_granularity' => $chartGranularity]) }}"
                    class="btn-ghost w-full text-center">
                    Reset
                </a>
            </div>
        </form>
    </section>

    @include('partials.report-export-actions', [
        'excelUrl' => route('accountingapp.reports.cashflow.export.excel', request()->query()),
        'pdfUrl' => route('accountingapp.reports.cashflow.export.pdf', request()->query()),
        'caption' => 'Export cashflow sesuai filter yang sedang aktif.',
    ])

    {{-- <section class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
    Fokus laporan ini ada pada worksheet cashflow dan rincian per periode. Ringkasan angka utama tetap bisa dilihat di dashboard accounting.
  </section> --}}

    <section class="table-shell mt-6">
        <div class="table-head">
            Ringkasan Cashflow Periode {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th colspan="2" class="text-center">Debet</th>
                        <th colspan="2" class="text-center">Kredit</th>
                    </tr>
                    <tr>
                        <th>Keterangan</th>
                        <th class="text-right">Nominal</th>
                        <th>Keterangan</th>
                        <th class="text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @for ($rowIndex = 0; $rowIndex < $summaryDetailRowCount; $rowIndex++)
                        @php
                            $incomeRow = $summaryIncomeRows[$rowIndex] ?? null;
                            $expenseRow = $summaryExpenseRows[$rowIndex] ?? null;
                            $incomeLabel =
                                ($incomeRow['label'] ?? null) === 'Saldo Awal'
                                    ? $openingBalanceLabel
                                    : $incomeRow['label'] ?? '';
                        @endphp
                        <tr>
                            <td>{{ $incomeLabel }}</td>
                            <td class="text-right">
                                {{ $incomeRow ? 'Rp ' . number_format((float) $incomeRow['amount'], 0, ',', '.') : '' }}
                            </td>
                            <td>{{ $expenseRow['label'] ?? '' }}</td>
                            <td class="text-right">
                                {{ $expenseRow ? 'Rp ' . number_format((float) $expenseRow['amount'], 0, ',', '.') : '' }}
                            </td>
                        </tr>
                    @endfor

                    <tr class="bg-emerald-50 font-semibold text-slate-900">
                        <td>Total Pemasukan</td>
                        <td class="text-right">Rp
                            {{ number_format((float) ($cashflowSummary['total_income'] ?? 0), 0, ',', '.') }}</td>
                        <td>Total Pengeluaran</td>
                        <td class="text-right">Rp
                            {{ number_format((float) ($cashflowSummary['total_expense'] ?? 0), 0, ',', '.') }}</td>
                    </tr>
                    <tr class="bg-sky-50 font-semibold text-slate-900">
                        <td>Saldo Awal</td>
                        <td class="text-right">Rp
                            {{ number_format((float) ($cashflowSummary['opening_balance'] ?? 0), 0, ',', '.') }}</td>
                        <td>Saldo Akhir</td>
                        <td class="text-right">Rp
                            {{ number_format((float) ($cashflowSummary['ending_balance'] ?? 0), 0, ',', '.') }}</td>
                    </tr>
                    <tr class="bg-slate-100 text-base font-bold text-slate-900">
                        <td>Grand Total</td>
                        <td class="text-right">Rp
                            {{ number_format((float) ($cashflowSummary['grand_total'] ?? 0), 0, ',', '.') }}</td>
                        <td>Grand Total</td>
                        <td class="text-right">Rp
                            {{ number_format((float) ($cashflowSummary['grand_total'] ?? 0), 0, ',', '.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    @if ($chartGranularity === 'week' && !empty($cashflowWeekTabs))
        <section class="mt-6">
            <div class="mb-3">
                <h2 class="section-title">Ringkasan Global Mingguan</h2>
                <p class="section-subtitle">Pilih week untuk melihat akumulasi lintas bulan, lalu baca rincian detailnya di
                    tabel bawah.</p>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($cashflowWeekTabs as $weekTab)
                    <a href="{{ request()->fullUrlWithQuery(['chart_granularity' => 'week', 'week_number' => $weekTab['week_number']]) }}"
                        class="section-card transition {{ $activeWeekNumber === $weekTab['week_number'] ? 'ring-2 ring-brand-200 border-brand-200 bg-brand-50/70 shadow-[0_14px_28px_-22px_rgba(52,85,219,0.55)]' : 'hover:border-slate-300 hover:bg-slate-50/70' }}">
                        <div>
                            <p class="metric-label">{{ $weekTab['label'] }}</p>
                            <p
                                class="text-lg font-display {{ (float) $weekTab['net_cashflow'] >= 0 ? 'text-slate-900' : 'text-rose-700' }}">
                                Rp {{ number_format((float) $weekTab['net_cashflow'], 0, ',', '.') }}
                            </p>
                        </div>

                        <div class="mt-4 space-y-2 text-xs">
                            <div class="flex items-start justify-between gap-3 border-t border-slate-200/80 pt-2">
                                <span class="text-slate-500">In</span>
                                <span class="text-right font-semibold leading-snug text-slate-800">
                                    Rp {{ number_format((float) $weekTab['total_income'], 0, ',', '.') }}
                                </span>
                            </div>
                            <div class="flex items-start justify-between gap-3 border-t border-slate-200/80 pt-2">
                                <span class="text-slate-500">Out</span>
                                <span class="text-right font-semibold leading-snug text-slate-800">
                                    Rp {{ number_format((float) $weekTab['total_expense'], 0, ',', '.') }}
                                </span>
                            </div>
                        </div>

                        @if (!empty($weekTab['months']))
                            <p class="mt-4 text-xs text-slate-500">
                                {{ implode(', ', $weekTab['months']) }}
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="table-shell mt-6">
        <div class="table-head flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <div>Rincian
                    {{ $activeGranularityLabel }}{{ $chartGranularity === 'week' && $activeWeekNumber ? ' - Week ' . $activeWeekNumber : '' }}
                </div>
                @if ($chartGranularity === 'week' && $activeWeekNumber)
                    <p class="mt-1 text-xs font-normal text-slate-500">
                        Tabel ini hanya menampilkan detail untuk Week {{ $activeWeekNumber }} pada semua bulan yang masuk
                        ke filter.
                    </p>
                @endif
            </div>
            <div class="inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                @foreach ($granularityLabels as $value => $label)
                    <a href="{{ request()->fullUrlWithQuery([
                        'chart_granularity' => $value,
                        'week_number' => $value === 'week' ? $activeWeekNumber ?? 1 : request('week_number'),
                    ]) }}"
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $chartGranularity === $value ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th class="text-right">Saldo Awal</th>
                        <th>Rincian Pemasukan</th>
                        <th class="text-right">Total Pemasukan</th>
                        <th>Rincian Pengeluaran</th>
                        <th class="text-right">Total Pengeluaran</th>
                        <th class="text-right">Saldo Akhir</th>
                        <th class="text-right">Grand Total</th>
                    </tr>
                </thead>
                <tbody>
                    @php $currentWeekGroup = null; @endphp
                    @forelse ($cashflowPeriods as $period)
                        @if ($chartGranularity === 'week' && ($period['group_label'] ?? null) !== $currentWeekGroup)
                            <tr class="bg-slate-50">
                                <td colspan="8"
                                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    {{ $period['group_label'] }}
                                </td>
                            </tr>
                            @php $currentWeekGroup = $period['group_label']; @endphp
                        @endif
                        <tr>
                            <td class="font-semibold text-slate-800">
                                <div>{{ $period['label'] }}</div>
                                @if (!empty($period['sub_label']))
                                    <div class="mt-1 text-xs font-normal text-slate-500">{{ $period['sub_label'] }}</div>
                                @endif
                            </td>
                            <td class="text-right">Rp
                                {{ number_format((float) ($period['opening_balance'] ?? 0), 0, ',', '.') }}</td>
                            <td class="text-xs text-slate-600">
                                @if (!empty($period['income_breakdown']))
                                    <details class="cashflow-detail">
                                        <summary class="cashflow-detail-summary">
                                            Detail {{ count($period['income_breakdown']) }} item
                                        </summary>
                                        <div class="cashflow-detail-list">
                                            @foreach ($period['income_breakdown'] as $row)
                                                <div class="cashflow-detail-item">
                                                    @if (!empty($row['reference']))
                                                        <div>{{ $row['label'] }}</div>
                                                        <div class="cashflow-detail-ref">{{ $row['reference'] }}:</div>
                                                        <div class="cashflow-detail-amount">Rp {{ number_format((float) $row['amount'], 0, ',', '.') }}</div>
                                                    @else
                                                        <div>{{ $row['label'] }}:</div>
                                                        <div class="cashflow-detail-amount">Rp {{ number_format((float) $row['amount'], 0, ',', '.') }}</div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-right">Rp
                                {{ number_format((float) ($period['total_income'] ?? 0), 0, ',', '.') }}</td>
                            <td class="text-xs text-slate-600">
                                @forelse ($period['expense_breakdown'] as $row)
                                    <div>{{ $row['label'] }}: Rp {{ number_format((float) $row['amount'], 0, ',', '.') }}
                                    </div>
                                @empty
                                    -
                                @endforelse
                            </td>
                            <td class="text-right">Rp
                                {{ number_format((float) ($period['total_expense'] ?? 0), 0, ',', '.') }}</td>
                            <td
                                class="text-right font-semibold {{ (float) ($period['ending_balance'] ?? 0) >= 0 ? 'text-slate-900' : 'text-rose-600' }}">
                                Rp {{ number_format((float) ($period['ending_balance'] ?? 0), 0, ',', '.') }}
                            </td>
                            <td class="text-right font-semibold">Rp
                                {{ number_format((float) ($period['grand_total'] ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-center text-slate-500">
                                Tidak ada data cashflow pada periode ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
