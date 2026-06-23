@extends('layouts.ownerapp', ['title' => 'Analisa HPP'])

@section('content')
    <section class="dashboard-hero">
        <h1 class="dashboard-hero-title">Analisa HPP</h1>
        <p class="dashboard-hero-subtitle">
            Membandingkan Bahan Baku & Overhead "Real" (cash out) dengan Bahan Baku & Overhead "Hitungan" (snapshot dari
            sales actual PO).
            <br>
        </p>
    </section>

    <section class="app-card mt-4 p-5">
        <form method="GET" action="{{ route('ownerapp.hpp-analysis') }}" class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div>
                <label class="mb-1.5 block">Dari Tanggal</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}">
            </div>
            <div>
                <label class="mb-1.5 block">Sampai Tanggal</label>
                <input type="date" name="date_to" value="{{ $dateTo }}">
            </div>
            <div class="md:col-span-2 flex items-end gap-2">
                <button class="btn-primary w-full" type="submit">Terapkan Filter</button>
                <a href="{{ route('ownerapp.hpp-analysis') }}" class="btn-ghost w-full">Reset</a>
            </div>
        </form>
    </section>

    <section class="stats-grid mt-4">
        <article class="stat-card">
            <p class="stat-label">Total Penjualan</p>
            <p class="stat-value text-brand-600">Rp {{ number_format($totalSales, 0, ',', '.') }}</p>
            <p class="stat-meta">PO selesai pada periode terpilih</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Bahan Baku Real</p>
            <p class="stat-value text-rose-600">Rp {{ number_format($totalHppReal, 0, ',', '.') }}</p>
            <p class="stat-meta">Cash out Bahan Baku + Kemasan</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">OHC Real</p>
            <p class="stat-value text-amber-600">Rp {{ number_format($totalOhcReal, 0, ',', '.') }}</p>
            <p class="stat-meta">Cash out selain Bahan Baku, Kemasan, Inventaris</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Selisih</p>
            <div class="mt-1 space-y-2">
                <div class="rounded-lg border border-slate-100 bg-slate-50/70 p-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">BB (Real − Hitungan)</p>
                    <p class="text-base font-semibold {{ $totalSelisihBb >= 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                        Rp {{ number_format($totalSelisihBb, 0, ',', '.') }}
                    </p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50/70 p-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">OHC (Real − Hitungan)</p>
                    <p class="text-base font-semibold {{ $totalSelisihOhc >= 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                        Rp {{ number_format($totalSelisihOhc, 0, ',', '.') }}
                    </p>
                </div>
            </div>
        </article>
        <article class="stat-card">
            <p class="stat-label">Bahan Baku Hitungan</p>
            <p class="stat-value text-rose-700">Rp {{ number_format($totalHppJual, 0, ',', '.') }}</p>
            <p class="stat-meta">Snapshot HPP dari sales actual PO</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">OHC Hitungan</p>
            <p class="stat-value text-amber-700">Rp {{ number_format($totalOhcJual, 0, ',', '.') }}</p>
            <p class="stat-meta">Snapshot OHC dari sales actual PO</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Profit</p>
            <div class="mt-1 space-y-2">
                <div class="rounded-lg border border-slate-100 bg-slate-50/70 p-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Hitungan</p>
                    <p class="text-base font-semibold {{ $totalProfitJual >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        Rp {{ number_format($totalProfitJual, 0, ',', '.') }}
                    </p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50/70 p-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Real</p>
                    <p class="text-base font-semibold {{ $totalProfitReal >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                        Rp {{ number_format($totalProfitReal, 0, ',', '.') }}
                    </p>
                </div>
            </div>
            <p class="stat-meta mt-2">Hitungan = (BB + OHC Hitungan) × markup &nbsp;·&nbsp; Real = Laba bersih Laporan Laba Rugi</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Profit (%)</p>
            <div class="mt-1 space-y-2">
                <div class="rounded-lg border border-slate-100 bg-slate-50/70 p-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Hitungan</p>
                    <p class="text-base font-semibold text-slate-900">
                        {{ $totalProfitPercent !== null ? number_format($totalProfitPercent, 2, ',', '.') . '%' : '-' }}
                    </p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50/70 p-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Real</p>
                    <p class="text-base font-semibold {{ ($totalProfitRealPercent ?? 0) >= 0 ? 'text-slate-900' : 'text-rose-700' }}">
                        {{ $totalProfitRealPercent !== null ? number_format($totalProfitRealPercent, 2, ',', '.') . '%' : '-' }}
                    </p>
                </div>
            </div>
            <p class="stat-meta mt-2">Hitungan = Profit ÷ Penjualan &nbsp;·&nbsp; Real = vs Penjualan (Laba Rugi)</p>
        </article>
    </section>

    <section class="table-shell mt-4">
        <div class="table-head">Analisa Perbandingan HPP per Periode</div>
        <div class="hpp-analysis-wrap">
            <table class="data-table hpp-analysis-table">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th class="text-right">Real <span class="text-[10px] font-normal text-slate-400">(BB / OHC)</span></th>
                        <th class="text-right">Hitungan <span class="text-[10px] font-normal text-slate-400">(BB / OHC)</span></th>
                        <th class="text-right">
                            Selisih
                            <div class="text-[10px] font-normal normal-case text-slate-400">Real − Hitungan (BB / OHC)</div>
                        </th>
                        <th class="text-right">Profit <span class="text-[10px] font-normal text-slate-400">(H / R)</span></th>
                        <th class="text-right">Profit % <span class="text-[10px] font-normal text-slate-400">(H / R)</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($periodRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-800 whitespace-nowrap">{{ $row['label'] }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div><span class="rp">Rp</span>{{ number_format($row['hpp_real'], 0, ',', '.') }} <span class="tag">(BB)</span></div>
                                <div class="text-slate-500"><span class="rp">Rp</span>{{ number_format($row['ohc_real'], 0, ',', '.') }} <span class="tag">(OHC)</span></div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap text-slate-600">
                                <div><span class="rp">Rp</span>{{ number_format($row['hpp_jual'], 0, ',', '.') }} <span class="tag">(BB)</span></div>
                                <div class="text-slate-500"><span class="rp">Rp</span>{{ number_format($row['ohc_jual'], 0, ',', '.') }} <span class="tag">(OHC)</span></div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div class="font-semibold {{ $row['selisih_bb'] >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                                    <span class="rp">Rp</span>{{ number_format($row['selisih_bb'], 0, ',', '.') }} <span class="tag">(BB)</span>
                                </div>
                                <div class="font-semibold {{ $row['selisih_ohc'] >= 0 ? 'text-rose-700' : 'text-emerald-700' }} text-slate-500">
                                    <span class="rp">Rp</span>{{ number_format($row['selisih_ohc'], 0, ',', '.') }} <span class="tag">(OHC)</span>
                                </div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div class="font-semibold {{ $row['profit_jual'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                    <span class="rp">Rp</span>{{ number_format($row['profit_jual'], 0, ',', '.') }} <span class="tag">(H)</span>
                                </div>
                                <div class="font-semibold {{ $row['profit_real'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }} text-[11px]">
                                    <span class="rp">Rp</span>{{ number_format($row['profit_real'], 0, ',', '.') }} <span class="tag">(R)</span>
                                </div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div class="font-semibold text-slate-800">
                                    {{ $row['profit_percent'] !== null ? number_format($row['profit_percent'], 2, ',', '.') . '%' : '-' }} <span class="tag">(H)</span>
                                </div>
                                <div class="font-semibold {{ ($row['profit_real_percent'] ?? 0) >= 0 ? 'text-slate-800' : 'text-rose-700' }} text-[11px]">
                                    {{ $row['profit_real_percent'] !== null ? number_format($row['profit_real_percent'], 2, ',', '.') . '%' : '-' }} <span class="tag">(R)</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-slate-500">Belum ada data periode.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-[10px] text-slate-500 border-t border-slate-100 leading-relaxed">
            <strong>BB</strong> = Bahan Baku &nbsp;·&nbsp;
            <strong>OHC</strong> = Overhead Cost (baris ke-2 lebih redup) &nbsp;·&nbsp;
            <strong>H</strong> = Hitungan / snapshot sales actual (baris atas) &nbsp;·&nbsp;
            <strong>R</strong> = Real / cash out (baris bawah) &nbsp;·&nbsp;
            <strong>Selisih</strong> = Real − Hitungan (BB & OHC) &nbsp;·&nbsp;
            <strong>Profit Real</strong> = Laba dari Laporan Laba Rugi
        </div>
    </section>
@endsection

@push('styles')
<style>
    /* Wrap container: no horizontal scroll, table shrinks to fit */
    .hpp-analysis-wrap {
        overflow: hidden;
    }
    .hpp-analysis-table {
        width: 100%;
        table-layout: auto;
        font-size: 11.5px;
    }
    .hpp-analysis-table th,
    .hpp-analysis-table td {
        padding: 0.4rem 0.5rem;
        line-height: 1.3;
    }
    .hpp-analysis-table thead th {
        background: rgb(248 250 252);
        color: rgb(15 23 42);
        font-weight: 700;
        font-size: 11px;
        text-transform: none;
        letter-spacing: 0;
    }
    .hpp-analysis-table .tabular-nums {
        font-variant-numeric: tabular-nums lining-nums;
        font-feature-settings: "tnum" 1, "lnum" 1;
    }
    /* "Rp" prefix dibuat redup biar angka jadi fokus utama */
    .hpp-analysis-table .rp {
        color: rgb(148 163 184);
        font-weight: 500;
        margin-right: 0.15rem;
    }
    /* Keterangan (BB)/(OHC)/(H)/(R) di belakang angka */
    .hpp-analysis-table .tag {
        color: rgb(148 163 184);
        font-weight: 400;
        font-size: 9.5px;
        margin-left: 0.1rem;
    }
    @media (max-width: 1100px) {
        .hpp-analysis-table {
            font-size: 10.5px;
        }
        .hpp-analysis-table th,
        .hpp-analysis-table td {
            padding: 0.35rem 0.4rem;
        }
    }
</style>
@endpush
