<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Best Seller per Customer</title>
  <style>
    @page { margin: 20px; }
    body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #0f172a; margin: 0; }
    h1 { font-size: 18px; margin: 0; }
    .meta { margin-top: 6px; font-size: 11px; color: #475569; }
    .meta div { margin-bottom: 2px; }
    .meta strong { color: #0f172a; }
    .summary { margin: 12px 0 14px; }
    .pill { display: inline-block; margin: 0 6px 6px 0; padding: 4px 10px; border-radius: 999px; border: 1px solid #dbe4ff; background: #f8faff; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th, td { border: 1px solid #d1d5db; padding: 7px 8px; }
    th { background: #f8fafc; text-align: left; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #475569; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    .total-row { background: #f8fafc; font-weight: 600; }
    .muted { color: #64748b; }
  </style>
</head>
<body>
  @php
    $grandQty = $results->sum('total_qty');
    $grandFreq = $results->sum('total_orders');
    $grandTotal = $results->sum('total_amount');
  @endphp

  <h1>Laporan Menu Best Seller per Customer</h1>
  <div class="meta">
    <div>Customer: <strong>{{ $customer?->name ?? '-' }}</strong></div>
    <div>Periode:
      <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}</strong>
      s/d
      <strong>{{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</strong>
    </div>
    <div>Top: <strong>{{ $limit }}</strong> produk</div>
    <div>Dicetak pada: {{ now()->format('d-m-Y H:i') }}</div>
  </div>

  <div class="summary">
    <span class="pill">Jumlah Item: {{ $results->count() }}</span>
    <span class="pill">Total Qty: {{ number_format($grandQty) }}</span>
    <span class="pill">Frekuensi: {{ number_format($grandFreq) }}</span>
    <span class="pill">Omzet: Rp {{ number_format($grandTotal, 0, ',', '.') }}</span>
  </div>

  @if($results->isEmpty())
    <p class="muted"><em>Tidak ada data pada periode ini.</em></p>
  @else
    <table>
      <thead>
      <tr>
        <th style="width:40px">#</th>
        <th>Produk</th>
        <th class="num" style="width:80px">Total Qty</th>
        <th class="num" style="width:110px">Frekuensi Order</th>
        <th class="num" style="width:120px">Total Omzet</th>
      </tr>
      </thead>
      <tbody>
      @foreach($results as $index => $row)
        <tr>
          <td>{{ $index + 1 }}</td>
          <td>{{ $row->product_name }}</td>
          <td class="num">{{ $row->total_qty }}</td>
          <td class="num">{{ $row->total_orders }}</td>
          <td class="num">
            Rp {{ number_format($row->total_amount, 0, ',', '.') }}
          </td>
        </tr>
      @endforeach

      <tr class="total-row">
        <td colspan="2">TOTAL</td>
        <td class="num">{{ $grandQty }}</td>
        <td class="num">{{ $grandFreq }}</td>
        <td class="num">
          Rp {{ number_format($grandTotal, 0, ',', '.') }}
        </td>
      </tr>
      </tbody>
    </table>
  @endif
</body>
</html>
