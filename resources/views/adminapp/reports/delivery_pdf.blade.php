<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Delivery</title>
  <style>
    @page { margin: 20px; }
    body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #0f172a; margin: 0; }
    h1 { font-size: 18px; margin: 0; }
    .meta { margin-top: 6px; font-size: 11px; color: #475569; }
    .meta strong { color: #0f172a; }
    .summary { margin: 12px 0 14px; }
    .pill { display: inline-block; margin: 0 6px 6px 0; padding: 4px 10px; border-radius: 999px; border: 1px solid #dbe4ff; background: #f8faff; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th, td { border: 1px solid #d1d5db; padding: 7px 8px; }
    th { background: #f8fafc; text-align: left; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #475569; }
    .mono { font-family: Consolas, Monaco, monospace; font-size: 11px; }
    .muted { color: #64748b; }
  </style>
</head>
<body>
  @php
    use App\Support\UiLabel;
    $readyCount = $dos->where('status', 'ready')->count();
    $onDeliveryCount = $dos->where('status', 'on_delivery')->count();
    $deliveredCount = $dos->where('status', 'delivered')->count();
    $statusLabel = UiLabel::deliveryStatusOptions();
  @endphp

  <h1>Laporan Delivery</h1>
  <div class="meta">
    Periode:
    <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}</strong> s/d
    <strong>{{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</strong>
    | Status: <strong>{{ $status ? ($statusLabel[$status] ?? $status) : 'Semua' }}</strong>
    | Dicetak: {{ now()->format('d-m-Y H:i') }}
  </div>

  <div class="summary">
    <span class="pill">Total: {{ $dos->count() }}</span>
    <span class="pill">{{ UiLabel::deliveryStatus('ready') }}: {{ $readyCount }}</span>
    <span class="pill">{{ UiLabel::deliveryStatus('on_delivery') }}: {{ $onDeliveryCount }}</span>
    <span class="pill">{{ UiLabel::deliveryStatus('delivered') }}: {{ $deliveredCount }}</span>
  </div>

  <table>
    <thead>
      <tr>
        <th>DO Code</th>
        <th>Area</th>
        <th>Driver</th>
        <th>Recipient</th>
        <th>Status</th>
        <th>Scheduled At</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($dos as $d)
        <tr>
          <td class="mono">{{ $d->do_code }}</td>
          <td>{{ $d->area->name ?? '-' }}</td>
          <td>{{ $d->driver->name ?? '-' }}</td>
          <td>{{ $d->recipient_name ?? '-' }}</td>
          <td>{{ UiLabel::deliveryStatus($d->status) }}</td>
          <td>{{ $d->scheduled_at?->format('d M Y H:i') }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="6" class="muted" style="text-align:center;">Tidak ada data.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
