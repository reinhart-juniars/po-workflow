@extends('layouts.accountingapp', ['title' => 'Laporan Neraca'])

@section('content')
    @php
        $formatCurrency = function (float $amount): string {
            $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
            $suffix = $amount < 0 ? ')' : '';

            return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
        };
    @endphp

    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Laporan Neraca</h1>
                <p class="dashboard-hero-subtitle">
                    Neraca menampilkan posisi aset, kewajiban, dan modal per tanggal laporan.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('accountingapp.reports.balance-sheet') }}" class="form-grid mt-6">
            <div>
                <label class="form-label">Tanggal Laporan</label>
                <input type="date" name="report_date" value="{{ $reportDate->toDateString() }}" class="form-control">
            </div>

            <div class="flex flex-col justify-end gap-2">
                <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                    <input type="checkbox" name="show_breakdown" value="1"
                        {{ request()->boolean('show_breakdown') ? 'checked' : '' }}
                        class="rounded border-slate-300">
                    Tampilkan breakdown detail
                </label>
                <button type="submit" class="btn-primary w-full">Tampilkan Neraca</button>
            </div>
        </form>
    </section>

    @include('partials.report-export-actions', [
        'excelUrl' => route('accountingapp.reports.balance-sheet.export.excel', request()->query()),
        'pdfUrl' => route('accountingapp.reports.balance-sheet.export.pdf', request()->query()),
        'caption' => 'Export neraca mengikuti tanggal laporan yang sedang dipilih.',
    ])

    <section class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
        Posisi neraca per <strong>{{ $reportDate->format('d M Y') }}</strong>. Nilai persediaan memakai stock opname
        terakhir, <br>
        jika belum ada stock opname, sistem memakai fallback opening + pembelian.
    </section>

    @if($warnings->isNotEmpty())
        <section class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div class="font-semibold">Catatan Akurasi</div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="section-card">
            <div class="metric-label">Total Aktiva Lancar</div>
            <div class="stat-value text-[1.35rem]">{{ $formatCurrency((float) $totalAssets) }}</div>
        </div>
        <div class="section-card">
            <div class="metric-label">Total Kewajiban</div>
            <div class="stat-value text-[1.35rem]">{{ $formatCurrency((float) $totalLiabilities) }}</div>
        </div>
        <div class="section-card">
            <div class="metric-label">Modal</div>
            <div class="stat-value text-[1.35rem] {{ $capitalAmount >= 0 ? 'text-slate-900' : 'text-rose-700' }}">
                {{ $formatCurrency((float) $capitalAmount) }}
            </div>
        </div>
        <div class="section-card">
            <div class="metric-label">Kekayaan</div>
            <div class="stat-value text-[1.35rem] {{ $wealthAmount >= 0 ? 'text-slate-900' : 'text-rose-700' }}">
                {{ $formatCurrency((float) $wealthAmount) }}
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2 items-stretch">
        <section class="table-shell flex h-full flex-col">
            <div class="table-head">Aktiva Lancar</div>
            <div class="flex flex-1 flex-col gap-3 p-4">
                @foreach ($assetGroups as $group)
                    <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 bg-sky-50 px-4 py-3 marker:hidden">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ $group['title'] }}</span>
                                <span
                                    class="text-xs text-slate-500">{{ number_format($group['rows']->count(), 0, ',', '.') }}
                                    detail</span>
                            </span>
                            <span class="flex items-center gap-3 text-right">
                                <span
                                    class="font-semibold text-slate-900">{{ $formatCurrency((float) $group['total']) }}</span>
                                <span class="text-slate-400 transition group-open:rotate-180">&#9662;</span>
                            </span>
                        </summary>
                        <div class="data-table-wrap border-t border-slate-200">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Keterangan</th>
                                        <th>Catatan</th>
                                        <th class="text-right">Nominal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($group['rows'] as $row)
                                        <tr>
                                            <td>{{ $row['label'] }}</td>
                                            <td>{{ $row['meta'] ?? '' }}</td>
                                            <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2">{{ $group['empty_label'] }}</td>
                                            <td class="text-right">Rp 0</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach

                <div
                    class="mt-auto flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-base font-bold text-slate-900">
                    <span>Total Aset</span>
                    <span class="text-right">{{ $formatCurrency((float) $totalAssets) }}</span>
                </div>
            </div>
        </section>

        <section class="table-shell flex h-full flex-col">
            <div class="table-head">Kewajiban</div>
            <div class="flex flex-1 flex-col gap-3 p-4">
                @foreach ($liabilityGroups as $group)
                    <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 bg-rose-50 px-4 py-3 marker:hidden">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ $group['title'] }}</span>
                                <span
                                    class="text-xs text-slate-500">{{ number_format($group['rows']->count(), 0, ',', '.') }}
                                    detail</span>
                            </span>
                            <span class="flex items-center gap-3 text-right">
                                <span
                                    class="font-semibold text-slate-900">{{ $formatCurrency((float) $group['total']) }}</span>
                                <span class="text-slate-400 transition group-open:rotate-180">&#9662;</span>
                            </span>
                        </summary>
                        <div class="data-table-wrap border-t border-slate-200">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Keterangan</th>
                                        <th>Catatan</th>
                                        <th class="text-right">Nominal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($group['rows'] as $row)
                                        <tr>
                                            <td>{{ $row['label'] }}</td>
                                            <td>{{ $row['meta'] ?? '' }}</td>
                                            <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2">{{ $group['empty_label'] }}</td>
                                            <td class="text-right">Rp 0</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach

                <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 bg-amber-50 px-4 py-3 marker:hidden">
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">Modal</span>
                            <span class="text-xs text-slate-500">{{ number_format($capitalRows->count(), 0, ',', '.') }}
                                detail</span>
                        </span>
                        <span class="flex items-center gap-3 text-right">
                            <span class="font-semibold text-slate-900">{{ $formatCurrency((float) $capitalAmount) }}</span>
                            <span class="text-slate-400 transition group-open:rotate-180">&#9662;</span>
                        </span>
                    </summary>
                    <div class="data-table-wrap border-t border-slate-200">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Keterangan</th>
                                    <th>Catatan</th>
                                    <th class="text-right">Nominal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($capitalRows as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td>{{ $row['meta'] ?? '' }}</td>
                                        <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>

                <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 bg-emerald-50 px-4 py-3 marker:hidden">
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">Kekayaan</span>
                            <span class="text-xs text-slate-500">{{ number_format($wealthRows->count(), 0, ',', '.') }}
                                detail</span>
                        </span>
                        <span class="flex items-center gap-3 text-right">
                            <span class="font-semibold text-slate-900">{{ $formatCurrency((float) $wealthAmount) }}</span>
                            <span class="text-slate-400 transition group-open:rotate-180">&#9662;</span>
                        </span>
                    </summary>
                    <div class="data-table-wrap border-t border-slate-200">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Keterangan</th>
                                    <th>Catatan</th>
                                    <th class="text-right">Nominal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($wealthRows as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td>{{ $row['meta'] ?? '' }}</td>
                                        <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>

                {{-- <div
                    class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-slate-900">
                    <span>Total Modal & Kekayaan</span>
                    <span class="text-right">{{ $formatCurrency((float) $equityAmount) }}</span>
                </div> --}}

                <div
                    class="mt-auto flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-base font-bold text-slate-900">
                    <span>Total</span>
                    <span class="text-right">{{ $formatCurrency((float) $totalLiabilitiesAndEquity) }}</span>
                </div>
            </div>
        </section>
    </div>
@endsection
