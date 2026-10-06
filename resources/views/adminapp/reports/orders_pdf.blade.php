<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Purchase Order</title>
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
    td.num, th.num { text-align: right; white-space: nowrap; }
    .mono { font-family: Consolas, Monaco, monospace; font-size: 11px; }
    .muted { color: #64748b; }
  </style>
</head>
<body>
  @php
    use App\Support\UiLabel;
    $draftCount = $orders->where('status', 'draft')->count();
    $inProgressCount = $orders->where('status', 'in_progress')->count();
    $completedCount = $orders->where('status', 'completed')->count();
    $statusLabel = UiLabel::purchaseOrderStatusOptions();
  @endphp

  <h1>Laporan Purchase Order</h1>
  <div class="meta">
    Periode:
    <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}</strong> s/d
    <strong>{{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</strong>
    | Status: <strong>{{ $status ? ($statusLabel[$status] ?? $status) : 'Semua' }}</strong>
    | Dicetak: {{ now()->format('d-m-Y H:i') }}
  </div>

  <div class="summary">
    <span class="pill">Total: {{ $orders->count() }}</span>
    <span class="pill">Draft: {{ $draftCount }}</span>
    <span class="pill">{{ UiLabel::purchaseOrderStatus('in_progress') }}: {{ $inProgressCount }}</span>
    <span class="pill">{{ UiLabel::purchaseOrderStatus('completed') }}: {{ $completedCount }}</span>
  </div>

  <table>
    <thead>
      <tr>
        <th>PO Number</th>
        <th>Customer</th>
        <th>Menu</th>
        <th>Status</th>
        <th class="num">Total</th>
        <th>Created At</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($orders as $o)
        @php
          $menuSummary = $o->items
            ->map(function ($item) {
              $name = $item->product->name ?? 'Produk';
              $text = $name . ' x' . (int) $item->qty;
              if (!empty($item->notes)) {
                $text .= ' (' . $item->notes . ')';
              }
              return $text;
            })
            ->implode(', ');
        @endphp
        <tr>
          <td class="mono">{{ $o->po_number }}</td>
          <td>{{ $o->customer->name ?? '-' }}</td>
          <td>{{ $menuSummary ?: '-' }}</td>
          <td>{{ UiLabel::purchaseOrderStatus($o->status) }}</td>
          <td class="num">Rp {{ number_format((float) ($o->total_amount ?? 0), 0, ',', '.') }}</td>
          <td>{{ $o->created_at?->format('d M Y H:i') }}</td>
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
