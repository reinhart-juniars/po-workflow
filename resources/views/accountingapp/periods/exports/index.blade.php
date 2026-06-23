<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{{ ($viewMode ?? 'outstanding') === 'paid' ? 'Piutang Sudah Dilunasi' : 'Monitoring Piutang' }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
    h1 { margin: 0 0 4px; font-size: 20px; }
    p { margin: 0; }
    .meta { margin-bottom: 16px; color: #475569; }
    .summary { margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
    th { background: #e2e8f0; text-align: left; }
    .text-right { text-align: right; }
    .muted { color: #64748b; }
    .overdue { color: #b91c1c; font-weight: bold; }
    .due-today { color: #d97706; font-weight: bold; }
    .legacy { color: #475569; font-weight: bold; }
    tfoot td { background: #f1f5f9; font-weight: bold; }
  </style>
</head>
<body>
  @php
    $viewMode = $viewMode ?? 'outstanding';
    $isPaid = $viewMode === 'paid';

    $urgencyLabels = [
      'overdue' => 'Lewat Jatuh Tempo',
      'today' => 'Jatuh Tempo Hari Ini',
      'next_7_days' => '7 Hari Lagi',
      'no_due_date' => 'Tanpa Jatuh Tempo',
    ];

    $dueStatus = function ($days): array {
      if ($days === null) {
        return ['label' => '-', 'class' => 'muted'];
      }

      if ($days < 0) {
        return ['label' => 'Telat ' . abs((int) $days) . ' hari', 'class' => 'overdue'];
      }

      if ($days === 0) {
        return ['label' => 'Jatuh tempo hari ini', 'class' => 'due-today'];
      }

      return ['label' => (int) $days . ' hari lagi', 'class' => ''];
    };
  @endphp

  @if ($isPaid)
    <h1>Piutang Sudah Dilunasi</h1>
    <div class="meta">
      <p>Tanggal Pembayaran: {{ $dateFrom !== '' ? \Carbon\Carbon::parse($dateFrom)->format('d M Y') : 'Awal' }} - {{ $dateTo !== '' ? \Carbon\Carbon::parse($dateTo)->format('d M Y') : 'Akhir' }}</p>
      @if (! empty($customerSearch ?? ''))
        <p>Customer: {{ $customerSearch }}</p>
      @endif
      <p>Export: {{ $exportedAt->format('d M Y H:i') }}</p>
    </div>

    <table class="summary">
      <thead>
        <tr>
          <th>Jumlah PO Lunas</th>
          <th class="text-right">Total Nominal Pelunasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>{{ number_format($countPaid ?? 0, 0, ',', '.') }}</td>
          <td class="text-right">Rp {{ number_format((float) ($totalPaid ?? 0), 0, ',', '.') }}</td>
        </tr>
      </tbody>
    </table>

    <table>
      <thead>
        <tr>
          <th>Tgl Pembayaran</th>
          <th>No. PO</th>
          <th>Customer</th>
          <th>Pesanan</th>
          <th>Akun Kas</th>
          <th class="text-right">Nominal</th>
        </tr>
      </thead>
      <tbody>
        @forelse($periods as $po)
          <tr>
            <td>{{ optional($po->cash_received_at)->format('d-m-Y H:i') ?? '-' }}</td>
            <td>{{ $po->po_number }}</td>
            <td>{{ $po->customer->name ?? '-' }}</td>
            <td>{{ $po->items_summary ?: '-' }}</td>
            <td>
              @if ($po->cashAccount)
                {{ $po->cashAccount->name }} ({{ $po->cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
              @else - @endif
            </td>
            <td class="text-right">{{ number_format((float) $po->total_amount, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="muted">Belum ada piutang yang dilunasi.</td></tr>
        @endforelse
      </tbody>
      @if (($countPaid ?? 0) > 0)
        <tfoot>
          <tr>
            <td colspan="5" class="text-right">Total</td>
            <td class="text-right">Rp {{ number_format((float) ($totalPaid ?? 0), 0, ',', '.') }}</td>
          </tr>
        </tfoot>
      @endif
    </table>

  @else
    <h1>Monitoring Piutang</h1>
    <div class="meta">
      <p>Jatuh Tempo: {{ $dateFrom !== '' ? \Carbon\Carbon::parse($dateFrom)->format('d M Y') : 'Awal' }} - {{ $dateTo !== '' ? \Carbon\Carbon::parse($dateTo)->format('d M Y') : 'Akhir' }}</p>
      <p>Status: {{ $urgencyLabels[$urgency] ?? 'Semua' }}</p>
      <p>Export: {{ $exportedAt->format('d M Y H:i') }}</p>
    </div>

    <table class="summary">
      <thead>
        <tr>
          <th>Lewat Jatuh Tempo</th>
          <th>Jatuh Tempo Hari Ini</th>
          <th>7 Hari Ke Depan</th>
          <th>Tanpa Jatuh Tempo</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="text-right">{{ number_format($overdueReceivablesCount ?? 0, 0, ',', '.') }}</td>
          <td class="text-right">{{ number_format($dueTodayReceivablesCount ?? 0, 0, ',', '.') }}</td>
          <td class="text-right">{{ number_format($dueSoonReceivablesCount ?? 0, 0, ',', '.') }}</td>
          <td class="text-right">{{ number_format($openReceivablesWithoutDueDateCount ?? 0, 0, ',', '.') }}</td>
        </tr>
      </tbody>
    </table>

    <table>
      <thead>
        <tr>
          <th>Tanggal PO</th>
          <th>No. PO</th>
          <th>Customer</th>
          <th>Pesanan</th>
          <th class="text-right">Total Nilai</th>
          <th>Jatuh Tempo</th>
          <th>Sisa Hari</th>
          <th>Status Data</th>
        </tr>
      </thead>
      <tbody>
        @forelse($periods as $po)
          @php $status = $dueStatus($po->days_remaining); @endphp
          <tr>
            <td>{{ optional($po->created_at)->format('d-m-Y') ?? '-' }}</td>
            <td>{{ $po->po_number }}</td>
            <td>{{ $po->customer->name ?? '-' }}</td>
            <td>{{ $po->items_summary ?: '-' }}</td>
            <td class="text-right">{{ number_format((float) $po->total_amount, 0, ',', '.') }}</td>
            <td>{{ $po->due_date ? $po->due_date->format('d-m-Y') : '-' }}</td>
            <td class="{{ $status['class'] }}">{{ $status['label'] }}</td>
            <td class="{{ ($po->is_legacy_receivable ?? false) ? 'legacy' : '' }}">
              {{ ($po->is_legacy_receivable ?? false) ? 'Legacy' : 'Normal' }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="8" class="muted">Belum ada piutang outstanding.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  @endif
</body>
</html>
