<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Pemakaian Bahan</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
    h1 { margin: 0 0 4px; font-size: 20px; }
    p { margin: 0; }
    .meta { margin-bottom: 16px; color: #475569; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border: 1px solid #cbd5e1; padding: 8px 10px; }
    th { background: #e2e8f0; text-align: left; }
    .text-right { text-align: right; }
  </style>
</head>
<body>
  <h1>Laporan Pemakaian Bahan</h1>
  <div class="meta">
    <p>Item: {{ $selectedItem?->name ?? '-' }} @if($selectedItem?->unit) ({{ $selectedItem->unit }}) @endif</p>
    <p>Periode: {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}</p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Komponen</th>
        <th class="text-right">Nilai</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Bahan Baku Lama</td>
        <td class="text-right">{{ number_format((float) ($summary['opening'] ?? 0), 2, ',', '.') }}</td>
      </tr>
      <tr>
        <td>Pembelian</td>
        <td class="text-right">{{ number_format((float) ($summary['purchases'] ?? 0), 2, ',', '.') }}</td>
      </tr>
      <tr>
        <td>Sisa Stok</td>
        <td class="text-right">{{ number_format((float) ($summary['ending'] ?? 0), 2, ',', '.') }}</td>
      </tr>
      <tr>
        <td><strong>Pemakaian</strong></td>
        <td class="text-right"><strong>{{ number_format((float) ($summary['usage'] ?? 0), 2, ',', '.') }}</strong></td>
      </tr>
    </tbody>
  </table>

  <table>
    <thead>
      <tr>
        <th>Tanggal</th>
        <th>Jenis</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Unit Cost</th>
        <th class="text-right">Nilai</th>
        <th>Keterangan</th>
      </tr>
    </thead>
    <tbody>
      @foreach(($detailRows ?? collect()) as $row)
        @if(($row['kind'] ?? null) === 'entry')
          <tr>
            <td>{{ $row['date_text'] ?? '-' }}</td>
            <td>{{ $row['type'] ?? '-' }}</td>
            <td class="text-right">
              {{ number_format((float) ($row['qty'] ?? 0), 2, ',', '.') }}
              @if($selectedItem?->unit) {{ $selectedItem->unit }} @endif
            </td>
            <td class="text-right">{{ number_format((float) ($row['unit_cost'] ?? 0), 2, ',', '.') }}</td>
            <td class="text-right">{{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}</td>
            <td>{{ $row['notes'] ?? '' }}</td>
          </tr>
        @else
          <tr>
            <td colspan="4"><strong>{{ $row['label'] ?? '-' }}</strong></td>
            <td class="text-right"><strong>{{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}</strong></td>
            <td>{{ $row['notes'] ?? '' }}</td>
          </tr>
        @endif
      @endforeach
    </tbody>
  </table>
</body>
</html>
