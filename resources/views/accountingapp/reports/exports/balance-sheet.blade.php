<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Neraca</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #0f172a;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 20px;
        }

        p {
            margin: 0;
        }

        .meta {
            margin-bottom: 14px;
            color: #475569;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #cbd5e1;
            padding: 5px 7px;
            vertical-align: top;
        }

        th {
            background: #e2e8f0;
            text-align: left;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            margin: 0 0 5px;
        }

        .group-asset {
            background: #e0f2fe;
            font-weight: bold;
        }

        .group-liability {
            background: #ffe4e6;
            font-weight: bold;
        }

        .group-equity {
            background: #fef3c7;
            font-weight: bold;
        }

        .group-wealth {
            background: #dcfce7;
            font-weight: bold;
        }

        .subtotal {
            background: #f8fafc;
            font-weight: bold;
        }

        .grand {
            background: #dcfce7;
            font-weight: bold;
        }
    </style>
</head>

<body>
    @php
        $sideLayout = $sideLayout ?? false;
        $showBreakdown = $showBreakdown ?? false;
        $formatCurrency = function (float $amount): string {
            $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
            $suffix = $amount < 0 ? ')' : '';
            return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
        };
        $colspanBreakdown = $showBreakdown ? 2 : 1;
    @endphp

    <h1>Laporan Neraca</h1>
    <div class="meta">
        <p>Per posisi: {{ $reportDate->format('d M Y') }}</p>
    </div>

    @if ($sideLayout)
        {{-- ===== PDF: dua kolom kiri-kanan ===== --}}
        <table style="border: none;">
            <tr>
                <td style="width:50%; vertical-align:top; border:none; padding-right:6px;">
                    <div class="section-title">Aktiva Lancar</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Keterangan</th>
                                @if ($showBreakdown)<th>Catatan</th>@endif
                                <th class="text-right">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assetGroups as $group)
                                <tr class="group-asset">
                                    <td colspan="{{ $colspanBreakdown }}">{{ $group['title'] }}</td>
                                    <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                                </tr>
                                @if ($showBreakdown)
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
                                    <tr class="subtotal">
                                        <td colspan="2">Total {{ $group['title'] }}</td>
                                        <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                            <tr class="grand">
                                <td colspan="{{ $colspanBreakdown }}"><strong>Total Aset</strong></td>
                                <td class="text-right">{{ $formatCurrency((float) $totalAssets) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>

                <td style="width:50%; vertical-align:top; border:none; padding-left:6px;">
                    <div class="section-title">Kewajiban, Modal & Kekayaan</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Keterangan</th>
                                @if ($showBreakdown)<th>Catatan</th>@endif
                                <th class="text-right">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($liabilityGroups as $group)
                                <tr class="group-liability">
                                    <td colspan="{{ $colspanBreakdown }}">{{ $group['title'] }}</td>
                                    <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                                </tr>
                                @if ($showBreakdown)
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
                                    <tr class="subtotal">
                                        <td colspan="2">Total {{ $group['title'] }}</td>
                                        <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                                    </tr>
                                @endif
                            @endforeach

                            <tr class="group-equity">
                                <td colspan="{{ $colspanBreakdown }}">Modal</td>
                                <td class="text-right">{{ $formatCurrency((float) $capitalAmount) }}</td>
                            </tr>
                            @if ($showBreakdown)
                                @foreach ($capitalRows as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td>{{ $row['meta'] ?? '' }}</td>
                                        <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="subtotal">
                                    <td colspan="2">Total Modal</td>
                                    <td class="text-right">{{ $formatCurrency((float) $capitalAmount) }}</td>
                                </tr>
                            @endif

                            <tr class="group-wealth">
                                <td colspan="{{ $colspanBreakdown }}">Kekayaan</td>
                                <td class="text-right">{{ $formatCurrency((float) $wealthAmount) }}</td>
                            </tr>
                            @if ($showBreakdown)
                                @foreach ($wealthRows as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td>{{ $row['meta'] ?? '' }}</td>
                                        <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="subtotal">
                                    <td colspan="2">Total Kekayaan</td>
                                    <td class="text-right">{{ $formatCurrency((float) $wealthAmount) }}</td>
                                </tr>
                                <tr class="subtotal">
                                    <td colspan="2">Total Modal + Kekayaan</td>
                                    <td class="text-right">{{ $formatCurrency((float) $equityAmount) }}</td>
                                </tr>
                            @endif

                            <tr class="grand">
                                <td colspan="{{ $colspanBreakdown }}"><strong>Total Kewajiban + Modal + Kekayaan</strong></td>
                                <td class="text-right">{{ $formatCurrency((float) $totalLiabilitiesAndEquity) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>

    @else
        {{-- ===== EXCEL: stacked vertikal (nested table tidak support) ===== --}}
        <div class="section">
            <div class="section-title">Aktiva Lancar</div>
            <table>
                <thead>
                    <tr>
                        <th>Keterangan</th>
                        @if ($showBreakdown)<th>Catatan</th>@endif
                        <th class="text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assetGroups as $group)
                        <tr class="group-asset">
                            <td colspan="{{ $colspanBreakdown }}">{{ $group['title'] }}</td>
                            <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                        </tr>
                        @if ($showBreakdown)
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
                            <tr class="subtotal">
                                <td colspan="2">Total {{ $group['title'] }}</td>
                                <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    <tr class="grand">
                        <td colspan="{{ $colspanBreakdown }}">Total Aset</td>
                        <td class="text-right">{{ $formatCurrency((float) $totalAssets) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Kewajiban, Modal dan Kekayaan</div>
            <table>
                <thead>
                    <tr>
                        <th>Keterangan</th>
                        @if ($showBreakdown)<th>Catatan</th>@endif
                        <th class="text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($liabilityGroups as $group)
                        <tr class="group-liability">
                            <td colspan="{{ $colspanBreakdown }}">{{ $group['title'] }}</td>
                            <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                        </tr>
                        @if ($showBreakdown)
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
                            <tr class="subtotal">
                                <td colspan="2">Total {{ $group['title'] }}</td>
                                <td class="text-right">{{ $formatCurrency((float) $group['total']) }}</td>
                            </tr>
                        @endif
                    @endforeach

                    <tr class="group-equity">
                        <td colspan="{{ $colspanBreakdown }}">Modal</td>
                        <td class="text-right">{{ $formatCurrency((float) $capitalAmount) }}</td>
                    </tr>
                    @if ($showBreakdown)
                        @foreach ($capitalRows as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ $row['meta'] ?? '' }}</td>
                                <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="subtotal">
                            <td colspan="2">Total Modal</td>
                            <td class="text-right">{{ $formatCurrency((float) $capitalAmount) }}</td>
                        </tr>
                    @endif

                    <tr class="group-wealth">
                        <td colspan="{{ $colspanBreakdown }}">Kekayaan</td>
                        <td class="text-right">{{ $formatCurrency((float) $wealthAmount) }}</td>
                    </tr>
                    @if ($showBreakdown)
                        @foreach ($wealthRows as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ $row['meta'] ?? '' }}</td>
                                <td class="text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="subtotal">
                            <td colspan="2">Total Kekayaan</td>
                            <td class="text-right">{{ $formatCurrency((float) $wealthAmount) }}</td>
                        </tr>
                        <tr class="subtotal">
                            <td colspan="2">Total Modal + Kekayaan</td>
                            <td class="text-right">{{ $formatCurrency((float) $equityAmount) }}</td>
                        </tr>
                    @endif

                    <tr class="grand">
                        <td colspan="{{ $colspanBreakdown }}">Total Kewajiban + Modal + Kekayaan</td>
                        <td class="text-right">{{ $formatCurrency((float) $totalLiabilitiesAndEquity) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</body>

</html>
