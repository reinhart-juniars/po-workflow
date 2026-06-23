<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Waste</title>
  <style>
    @page { margin: 16px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #0f172a; }
    h1 { margin: 0 0 4px; font-size: 18px; }
    p { margin: 0; }
    .meta { margin-bottom: 12px; color: #475569; line-height: 1.45; }
    .cards { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 10px; }
    .card { border: 1px solid #cbd5e1; padding: 8px; background: #f8fafc; }
    .card-label { color: #64748b; font-size: 9px; text-transform: uppercase; font-weight: bold; }
    .card-value { margin-top: 3px; font-size: 13px; font-weight: bold; }
    table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; line-height: 1.35; }
    th { background: #e2e8f0; text-align: center; font-size: 9px; text-transform: uppercase; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .muted { color: #64748b; }
    .grand-total td { background: #fee2e2; font-weight: bold; }
    .money { white-space: nowrap; }
  </style>
</head>
<body>
  <h1>Laporan Waste</h1>
  <div class="meta">
    <p>Periode: {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}</p>
    <p>Barang carry forward (retur hari sebelumnya) yang tidak layak jual dan dibuang. Berdasarkan tanggal penjualan (Sales Actual submitted).</p>
  </div>

  <table class="cards">
    <tr>
      <td class="card">
        <div class="card-label">Total Qty Waste</div>
        <div class="card-value">{{ number_format((float) $totalWasteQty, 2, ',', '.') }}</div>
      </td>
      <td class="card">
        <div class="card-label">Nilai Cost (BB + OHC)</div>
        <div class="card-value">Rp {{ number_format((float) $totalWasteCost, 0, ',', '.') }}</div>
      </td>
      <td class="card">
        <div class="card-label">Nilai Harga Jual</div>
        <div class="card-value">Rp {{ number_format((float) $totalWasteSelling, 0, ',', '.') }}</div>
      </td>
    </tr>
  </table>

  <table class="sheet">
    <thead>
      <tr>
        <th style="width: 5%;">No.</th>
        <th style="width: 11%;">Tanggal</th>
        <th style="width: 17%;">Customer</th>
        <th style="width: 20%;">Item</th>
        <th style="width: 9%;">Qty Waste</th>
        <th style="width: 11%;">Cost / Unit</th>
        <th style="width: 13%;">Nilai Cost</th>
        <th style="width: 14%;">Nilai Harga Jual</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($wasteItems as $item)
        @php
          $costPerUnit = (float) $item->raw_material_cost + (float) $item->overhead_cost;
          $wasteCost = (float) $item->qty_waste * $costPerUnit;
          $wasteSelling = (float) $item->qty_waste * (float) $item->unit_price;
        @endphp
        <tr>
          <td class="text-center">{{ $loop->iteration }}</td>
          <td>{{ $item->salesActual?->sales_date?->format('d M Y') }}</td>
          <td>{{ $item->salesActual?->customer?->name ?? '-' }}</td>
          <td>
            {{ $item->item_name }}
            <div class="muted">{{ $item->unit ?: '-' }}</div>
          </td>
          <td class="text-right">{{ number_format((float) $item->qty_waste, 2, ',', '.') }}</td>
          <td class="text-right money">Rp {{ number_format($costPerUnit, 0, ',', '.') }}</td>
          <td class="text-right money">Rp {{ number_format($wasteCost, 0, ',', '.') }}</td>
          <td class="text-right money">Rp {{ number_format($wasteSelling, 0, ',', '.') }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="8" class="text-center muted">Tidak ada barang waste pada periode ini.</td>
        </tr>
      @endforelse

      @if (! $wasteItems->isEmpty())
        <tr class="grand-total">
          <td colspan="4" class="text-center">TOTAL WASTE</td>
          <td class="text-right">{{ number_format((float) $totalWasteQty, 2, ',', '.') }}</td>
          <td></td>
          <td class="text-right money">Rp {{ number_format((float) $totalWasteCost, 0, ',', '.') }}</td>
          <td class="text-right money">Rp {{ number_format((float) $totalWasteSelling, 0, ',', '.') }}</td>
        </tr>
      @endif
    </tbody>
  </table>
</body>
</html>
