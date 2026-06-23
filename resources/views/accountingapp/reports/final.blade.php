@extends('layouts.accountingapp', ['title' => 'Laporan Final'])

@push('styles')
    <style>
        .final-report-shell {
            color: rgb(15 23 42);
            font-feature-settings: "tnum" 1, "lnum" 1;
            font-variant-numeric: tabular-nums lining-nums;
        }

        .final-card {
            border: 1px solid rgb(226 232 240);
            border-radius: 1rem;
            background: white;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .final-card-head {
            padding: 0.95rem 1.15rem;
            border-bottom: 1px solid rgb(226 232 240);
            background: rgb(248 250 252);
        }

        .final-card-title {
            font-size: 13.5px;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: rgb(15 23 42);
        }

        .final-card-subtitle {
            margin-top: 0.18rem;
            font-size: 11.5px;
            font-weight: 600;
            color: rgb(100 116 139);
        }

        .final-card-body {
            padding: 0.85rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            flex: 1 1 auto;
        }

        .final-card-body .final-line.total {
            margin-top: auto;
        }

        .final-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.85rem;
            padding: 0.7rem 0.85rem;
            border: 1px solid rgb(226 232 240);
            border-radius: 0.75rem;
            background: rgb(248 250 252 / 0.6);
        }

        .final-line .left {
            display: grid;
            gap: 0.1rem;
        }

        .final-line .label {
            font-size: 13px;
            font-weight: 700;
            color: rgb(15 23 42);
            letter-spacing: -0.01em;
        }

        .final-line .meta {
            font-size: 11px;
            font-weight: 600;
            color: rgb(100 116 139);
        }

        .final-line .amount {
            font-size: 13.5px;
            font-weight: 700;
            color: rgb(15 23 42);
            white-space: nowrap;
        }

        .final-line.tone-blue {
            background: rgb(239 246 255);
            border-color: rgb(191 219 254);
        }

        .final-line.tone-rose {
            background: rgb(254 242 242);
            border-color: rgb(254 205 211);
        }

        .final-line.tone-amber {
            background: rgb(254 252 232);
            border-color: rgb(253 230 138);
        }

        .final-line.tone-emerald {
            background: rgb(236 253 245);
            border-color: rgb(167 243 208);
        }

        .final-line.total {
            background: rgb(220 252 231);
            border-color: rgb(134 239 172);
            font-weight: 800;
        }

        .final-line.total .label,
        .final-line.total .amount {
            color: rgb(20 83 45);
            font-weight: 800;
        }

        .final-pl-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
        }

        .final-pl-table td {
            padding: 0.65rem 0.85rem;
            border-top: 1px solid rgb(226 232 240);
            vertical-align: top;
        }

        .final-pl-table tr:first-child td {
            border-top: 0;
        }

        .final-pl-table td.label-cell {
            color: rgb(15 23 42);
            font-weight: 600;
        }

        .final-pl-table td.value-cell {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .final-pl-table tr.section-row td {
            background: rgb(241 245 249);
            font-weight: 800;
            font-size: 12px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: rgb(15 23 42);
        }

        .final-pl-table tr.subtotal-row td {
            background: rgb(226 232 240);
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .final-pl-table tr.major-row td {
            background: rgb(219 234 254);
            font-weight: 800;
            color: rgb(15 23 42);
            font-size: 13.5px;
        }

        .final-pl-table tr.profit-row td {
            background: rgb(220 252 231);
            color: rgb(20 83 45);
            font-weight: 800;
        }

        .final-pl-table tr.profit-row.negative td {
            background: rgb(254 226 226);
            color: rgb(127 29 29);
        }

        .final-pl-table tr.total-check-row td {
            background: rgb(254 252 232);
            color: rgb(120 53 15);
            font-weight: 800;
        }

        .final-pl-table .indent {
            padding-left: 1.5rem;
            font-weight: 600;
            color: rgb(51 65 85);
        }

        .final-pl-table col.col-label { width: auto; }
        .final-pl-table col.col-inner { width: 22%; }
        .final-pl-table col.col-outer { width: 22%; }

        .final-pl-table tr.spacer-row td {
            padding: 0.35rem 0.85rem;
            background: white;
            border-top: 0;
        }

        .final-pl-table tr.grand-total-row td {
            background: rgb(254 252 232);
            color: rgb(120 53 15);
            font-weight: 800;
        }

        .final-pl-table .extra-tag {
            display: inline-block;
            margin-left: 0.5rem;
            padding: 0.05rem 0.45rem;
            border-radius: 0.5rem;
            background: rgb(241 245 249);
            font-size: 10px;
            font-weight: 700;
            color: rgb(71 85 105);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
    </style>
@endpush

@section('content')
    @php
        $formatCurrency = function (float $amount): string {
            if (abs($amount) < 0.005) {
                return 'Rp 0';
            }

            $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
            $suffix = $amount < 0 ? ')' : '';

            return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
        };
    @endphp

    <div class="final-report-shell space-y-5">
        <section class="dashboard-hero">
            <div class="page-toolbar">
                <div>
                    <h1 class="dashboard-hero-title">Laporan Final</h1>
                    <p class="dashboard-hero-subtitle">
                        Gabungan ringkas Laporan Neraca dan Laporan Laba Rugi dalam satu halaman. <br>
                        Neraca memakai posisi per tanggal akhir, laba rugi memakai rentang tanggal yang dipilih.
                    </p>
                </div>
            </div>

            <form method="GET" action="{{ route('accountingapp.reports.final') }}" class="form-grid mt-6">
                <div>
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
                </div>
                <div>
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full">Tampilkan Laporan</button>
                </div>
                <div class="flex items-end">
                    <a href="{{ route('accountingapp.reports.final') }}" class="btn-ghost w-full text-center">Reset</a>
                </div>
            </form>
        </section>

        @include('partials.report-export-actions', [
            'excelUrl' => route('accountingapp.reports.final.export.excel', request()->query()),
            'pdfUrl' => route('accountingapp.reports.final.export.pdf', request()->query()),
            'caption' => 'Export laporan final mengikuti rentang tanggal yang sedang dipilih.',
        ])

        {{-- ====== NERACA ====== --}}
        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Laporan Neraca</h2>
                <p class="text-xs font-semibold text-slate-500">Posisi per
                    {{ $dateTo->format('d M Y') }}.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="final-card">
                    <div class="final-card-head">
                        <div class="final-card-title">Aktiva Lancar</div>
                        <div class="final-card-subtitle">Aset yang dimiliki sampai tanggal laporan</div>
                    </div>
                    <div class="final-card-body">
                        @foreach ($balanceSummary['assets'] as $asset)
                            <div class="final-line tone-blue">
                                <div class="left">
                                    <span class="label">{{ $asset['label'] }}</span>
                                </div>
                                <div class="amount">{{ $formatCurrency((float) $asset['amount']) }}</div>
                            </div>
                        @endforeach

                        <div class="final-line total">
                            <div class="left">
                                <span class="label">Total Aset</span>
                            </div>
                            <div class="amount">{{ $formatCurrency((float) $balanceSummary['totalAssets']) }}</div>
                        </div>
                    </div>
                </div>

                <div class="final-card">
                    <div class="final-card-head">
                        <div class="final-card-title">Kewajiban</div>
                        <div class="final-card-subtitle">Hutang, modal, dan kekayaan sampai tanggal laporan</div>
                    </div>
                    <div class="final-card-body">
                        <div class="final-line tone-rose">
                            <div class="left">
                                <span class="label">{{ $balanceSummary['liability']['label'] }}</span>
                            </div>
                            <div class="amount">{{ $formatCurrency((float) $balanceSummary['liability']['amount']) }}
                            </div>
                        </div>

                        <div class="final-line tone-amber">
                            <div class="left">
                                <span class="label">{{ $balanceSummary['capital']['label'] }}</span>
                            </div>
                            <div class="amount">{{ $formatCurrency((float) $balanceSummary['capital']['amount']) }}
                            </div>
                        </div>

                        <div class="final-line tone-emerald">
                            <div class="left">
                                <span class="label">{{ $balanceSummary['wealth']['label'] }}</span>
                            </div>
                            <div class="amount">{{ $formatCurrency((float) $balanceSummary['wealth']['amount']) }}</div>
                        </div>

                        <div class="final-line total">
                            <div class="left">
                                <span class="label">Total</span>
                            </div>
                            <div class="amount">
                                {{ $formatCurrency((float) $balanceSummary['totalLiabilitiesAndEquity']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ====== LABA RUGI ====== --}}
        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Laporan Laba Rugi</h2>
                <p class="text-xs font-semibold text-slate-500">Rentang
                    {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}.</p>
            </div>

            <div class="final-card">
                <table class="final-pl-table">
                    <colgroup>
                        <col class="col-label">
                        <col class="col-inner">
                        <col class="col-outer">
                    </colgroup>
                    <tbody>
                        {{-- ===== PENJUALAN: total di kolom dalam (kiri) ===== --}}
                        <tr class="major-row">
                            <td class="label-cell">PENJUALAN</td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['totalPenjualan']) }}</td>
                            <td class="value-cell"></td>
                        </tr>

                        <tr>
                            <td class="indent">Bahan Baku Lama</td>
                            <td class="value-cell"></td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['bahanBakuLama']) }}</td>
                        </tr>
                        <tr>
                            <td class="indent">Bahan Baku Baru</td>
                            <td class="value-cell"></td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['bahanBakuBaru']) }}</td>
                        </tr>
                        <tr>
                            <td class="indent">Sisa Stok</td>
                            <td class="value-cell"></td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['sisaStok']) }}</td>
                        </tr>
                        <tr class="subtotal-row">
                            <td class="label-cell">Bahan Baku Terpakai</td>
                            <td class="value-cell"></td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['bahanBakuTerpakai']) }}</td>
                        </tr>

                        <tr class="spacer-row">
                            <td colspan="3">&nbsp;</td>
                        </tr>

                        {{-- ===== PENGELUARAN ===== --}}
                        <tr class="section-row">
                            <td class="label-cell">PENGELUARAN</td>
                            <td class="value-cell"></td>
                            <td class="value-cell"></td>
                        </tr>

                        @foreach ($profitLoss['pengeluaranRows'] as $row)
                            <tr>
                                <td class="indent">{{ $row['label'] }}</td>
                                <td class="value-cell"></td>
                                <td class="value-cell">{{ $formatCurrency((float) $row['amount']) }}</td>
                            </tr>
                        @endforeach

                        <tr class="subtotal-row">
                            <td class="label-cell">TOTAL</td>
                            <td class="value-cell"></td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['totalPengeluaran']) }}</td>
                        </tr>

                        <tr class="spacer-row">
                            <td colspan="3">&nbsp;</td>
                        </tr>

                        {{-- ===== LABA ===== --}}
                        @php
                            $penjualan = (float) $profitLoss['totalPenjualan'];
                            $laba = (float) $profitLoss['labaRugi'];
                            $marginPct = abs($penjualan) > 0.005 ? ($laba / $penjualan) * 100 : 0;
                            $marginLabel = number_format($marginPct, 2, ',', '.') . '%';
                        @endphp
                        <tr class="profit-row {{ $laba < 0 ? 'negative' : '' }}">
                            <td class="label-cell">
                                LABA
                                <span class="extra-tag">{{ $marginLabel }}</span>
                            </td>
                            <td class="value-cell"></td>
                            <td class="value-cell">{{ $formatCurrency($laba) }}</td>
                        </tr>

                        {{-- ===== TOTAL (cek balance: kiri = kanan) ===== --}}
                        <tr class="grand-total-row">
                            <td class="label-cell">TOTAL</td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['totalPenjualan']) }}</td>
                            <td class="value-cell">{{ $formatCurrency((float) $profitLoss['total']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
