<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Final</title>
    <style>
        @page {
            margin: 18px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: #0f172a;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 17px;
        }

        p {
            margin: 0;
        }

        .meta {
            margin-bottom: 12px;
            color: #475569;
            line-height: 1.45;
        }

        .section-title {
            margin: 14px 0 6px;
            font-size: 12.5px;
            font-weight: bold;
        }

        .section-sub {
            margin: 0 0 6px;
            font-size: 9.5px;
            color: #64748b;
        }

        table.neraca,
        table.laba-rugi {
            width: 100%;
            border-collapse: collapse;
        }

        table.neraca td,
        table.neraca th,
        table.laba-rugi td,
        table.laba-rugi th {
            border: 1px solid #cbd5e1;
            padding: 5px 7px;
            vertical-align: top;
            line-height: 1.35;
        }

        table.neraca th {
            background: #e2e8f0;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .neraca-wrap {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .neraca-col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 6px;
        }

        .neraca-col.right {
            padding-right: 0;
            padding-left: 6px;
        }

        .num {
            text-align: right;
            white-space: nowrap;
        }

        .label-cell {
            font-weight: 600;
        }

        .meta-cell {
            font-size: 9px;
            color: #64748b;
        }

        tr.row-asset td {
            background: #eff6ff;
        }

        tr.row-liability td {
            background: #fef2f2;
        }

        tr.row-capital td {
            background: #fefce8;
        }

        tr.row-wealth td {
            background: #ecfdf5;
        }

        tr.row-spacer td {
            background: #ffffff;
        }

        tr.row-total td {
            background: #dcfce7;
            font-weight: bold;
            color: #14532d;
        }

        table.laba-rugi tr.section td {
            background: #f1f5f9;
            font-weight: bold;
            text-transform: uppercase;
        }

        table.laba-rugi tr.subtotal td {
            background: #e2e8f0;
            font-weight: bold;
        }

        table.laba-rugi tr.major td {
            background: #dbeafe;
            font-weight: bold;
            font-size: 11.5px;
        }

        table.laba-rugi tr.profit td {
            background: #dcfce7;
            font-weight: bold;
            color: #14532d;
        }

        table.laba-rugi tr.profit.negative td {
            background: #fee2e2;
            color: #7f1d1d;
        }

        table.laba-rugi tr.total-check td {
            background: #fefce8;
            font-weight: bold;
            color: #78350f;
        }

        .indent-cell {
            padding-left: 18px !important;
            font-weight: 600;
            color: #334155;
        }

        table.laba-rugi col.col-label { width: auto; }
        table.laba-rugi col.col-inner { width: 22%; }
        table.laba-rugi col.col-outer { width: 22%; }

        table.laba-rugi tr.spacer td {
            background: #ffffff;
            padding: 3px 6px;
            border-color: #ffffff;
        }

        table.laba-rugi tr.grand-total td {
            background: #fefce8;
            font-weight: bold;
            color: #78350f;
        }

        .extra-tag {
            display: inline-block;
            margin-left: 5px;
            padding: 0 4px;
            background: #f1f5f9;
            font-size: 8.5px;
            font-weight: bold;
            color: #475569;
            text-transform: uppercase;
        }
    </style>
</head>

