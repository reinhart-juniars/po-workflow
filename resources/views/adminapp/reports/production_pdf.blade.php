<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Produksi (SPK)</title>
  <style>
    @page { margin: 18px; }
    body { font-family: "Segoe UI", Arial, sans-serif; font-size: 11px; color: #0f172a; margin: 0; }
    h1 { font-size: 18px; margin: 0; }
    .meta { margin-top: 6px; font-size: 11px; color: #475569; }
    .meta strong { color: #0f172a; }
    .summary { margin: 12px 0 14px; }
    .pill { display: inline-block; margin: 0 6px 6px 0; padding: 4px 10px; border-radius: 999px; border: 1px solid #dbe4ff; background: #f8faff; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
    th, td { border: 1px solid #d1d5db; padding: 7px 8px; vertical-align: top; word-break: break-word; }
    th { background: #f8fafc; text-align: left; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #475569; }
    .mono { font-family: Consolas, Monaco, monospace; font-size: 11px; }
    .muted { color: #64748b; }
    .stack { display: block; }
    .stack div { margin-bottom: 4px; }
    .stack div:last-child { margin-bottom: 0; }
    .price-total { margin-top: 6px; padding-top: 4px; border-top: 1px solid #cbd5e1; font-weight: 700; text-align: right; }
  </style>
</head>
<body>
  @php
    use App\Support\UiLabel;
    $inProcessCount = $spks->where('status', 'in_process')->count();
    $completedCount = $spks->where('status', 'completed')->count();
    $statusLabel = UiLabel::spkStatusOptions();
  @endphp

  <h1>Laporan Produksi (SPK)</h1>
  <div class="meta">
    Periode:
    <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}</strong> s/d
    <strong>{{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</strong>
    | Status: <strong>{{ $status ? ($statusLabel[$status] ?? $status) : 'Semua' }}</strong>
    | Dicetak: {{ now()->format('d-m-Y H:i') }}
  </div>

  <div class="summary">
    <span class="pill">Total: {{ $spks->count() }}</span>
    <span class="pill">{{ UiLabel::spkStatus('in_process') }}: {{ $inProcessCount }}</span>
    <span class="pill">{{ UiLabel::spkStatus('completed') }}: {{ $completedCount }}</span>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width: 10%;">SPK Code</th>
        <th style="width: 12%;">Scheduled At</th>
        <th style="width: 16%;">Customer</th>
        <th style="width: 24%;">Menu &amp; Jumlah</th>
        <th style="width: 14%;">Harga</th>
        <th style="width: 16%;">Keterangan</th>
        <th style="width: 8%;">Status</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($spks as $index => $s)
        @php $reportRow = $reportRows[$index]; @endphp
        <tr>
          <td class="mono">{{ $s->spk_code }}</td>
          <td>{{ $s->scheduled_at ? \Carbon\Carbon::parse($s->scheduled_at)->format('d M Y H:i') : '-' }}</td>
          <td>
            <div class="stack">
              @forelse ($reportRow['customer_lines'] as $customer)
                <div>{{ $customer }}</div>
              @empty
                <div class="muted">-</div>
              @endforelse
            </div>
          </td>
          <td>
            <div class="stack">
              @forelse ($reportRow['item_lines'] as $line)
                <div>{{ $line['product_name'] }} x{{ number_format($line['qty'], 0, ',', '.') }}</div>
              @empty
                <div class="muted">-</div>
              @endforelse
            </div>
          </td>
          <td>
            <div class="stack">
              @forelse ($reportRow['item_lines'] as $line)
                <div>{{ $line['price_text'] }}</div>
              @empty
                <div class="muted">-</div>
              @endforelse
            </div>
            @if($reportRow['item_lines']->isNotEmpty())
              <div class="price-total">Total Rp {{ number_format($reportRow['total_amount'], 0, ',', '.') }}</div>
            @endif
          </td>
          <td>
            <div class="stack">
              @forelse ($reportRow['notes_lines'] as $note)
                <div>{{ $note }}</div>
              @empty
                <div class="muted">-</div>
              @endforelse
            </div>
          </td>
          <td>{{ UiLabel::spkStatus($s->status) }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="muted" style="text-align:center;">Tidak ada data.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
