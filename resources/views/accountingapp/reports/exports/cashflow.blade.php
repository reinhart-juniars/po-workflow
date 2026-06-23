<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Cashflow</title>
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
    .text-center { text-align: center; }
    .muted { color: #64748b; }
    .subtotal { background: #ecfeff; font-weight: bold; }
    .total { background: #dbeafe; font-weight: bold; }
    .group-row td { background: #f8fafc; font-weight: bold; color: #475569; }
  </style>
</head>
<body>
  @php
    $granularityLabels = ['day' => 'Harian', 'week' => 'Mingguan', 'month' => 'Bulanan'];
    $monthLabels = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $activeGranularityLabel = $granularityLabels[$chartGranularity] ?? 'Harian';
    $summaryIncomeRows = $cashflowSummary['income_rows'] ?? [];
    $summaryExpenseRows = $cashflowSummary['expense_rows'] ?? [];
    $summaryDetailRowCount = max(count($summaryIncomeRows), count($summaryExpenseRows), 1);
    $openingBalanceReference = $dateFrom->copy();
    $openingBalanceLabel = 'Saldo Awal Bulan ' . ($monthLabels[(int) $openingBalanceReference->month] ?? $openingBalanceReference->format('m')) . ' ' . $openingBalanceReference->year;
  @endphp

  <h1>Laporan Cashflow</h1>
  <div class="meta">
    <p>Periode: {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}</p>
    <p>Mode rincian: {{ $activeGranularityLabel }}@if($chartGranularity === 'week' && $activeWeekNumber) | Week {{ $activeWeekNumber }} @endif</p>
  </div>

  <div class="section">
    <div class="section-title">Ringkasan Cashflow</div>
    <table>
      <thead>
        <tr>
          <th colspan="2" class="text-center">Pemasukan</th>
          <th colspan="2" class="text-center">Pengeluaran</th>
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
            $incomeLabel = ($incomeRow['label'] ?? null) === 'Saldo Awal' ? $openingBalanceLabel : ($incomeRow['label'] ?? '');
          @endphp
          <tr>
            <td>{{ $incomeLabel }}</td>
            <td class="text-right">{{ $incomeRow ? number_format((float) $incomeRow['amount'], 0, ',', '.') : '' }}</td>
            <td>{{ $expenseRow['label'] ?? '' }}</td>
            <td class="text-right">{{ $expenseRow ? number_format((float) $expenseRow['amount'], 0, ',', '.') : '' }}</td>
          </tr>
        @endfor
        <tr class="subtotal">
          <td>Total Pemasukan</td>
          <td class="text-right">{{ number_format((float) ($cashflowSummary['total_income'] ?? 0), 0, ',', '.') }}</td>
          <td>Total Pengeluaran</td>
          <td class="text-right">{{ number_format((float) ($cashflowSummary['total_expense'] ?? 0), 0, ',', '.') }}</td>
        </tr>
        <tr class="subtotal">
          <td>Saldo Awal</td>
          <td class="text-right">{{ number_format((float) ($cashflowSummary['opening_balance'] ?? 0), 0, ',', '.') }}</td>
          <td>Saldo Akhir</td>
          <td class="text-right">{{ number_format((float) ($cashflowSummary['ending_balance'] ?? 0), 0, ',', '.') }}</td>
        </tr>
        <tr class="total">
          <td>Grand Total</td>
          <td class="text-right">{{ number_format((float) ($cashflowSummary['grand_total'] ?? 0), 0, ',', '.') }}</td>
          <td>Grand Total</td>
          <td class="text-right">{{ number_format((float) ($cashflowSummary['grand_total'] ?? 0), 0, ',', '.') }}</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="section">
    <div class="section-title">Rincian Periode</div>
    <table>
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
            <tr class="group-row">
              <td colspan="8">{{ $period['group_label'] }}</td>
            </tr>
            @php $currentWeekGroup = $period['group_label']; @endphp
          @endif
          <tr>
            <td>
              <div><strong>{{ $period['label'] }}</strong></div>
              @if (! empty($period['sub_label']))
                <div class="muted">{{ $period['sub_label'] }}</div>
              @endif
            </td>
            <td class="text-right">{{ number_format((float) ($period['opening_balance'] ?? 0), 0, ',', '.') }}</td>
            <td>
              @forelse ($period['income_breakdown'] as $row)
                <div>
                  @if (! empty($row['reference']))
                    <div>{{ $row['label'] }}</div>
                    <div>{{ $row['reference'] }}:</div>
                    <div>Rp {{ number_format((float) $row['amount'], 0, ',', '.') }}</div>
                  @else
                    {{ $row['label'] }}: {{ number_format((float) $row['amount'], 0, ',', '.') }}
                  @endif
                </div>
              @empty
                -
              @endforelse
            </td>
            <td class="text-right">{{ number_format((float) ($period['total_income'] ?? 0), 0, ',', '.') }}</td>
            <td>
              @forelse ($period['expense_breakdown'] as $row)
                <div>{{ $row['label'] }}: {{ number_format((float) $row['amount'], 0, ',', '.') }}</div>
              @empty
                -
              @endforelse
            </td>
            <td class="text-right">{{ number_format((float) ($period['total_expense'] ?? 0), 0, ',', '.') }}</td>
            <td class="text-right">{{ number_format((float) ($period['ending_balance'] ?? 0), 0, ',', '.') }}</td>
            <td class="text-right">{{ number_format((float) ($period['grand_total'] ?? 0), 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="8" class="text-center muted">Tidak ada data cashflow pada periode ini.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</body>
</html>