<body>
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

    <h1>Laporan Final</h1>
    <div class="meta">
        <p>Rentang Tanggal: {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}</p>
        <p>Posisi Neraca per: {{ $dateTo->format('d M Y') }}</p>
    </div>

    {{-- ============ NERACA ============ --}}
    <div class="section-title">Laporan Neraca</div>
    {{-- <div class="section-sub">Tanpa breakdown detail.</div> --}}

    @php
        $assetCount = count($balanceSummary['assets']);
        $liabilitySideCount = 3;
        $spacerRows = max(0, $assetCount - $liabilitySideCount);
    @endphp

    <div class="neraca-wrap">
        <div class="neraca-col">
            <table class="neraca">
                <thead>
                    <tr>
                        <th>Aktiva Lancar</th>
                        <th class="num">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($balanceSummary['assets'] as $asset)
                        <tr class="row-asset">
                            <td class="label-cell">{{ $asset['label'] }}</td>
                            <td class="num">{{ $formatCurrency((float) $asset['amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="row-total">
                        <td class="label-cell">Total Aset</td>
                        <td class="num">{{ $formatCurrency((float) $balanceSummary['totalAssets']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="neraca-col right">
            <table class="neraca">
                <thead>
                    <tr>
                        <th>Kewajiban</th>
                        <th class="num">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-liability">
                        <td class="label-cell">{{ $balanceSummary['liability']['label'] }}</td>
                        <td class="num">{{ $formatCurrency((float) $balanceSummary['liability']['amount']) }}</td>
                    </tr>
                    <tr class="row-capital">
                        <td class="label-cell">{{ $balanceSummary['capital']['label'] }}</td>
                        <td class="num">{{ $formatCurrency((float) $balanceSummary['capital']['amount']) }}</td>
                    </tr>
                    <tr class="row-wealth">
                        <td class="label-cell">{{ $balanceSummary['wealth']['label'] }}</td>
                        <td class="num">{{ $formatCurrency((float) $balanceSummary['wealth']['amount']) }}</td>
                    </tr>
                    @for ($i = 0; $i < $spacerRows; $i++)
                        <tr class="row-spacer">
                            <td>&nbsp;</td>
                            <td class="num">&nbsp;</td>
                        </tr>
                    @endfor
                    <tr class="row-total">
                        <td class="label-cell">Total</td>
                        <td class="num">{{ $formatCurrency((float) $balanceSummary['totalLiabilitiesAndEquity']) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============ LABA RUGI ============ --}}
    <div class="section-title">Laporan Laba Rugi</div>
    <div class="section-sub">Rentang {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}.</div>

    <table class="laba-rugi">
        <colgroup>
            <col class="col-label">
            <col class="col-inner">
            <col class="col-outer">
        </colgroup>
        <tbody>
            {{-- ===== PENJUALAN: total di kolom dalam (kiri) ===== --}}
            <tr class="major">
                <td class="label-cell">PENJUALAN</td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['totalPenjualan']) }}</td>
                <td class="num"></td>
            </tr>
            <tr>
                <td class="indent-cell">Bahan Baku Lama</td>
                <td class="num"></td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['bahanBakuLama']) }}</td>
            </tr>
            <tr>
                <td class="indent-cell">Bahan Baku Baru</td>
                <td class="num"></td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['bahanBakuBaru']) }}</td>
            </tr>
            <tr>
                <td class="indent-cell">Sisa Stok</td>
                <td class="num"></td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['sisaStok']) }}</td>
            </tr>
            <tr class="subtotal">
                <td class="label-cell">Bahan Baku Terpakai</td>
                <td class="num"></td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['bahanBakuTerpakai']) }}</td>
            </tr>

            <tr class="spacer"><td colspan="3">&nbsp;</td></tr>

            {{-- ===== PENGELUARAN ===== --}}
            <tr class="section">
                <td class="label-cell">PENGELUARAN</td>
                <td></td>
                <td></td>
            </tr>
            @foreach ($profitLoss['pengeluaranRows'] as $row)
                <tr>
                    <td class="indent-cell">{{ $row['label'] }}</td>
                    <td class="num"></td>
                    <td class="num">{{ $formatCurrency((float) $row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td class="label-cell">TOTAL</td>
                <td class="num"></td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['totalPengeluaran']) }}</td>
            </tr>

            <tr class="spacer"><td colspan="3">&nbsp;</td></tr>

            {{-- ===== LABA ===== --}}
            @php
                $penjualan = (float) $profitLoss['totalPenjualan'];
                $laba = (float) $profitLoss['labaRugi'];
                $marginPct = abs($penjualan) > 0.005 ? ($laba / $penjualan) * 100 : 0;
                $marginLabel = number_format($marginPct, 2, ',', '.') . '%';
            @endphp
            <tr class="profit {{ $laba < 0 ? 'negative' : '' }}">
                <td class="label-cell">
                    LABA
                    <span class="extra-tag">{{ $marginLabel }}</span>
                </td>
                <td class="num"></td>
                <td class="num">{{ $formatCurrency($laba) }}</td>
            </tr>

            {{-- ===== TOTAL (cek balance) ===== --}}
            <tr class="grand-total">
                <td class="label-cell">TOTAL</td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['totalPenjualan']) }}</td>
                <td class="num">{{ $formatCurrency((float) $profitLoss['total']) }}</td>
            </tr>
        </tbody>
    </table>
</body>

</html>
