<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Laba Rugi</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
    h1 { margin: 0 0 4px; font-size: 20px; }
    p { margin: 0; }
    .meta { margin-bottom: 16px; color: #475569; }
    .section { margin-top: 18px; }
    .section-title { margin: 0 0 8px; font-size: 13px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
    th { background: #e2e8f0; text-align: left; }
    .text-right { text-align: right; }
    .muted { color: #64748b; }
    .section-row { background: #dbeafe; font-weight: bold; }
    .subtotal { background: #f8fafc; font-weight: bold; }
    .summary { background: #eff6ff; font-weight: bold; }
    .net { background: #dcfce7; font-weight: bold; }
    .negative { color: #be123c; }
    .warning { margin-top: 10px; padding: 8px 10px; border: 1px solid #fecdd3; background: #fff1f2; color: #881337; }
    .warning-title { font-weight: bold; margin-bottom: 4px; }

    table.laba-rugi tr.section td { background: #f1f5f9; font-weight: bold; text-transform: uppercase; }
    table.laba-rugi tr.subtotal td { background: #e2e8f0; font-weight: bold; }
    table.laba-rugi tr.major td { background: #dbeafe; font-weight: bold; font-size: 11.5px; }
    table.laba-rugi tr.profit td { background: #dcfce7; font-weight: bold; color: #14532d; }
    table.laba-rugi tr.profit.negative td { background: #fee2e2; color: #7f1d1d; }
    table.laba-rugi tr.grand-total td { background: #fefce8; font-weight: bold; color: #78350f; }
    table.laba-rugi tr.spacer td { background: #ffffff; padding: 3px 6px; border-color: #ffffff; }
    .indent-cell { padding-left: 18px !important; font-weight: 600; color: #334155; }
    .num { text-align: right; white-space: nowrap; }
    .label-cell { font-weight: 600; }
    .extra-tag { display: inline-block; margin-left: 5px; padding: 0 4px; background: #f1f5f9; font-size: 8.5px; font-weight: bold; color: #475569; text-transform: uppercase; }
    table.laba-rugi col.col-label { width: auto; }
    table.laba-rugi col.col-inner { width: 22%; }
    table.laba-rugi col.col-outer { width: 22%; }
  </style>
</head>
<body>
  @php
    $formatCurrency = function (float $amount): string {
      $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
      $suffix = $amount < 0 ? ')' : '';

      return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
    };

    $formatPercent = fn (?float $percent): string => $percent === null
      ? '-'
      : number_format($percent, 2, ',', '.') . '%';

  @endphp

  <h1>Laporan Laba Rugi</h1>
  <div class="meta">
    @if($periodType === 'yearly')
      <p>Mode: Tahunan</p>
      <p>Tahun: {{ $selectedYear }}</p>
    @else
      <p>Mode: Bulanan</p>
      <p>Periode: {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}</p>
    @endif
  </div>

  @if(($statement['inventoryWarnings'] ?? collect())->isNotEmpty())
    <div class="warning">
      <div class="warning-title">Catatan data yang masih kurang</div>
      @foreach($statement['inventoryWarnings'] as $warning)
        <div>{{ $warning }}</div>
      @endforeach
    </div>
  @endif

  <div class="section">
    <div class="section-title">Ringkasan</div>
    <table>
      <thead>
        <tr>
          <th>KPI</th>
          <th class="text-right">Nominal</th>
        </tr>
      </thead>
      <tbody>
        <tr><td>Pendapatan</td><td class="text-right">{{ $formatCurrency((float) $statement['salesRevenue']) }}</td></tr>
        <tr><td>Laba Kotor</td><td class="text-right {{ $statement['grossProfit'] < 0 ? 'negative' : '' }}">{{ $formatCurrency((float) $statement['grossProfit']) }}</td></tr>
        <tr><td>Laba Operasional</td><td class="text-right {{ $statement['operatingProfit'] < 0 ? 'negative' : '' }}">{{ $formatCurrency((float) $statement['operatingProfit']) }}</td></tr>
        <tr class="net"><td>{{ $statement['netProfit'] >= 0 ? 'Laba Bersih' : 'Rugi Bersih' }} ({{ $formatPercent($statement['netProfitPercentage'] ?? null) }})</td><td class="text-right {{ $statement['netProfit'] < 0 ? 'negative' : '' }}">{{ $formatCurrency((float) $statement['netProfit']) }}</td></tr>
      </tbody>
    </table>
  </div>

  @if($periodType === 'yearly' && $yearlyMatrix)
    <div class="section">
      <div class="section-title">Worksheet Tahunan</div>
      <table>
        <thead>
          <tr>
            <th>Keterangan</th>
            @foreach($yearlyMatrix['months'] as $month)
              <th class="text-right">{{ $month['label'] }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($yearlyMatrix['groups'] as $group)
            <tr class="section-row">
              <td>{{ $group['title'] }}</td>
              @foreach($yearlyMatrix['months'] as $month)
                <td></td>
              @endforeach
            </tr>
            @forelse($group['rows'] as $row)
              <tr>
                <td>{{ $row['label'] }}</td>
                @foreach($yearlyMatrix['months'] as $month)
                  @php $amount = (float) ($row['values'][$month['key']] ?? 0); @endphp
                  <td class="text-right {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</td>
                @endforeach
              </tr>
            @empty
              <tr>
                <td>{{ $group['empty_label'] ?? 'Tidak ada data' }}</td>
                @foreach($yearlyMatrix['months'] as $month)
                  <td class="text-right muted">Rp 0</td>
                @endforeach
              </tr>
            @endforelse
            <tr class="subtotal">
              <td>{{ $group['total_label'] }}</td>
              @foreach($yearlyMatrix['months'] as $month)
                @php $amount = (float) ($group['total_values'][$month['key']] ?? 0); @endphp
                <td class="text-right {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</td>
              @endforeach
            </tr>
            @if(!empty($group['summary_label']))
              <tr class="summary">
                <td>{{ $group['summary_label'] }}</td>
                @foreach($yearlyMatrix['months'] as $month)
                  @php $amount = (float) ($group['summary_values'][$month['key']] ?? 0); @endphp
                  <td class="text-right {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</td>
                @endforeach
              </tr>
            @endif
          @endforeach
          <tr class="net">
            <td>Laba Bersih Per Bulan</td>
            @foreach($yearlyMatrix['months'] as $month)
              @php $amount = (float) ($yearlyMatrix['net_profit_values'][$month['key']] ?? 0); @endphp
              <td class="text-right {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</td>
            @endforeach
          </tr>
        </tbody>
      </table>
    </div>
  @else
    @php
      $penjualan = (float) ($profitLoss['totalPenjualan'] ?? 0);
      $laba = (float) ($profitLoss['labaRugi'] ?? 0);
      $marginPct = abs($penjualan) > 0.005 ? ($laba / $penjualan) * 100 : 0;
      $marginLabel = number_format($marginPct, 2, ',', '.') . '%';
    @endphp

    <div class="section">
      <div class="section-title">Laporan Laba Rugi</div>
      <table class="laba-rugi">
        <colgroup>
          <col class="col-label">
          <col class="col-inner">
          <col class="col-outer">
        </colgroup>
        <tbody>
          <tr class="major">
            <td class="label-cell">PENJUALAN</td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['totalPenjualan'] ?? 0)) }}</td>
            <td class="num"></td>
          </tr>
          <tr>
            <td class="indent-cell">Bahan Baku Lama</td>
            <td class="num"></td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['bahanBakuLama'] ?? 0)) }}</td>
          </tr>
          <tr>
            <td class="indent-cell">Bahan Baku Baru</td>
            <td class="num"></td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['bahanBakuBaru'] ?? 0)) }}</td>
          </tr>
          <tr>
            <td class="indent-cell">Sisa Stok</td>
            <td class="num"></td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['sisaStok'] ?? 0)) }}</td>
          </tr>
          <tr class="subtotal">
            <td class="label-cell">Bahan Baku Terpakai</td>
            <td class="num"></td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['bahanBakuTerpakai'] ?? 0)) }}</td>
          </tr>

          <tr class="spacer"><td colspan="3">&nbsp;</td></tr>

          <tr class="section">
            <td class="label-cell">PENGELUARAN</td>
            <td></td>
            <td></td>
          </tr>
          @foreach (($profitLoss['pengeluaranRows'] ?? []) as $row)
            <tr>
              <td class="indent-cell">{{ $row['label'] }}</td>
              <td class="num"></td>
              <td class="num">{{ $formatCurrency((float) $row['amount']) }}</td>
            </tr>
          @endforeach
          <tr class="subtotal">
            <td class="label-cell">TOTAL</td>
            <td class="num"></td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['totalPengeluaran'] ?? 0)) }}</td>
          </tr>

          <tr class="spacer"><td colspan="3">&nbsp;</td></tr>

          <tr class="profit {{ $laba < 0 ? 'negative' : '' }}">
            <td class="label-cell">
              LABA
              <span class="extra-tag">{{ $marginLabel }}</span>
            </td>
            <td class="num"></td>
            <td class="num">{{ $formatCurrency($laba) }}</td>
          </tr>

          <tr class="grand-total">
            <td class="label-cell">TOTAL</td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['totalPenjualan'] ?? 0)) }}</td>
            <td class="num">{{ $formatCurrency((float) ($profitLoss['total'] ?? 0)) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  @endif
</body>
</html>
