<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Penjualan</title>
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
    .section-title { margin: 12px 0 6px; font-size: 12px; font-weight: bold; }
    table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.matrix { width: 100%; border-collapse: collapse; table-layout: auto; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; line-height: 1.35; }
    th { background: #e2e8f0; text-align: center; font-size: 9px; text-transform: uppercase; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .muted { color: #64748b; }
    .date-row td { background: #f8fafc; font-weight: bold; }
    .subtotal td { background: #e2e8f0; font-weight: bold; }
    .share td { background: #ffedd5; font-weight: bold; color: #7c2d12; }
    .grand-total td { background: #dbeafe; font-weight: bold; }
    .segment-lapak td { background: #fff7ed; color: #9a3412; font-weight: bold; }
    .segment-nonlapak td { background: #f8fafc; color: #334155; font-weight: bold; }
    .customer { font-weight: bold; }
    .meta-line { margin-top: 2px; font-size: 9px; color: #64748b; }
    .stack-box { border: 1px solid #e2e8f0; background: #f8fafc; padding: 4px; margin-bottom: 4px; }
    .stack-title { color: #64748b; font-size: 8.5px; text-transform: uppercase; font-weight: bold; margin-bottom: 3px; }
    .stack-row { border-bottom: 1px dashed #cbd5e1; padding: 2px 0; }
    .stack-row:last-child { border-bottom: 0; }
    .stack-label { display: inline-block; width: 68%; }
    .stack-value { display: inline-block; width: 28%; text-align: right; font-weight: bold; }
    .money { white-space: nowrap; }
    .micro { font-size: 8.5px; color: #475569; font-weight: bold; }
    .section-block { margin-top: 14px; page-break-inside: avoid; }
    .section-head { background: #f1f5f9; border: 1px solid #cbd5e1; border-bottom: 0; padding: 6px 8px; }
    .section-title { font-weight: bold; font-size: 12px; color: #0f172a; }
    .section-desc { font-size: 9px; color: #64748b; margin-top: 1px; }
    .section-total td { background: #fef3c7; color: #78350f; font-weight: bold; }
    .summary-block { margin-top: 14px; }
    .summary-block table { width: 100%; border-collapse: collapse; }
    .summary-block tr.total td { background: #dbeafe; font-weight: bold; font-size: 11.5px; }
  </style>
</head>
<body>
  @php
    $activeSegment = collect($segmentCards)->firstWhere('key', $segmentScope);

    $renderItemStack = function (array $map) {
      return collect($map)
        ->filter(fn ($qty) => (int) $qty > 0)
        ->map(fn ($qty, $label) => ['label' => $label, 'value' => number_format((int) $qty)])
        ->values()
        ->all();
    };

    $renderPriceStack = function (array $map) use ($priceColumns) {
      return collect($priceColumns)
        ->map(function ($price) use ($map) {
          $amount = (float) ($map[$price['key']] ?? 0);

          if ($amount <= 0) {
            return null;
          }

          return [
            'label' => $price['label'],
            'value' => 'Rp ' . number_format($amount, 0, ',', '.'),
          ];
        })
        ->filter()
        ->values()
        ->all();
    };

    $segmentRowClass = fn (string $key): string => $key === 'lapak' ? 'lapak' : 'nonlapak';
    $formatMoney = fn (float $amount): string => $amount > 0 ? 'Rp ' . number_format($amount, 0, ',', '.') : '-';
    $totalColumnCount = 2 + count($itemColumns) + 1 + count($priceColumns) + 2;
    $useSectionLayout = $useSectionLayout ?? false;
    $sectionTotalColumnCount = 2 + count($priceColumns) + 3;
    $visibleSections = collect($salesSections ?? [])
      ->filter(fn ($s) => !empty($s['groups']))
      ->values()
      ->all();
  @endphp

  <h1>Laporan Penjualan</h1>
  <div class="meta">
    <p>Periode: {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}</p>
    <p>Segment: {{ $activeSegment['label'] ?? 'Semua' }} | Mode: {{ $viewMode === 'full' ? 'Lengkap' : 'Ringkas' }}</p>
  </div>

  <table class="cards">
    <tr>
      @foreach ($segmentCards as $segmentCard)
        <td class="card">
          <div class="card-label">{{ $segmentCard['label'] }}</div>
          <div class="card-value">Rp {{ number_format((float) ($segmentCard['total_amount'] ?? 0), 0, ',', '.') }}</div>
          <div class="muted">
            {{ number_format((int) ($segmentCard['total_orders'] ?? 0)) }} order |
            {{ number_format((int) ($segmentCard['total_qty'] ?? 0)) }} qty
            @if ($segmentCard['share_percent'] !== null)
              | {{ number_format((float) $segmentCard['share_percent'], 1, ',', '.') }}%
            @endif
          </div>
        </td>
      @endforeach
    </tr>
  </table>

  <table class="cards">
    <tr>
      <td class="card">
        <div class="card-label">Periode</div>
        <div class="card-value">{{ $dateFrom->format('d M') }} - {{ $dateTo->format('d M Y') }}</div>
        <div class="muted">{{ $activeSegment['label'] ?? 'Semua' }}</div>
      </td>
      <td class="card">
        <div class="card-label">Jumlah Order Aktif</div>
        <div class="card-value">{{ number_format((int) ($salesGrandTotals['total_orders'] ?? 0)) }}</div>
      </td>
      <td class="card">
        <div class="card-label">Total Qty Aktif</div>
        <div class="card-value">{{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</div>
      </td>
      <td class="card">
        <div class="card-label">Total Penjualan Aktif</div>
        <div class="card-value">Rp {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}</div>
      </td>
    </tr>
  </table>

  @if ($viewMode === 'full' && $useSectionLayout)
    @forelse ($visibleSections as $section)
      <div class="section-block">
        <div class="section-head">
          <div class="section-title">{{ $section['label'] }}</div>
          <div class="section-desc">{{ $section['description'] }}</div>
        </div>
        <table class="matrix">
          <thead>
            <tr>
              <th style="width: 6%;">No.</th>
              <th>Nama</th>
              @foreach ($priceColumns as $priceColumn)
                <th>{{ $priceColumn['label'] }}</th>
              @endforeach
              <th>Jumlah Pack</th>
              <th>Ongkir</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            @php $rowNumber = 1; @endphp
            @foreach ($section['groups'] as $group)
              <tr class="date-row">
                <td colspan="{{ $sectionTotalColumnCount }}">Tanggal Penjualan: {{ $group['date_label'] }}</td>
              </tr>

              @foreach ($group['rows'] as $row)
                <tr>
                  <td class="text-center">{{ $rowNumber++ }}</td>
                  <td>
                    <div class="customer">{{ $row['customer_label'] }}</div>
                    @foreach (($row['order_meta_lines'] ?? []) as $metaLine)
                      <div class="meta-line">{{ $metaLine }}</div>
                    @endforeach
                  </td>
                  @foreach ($priceColumns as $priceColumn)
                    @php $qty = (int) ($row['price_qty_map'][$priceColumn['key']] ?? 0); @endphp
                    <td class="text-center">{{ $qty > 0 ? number_format($qty) : '-' }}</td>
                  @endforeach
                  <td class="text-right">{{ number_format((int) ($row['total_qty'] ?? 0)) }}</td>
                  <td class="text-right money">{{ $formatMoney((float) ($row['shipping_cost'] ?? 0)) }}</td>
                  <td class="text-right money">Rp {{ number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.') }}</td>
                </tr>
              @endforeach

              <tr class="subtotal">
                <td colspan="2" class="text-center">SUB TOTAL {{ strtoupper($group['date_label']) }}</td>
                @foreach ($priceColumns as $priceColumn)
                  @php $qty = (int) ($group['price_qty_totals'][$priceColumn['key']] ?? 0); @endphp
                  <td class="text-center">{{ $qty > 0 ? number_format($qty) : '-' }}</td>
                @endforeach
                <td class="text-right">{{ number_format((int) ($group['total_qty'] ?? 0)) }}</td>
                <td class="text-right money">{{ $formatMoney((float) ($group['shipping_total'] ?? 0)) }}</td>
                <td class="text-right money">
                  Rp {{ number_format((float) ($group['subtotal_amount'] ?? 0), 0, ',', '.') }}
                  <div class="micro">{{ $group['period_share_percent'] !== null ? number_format((float) $group['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}</div>
                  <div class="micro">{{ $group['section_share_percent'] !== null ? number_format((float) $group['section_share_percent'], 1, ',', '.') . '% dari ' . $section['label'] : '-' }}</div>
                </td>
              </tr>
            @endforeach

            <tr class="section-total">
              <td colspan="2" class="text-center">SUB TOTAL {{ strtoupper($section['label']) }}</td>
              @foreach ($priceColumns as $priceColumn)
                @php $qty = (int) ($section['price_qty_totals'][$priceColumn['key']] ?? 0); @endphp
                <td class="text-center">{{ $qty > 0 ? number_format($qty) : '-' }}</td>
              @endforeach
              <td class="text-right">{{ number_format((int) ($section['total_qty'] ?? 0)) }}</td>
              <td class="text-right money">{{ $formatMoney((float) ($section['shipping_total'] ?? 0)) }}</td>
              <td class="text-right money">
                Rp {{ number_format((float) ($section['subtotal_amount'] ?? 0), 0, ',', '.') }}
                <div class="micro">{{ $section['period_share_percent'] !== null ? number_format((float) $section['period_share_percent'], 1, ',', '.') . '% dari total periode' : '-' }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    @empty
      <div class="muted" style="margin-top: 12px;">Belum ada data penjualan completed pada periode ini.</div>
    @endforelse

    @if (! empty($visibleSections))
      <div class="summary-block">
        <div class="section-title">Ringkasan Total</div>
        <table>
          <thead>
            <tr>
              <th style="text-align: left;">Kategori</th>
              <th class="text-right">Order</th>
              <th class="text-right">Pack</th>
              <th class="text-right">Ongkir</th>
              <th class="text-right">Total</th>
              <th class="text-right">% Periode</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($visibleSections as $section)
              <tr>
                <td>Total {{ $section['label'] }}</td>
                <td class="text-right">{{ number_format((int) $section['rows_count']) }}</td>
                <td class="text-right">{{ number_format((int) $section['total_qty']) }}</td>
                <td class="text-right money">{{ $formatMoney((float) ($section['shipping_total'] ?? 0)) }}</td>
                <td class="text-right money">Rp {{ number_format((float) $section['subtotal_amount'], 0, ',', '.') }}</td>
                <td class="text-right">{{ $section['period_share_percent'] !== null ? number_format((float) $section['period_share_percent'], 1, ',', '.') . '%' : '-' }}</td>
              </tr>
            @endforeach
            <tr class="total">
              <td>Total Penjualan</td>
              <td class="text-right">{{ number_format((int) ($salesGrandTotals['total_orders'] ?? 0)) }}</td>
              <td class="text-right">{{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</td>
              <td class="text-right money">{{ $formatMoney((float) ($salesGrandTotals['shipping_total'] ?? 0)) }}</td>
              <td class="text-right money">Rp {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}</td>
              <td class="text-right">100%</td>
            </tr>
          </tbody>
        </table>
      </div>
    @endif
  @elseif ($viewMode === 'full')
    <div class="section-title">Worksheet Penjualan Lengkap</div>
    <table class="matrix">
      <thead>
        <tr>
          <th>No.</th>
          <th>Nama</th>
          @foreach ($itemColumns as $itemColumn)
            <th>{{ $itemColumn }}</th>
          @endforeach
          <th>Jumlah</th>
          @foreach ($priceColumns as $priceColumn)
            <th>{{ $priceColumn['label'] }}</th>
          @endforeach
          <th>Ongkir</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        @php $rowNumber = 1; @endphp
        @forelse ($salesGroups as $group)
          <tr class="date-row">
            <td colspan="{{ $totalColumnCount }}">Tanggal Penjualan: {{ $group['date_label'] }}</td>
          </tr>

          @foreach ($group['rows'] as $row)
            <tr>
              <td class="text-center">{{ $rowNumber++ }}</td>
              <td>
                <div class="customer">{{ $row['customer_label'] }}</div>
                @foreach (($row['order_meta_lines'] ?? []) as $metaLine)
                  <div class="meta-line">{{ $metaLine }}</div>
                @endforeach
              </td>
              @foreach ($itemColumns as $itemColumn)
                <td class="text-center">{{ (int) ($row['item_qty_map'][$itemColumn] ?? 0) ?: '-' }}</td>
              @endforeach
              <td class="text-center">{{ number_format((int) ($row['total_qty'] ?? 0)) }}</td>
              @foreach ($priceColumns as $priceColumn)
                @php $priceValue = (float) ($row['price_amount_map'][$priceColumn['key']] ?? 0); @endphp
                <td class="text-right money">{{ $formatMoney($priceValue) }}</td>
              @endforeach
              <td class="text-right money">{{ $formatMoney((float) ($row['shipping_cost'] ?? 0)) }}</td>
              <td class="text-right money">Rp {{ number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.') }}</td>
            </tr>
          @endforeach

          @if ($segmentScope === 'all')
            @foreach ($group['segment_breakdown'] ?? [] as $segment)
              <tr class="segment-{{ $segmentRowClass($segment['key']) }}">
                <td colspan="2" class="text-center">SUB TOTAL {{ strtoupper($segment['label']) }}</td>
                @foreach ($itemColumns as $itemColumn)
                  <td class="text-center">{{ (int) ($segment['item_totals'][$itemColumn] ?? 0) ?: '-' }}</td>
                @endforeach
                <td class="text-center">{{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                @foreach ($priceColumns as $priceColumn)
                  <td class="text-right money">{{ $formatMoney((float) ($segment['price_totals'][$priceColumn['key']] ?? 0)) }}</td>
                @endforeach
                <td class="text-right money">{{ $formatMoney((float) ($segment['shipping_total'] ?? 0)) }}</td>
                <td class="text-right money">
                  Rp {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                  <div class="micro">{{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}</div>
                  <div class="micro">{{ $segment['group_share_percent'] !== null ? number_format((float) $segment['group_share_percent'], 1, ',', '.') . '% dari hari ini' : '-' }}</div>
                </td>
              </tr>
            @endforeach
          @endif

          <tr class="subtotal">
            <td colspan="2" class="text-center">SUB TOTAL PENJUALAN</td>
            @foreach ($itemColumns as $itemColumn)
              <td class="text-center">{{ number_format((int) ($group['item_totals'][$itemColumn] ?? 0)) }}</td>
            @endforeach
            <td class="text-center">{{ number_format((int) ($group['total_qty'] ?? 0)) }}</td>
            @foreach ($priceColumns as $priceColumn)
              <td class="text-right money">{{ $formatMoney((float) ($group['price_totals'][$priceColumn['key']] ?? 0)) }}</td>
            @endforeach
            <td class="text-right money">{{ $formatMoney((float) ($group['shipping_total'] ?? 0)) }}</td>
            <td class="text-right money">Rp {{ number_format((float) ($group['subtotal_amount'] ?? 0), 0, ',', '.') }}</td>
          </tr>
          <tr class="share">
            <td colspan="{{ $totalColumnCount - 1 }}" class="text-center">SUB TOTAL PROSENTASE</td>
            <td class="text-right">{{ $group['percentage'] !== null ? number_format((float) $group['percentage'], 0, ',', '.') . '%' : '-' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="{{ $totalColumnCount }}" class="text-center muted">Belum ada data penjualan completed pada periode ini.</td>
          </tr>
        @endforelse

        @if (! empty($salesGroups))
          @if ($segmentScope === 'all')
            @foreach ($salesGrandTotals['segment_breakdown'] ?? [] as $segment)
              <tr class="segment-{{ $segmentRowClass($segment['key']) }}">
                <td colspan="2" class="text-center">TOTAL {{ strtoupper($segment['label']) }}</td>
                @foreach ($itemColumns as $itemColumn)
                  <td class="text-center">{{ (int) ($segment['item_totals'][$itemColumn] ?? 0) ?: '-' }}</td>
                @endforeach
                <td class="text-center">{{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                @foreach ($priceColumns as $priceColumn)
                  <td class="text-right money">{{ $formatMoney((float) ($segment['price_totals'][$priceColumn['key']] ?? 0)) }}</td>
                @endforeach
                <td class="text-right money">{{ $formatMoney((float) ($segment['shipping_total'] ?? 0)) }}</td>
                <td class="text-right money">
                  Rp {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                  <div class="micro">{{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}</div>
                </td>
              </tr>
            @endforeach
          @endif

          <tr class="grand-total">
            <td colspan="2" class="text-center">TOTAL PENJUALAN</td>
            @foreach ($itemColumns as $itemColumn)
              <td class="text-center">{{ number_format((int) ($salesGrandTotals['item_totals'][$itemColumn] ?? 0)) }}</td>
            @endforeach
            <td class="text-center">{{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</td>
            @foreach ($priceColumns as $priceColumn)
              <td class="text-right money">{{ $formatMoney((float) ($salesGrandTotals['price_totals'][$priceColumn['key']] ?? 0)) }}</td>
            @endforeach
            <td class="text-right money">{{ $formatMoney((float) ($salesGrandTotals['shipping_total'] ?? 0)) }}</td>
            <td class="text-right money">Rp {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}</td>
          </tr>
        @endif
      </tbody>
    </table>
  @else
    <div class="section-title">Worksheet Penjualan Ringkas</div>
    <table class="sheet">
      <thead>
        <tr>
          <th style="width: 6%;">No.</th>
          <th style="width: 20%;">Nama</th>
          <th style="width: 38%;">Ringkasan Order</th>
          <th style="width: 9%;">Jumlah</th>
          <th style="width: 12%;">Ongkir</th>
          <th style="width: 15%;">Total</th>
        </tr>
      </thead>
      <tbody>
        @php $rowNumber = 1; @endphp
        @forelse ($salesGroups as $group)
          <tr class="date-row">
            <td colspan="6">Tanggal Penjualan: {{ $group['date_label'] }}</td>
          </tr>

          @foreach ($group['rows'] as $row)
            @php
              $itemStack = $renderItemStack($row['item_qty_map'] ?? []);
              $priceStack = $renderPriceStack($row['price_amount_map'] ?? []);
            @endphp
            <tr>
              <td class="text-center">{{ $rowNumber++ }}</td>
              <td>
                <div class="customer">{{ $row['customer_label'] }}</div>
                @foreach (($row['order_meta_lines'] ?? []) as $metaLine)
                  <div class="meta-line">{{ $metaLine }}</div>
                @endforeach
              </td>
              <td>
                <div class="stack-box">
                  <div class="stack-title">Komposisi</div>
                  @forelse ($itemStack as $item)
                    <div class="stack-row"><span class="stack-label">{{ $item['label'] }}</span><span class="stack-value">{{ $item['value'] }}</span></div>
                  @empty
                    <span class="muted">-</span>
                  @endforelse
                </div>
                <div class="stack-box">
                  <div class="stack-title">Harga</div>
                  @forelse ($priceStack as $price)
                    <div class="stack-row"><span class="stack-label">{{ $price['label'] }}</span><span class="stack-value">{{ $price['value'] }}</span></div>
                  @empty
                    <span class="muted">-</span>
                  @endforelse
                </div>
              </td>
              <td class="text-center">{{ number_format((int) ($row['total_qty'] ?? 0)) }}</td>
              <td class="text-right money">{{ $formatMoney((float) ($row['shipping_cost'] ?? 0)) }}</td>
              <td class="text-right money">Rp {{ number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.') }}</td>
            </tr>
          @endforeach

          @if ($segmentScope === 'all')
            @foreach ($group['segment_breakdown'] ?? [] as $segment)
              @php
                $segmentItemStack = $renderItemStack($segment['item_totals'] ?? []);
                $segmentPriceStack = $renderPriceStack($segment['price_totals'] ?? []);
              @endphp
              <tr class="segment-{{ $segmentRowClass($segment['key']) }}">
                <td colspan="2" class="text-center">SUB TOTAL {{ strtoupper($segment['label']) }}</td>
                <td>
                  <div class="stack-box">
                    <div class="stack-title">Komposisi</div>
                    @forelse ($segmentItemStack as $item)
                      <div class="stack-row"><span class="stack-label">{{ $item['label'] }}</span><span class="stack-value">{{ $item['value'] }}</span></div>
                    @empty
                      -
                    @endforelse
                  </div>
                  <div class="stack-box">
                    <div class="stack-title">Harga</div>
                    @forelse ($segmentPriceStack as $price)
                      <div class="stack-row"><span class="stack-label">{{ $price['label'] }}</span><span class="stack-value">{{ $price['value'] }}</span></div>
                    @empty
                      -
                    @endforelse
                  </div>
                </td>
                <td class="text-center">{{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                <td class="text-right money">{{ $formatMoney((float) ($segment['shipping_total'] ?? 0)) }}</td>
                <td class="text-right money">
                  Rp {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                  <div class="micro">{{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}</div>
                  <div class="micro">{{ $segment['group_share_percent'] !== null ? number_format((float) $segment['group_share_percent'], 1, ',', '.') . '% dari hari ini' : '-' }}</div>
                </td>
              </tr>
            @endforeach
          @endif

          @php
            $groupItemStack = $renderItemStack($group['item_totals'] ?? []);
            $groupPriceStack = $renderPriceStack($group['price_totals'] ?? []);
          @endphp
          <tr class="subtotal">
            <td colspan="2" class="text-center">SUB TOTAL PENJUALAN</td>
            <td>
              <div class="stack-box">
                <div class="stack-title">Komposisi</div>
                @forelse ($groupItemStack as $item)
                  <div class="stack-row"><span class="stack-label">{{ $item['label'] }}</span><span class="stack-value">{{ $item['value'] }}</span></div>
                @empty
                  -
                @endforelse
              </div>
              <div class="stack-box">
                <div class="stack-title">Harga</div>
                @forelse ($groupPriceStack as $price)
                  <div class="stack-row"><span class="stack-label">{{ $price['label'] }}</span><span class="stack-value">{{ $price['value'] }}</span></div>
                @empty
                  -
                @endforelse
              </div>
            </td>
            <td class="text-center">{{ number_format((int) ($group['total_qty'] ?? 0)) }}</td>
            <td class="text-right money">{{ $formatMoney((float) ($group['shipping_total'] ?? 0)) }}</td>
            <td class="text-right money">Rp {{ number_format((float) ($group['subtotal_amount'] ?? 0), 0, ',', '.') }}</td>
          </tr>
          <tr class="share">
            <td colspan="5" class="text-center">SUB TOTAL PROSENTASE</td>
            <td class="text-right">{{ $group['percentage'] !== null ? number_format((float) $group['percentage'], 0, ',', '.') . '%' : '-' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="text-center muted">Belum ada data penjualan completed pada periode ini.</td>
          </tr>
        @endforelse

        @if (! empty($salesGroups))
          @if ($segmentScope === 'all')
            @foreach ($salesGrandTotals['segment_breakdown'] ?? [] as $segment)
              @php
                $segmentItemStack = $renderItemStack($segment['item_totals'] ?? []);
                $segmentPriceStack = $renderPriceStack($segment['price_totals'] ?? []);
              @endphp
              <tr class="segment-{{ $segmentRowClass($segment['key']) }}">
                <td colspan="2" class="text-center">TOTAL {{ strtoupper($segment['label']) }}</td>
                <td>
                  <div class="stack-box">
                    <div class="stack-title">Komposisi</div>
                    @forelse ($segmentItemStack as $item)
                      <div class="stack-row"><span class="stack-label">{{ $item['label'] }}</span><span class="stack-value">{{ $item['value'] }}</span></div>
                    @empty
                      -
                    @endforelse
                  </div>
                  <div class="stack-box">
                    <div class="stack-title">Harga</div>
                    @forelse ($segmentPriceStack as $price)
                      <div class="stack-row"><span class="stack-label">{{ $price['label'] }}</span><span class="stack-value">{{ $price['value'] }}</span></div>
                    @empty
                      -
                    @endforelse
                  </div>
                </td>
                <td class="text-center">{{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                <td class="text-right money">{{ $formatMoney((float) ($segment['shipping_total'] ?? 0)) }}</td>
                <td class="text-right money">
                  Rp {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                  <div class="micro">{{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}</div>
                </td>
              </tr>
            @endforeach
          @endif

          @php
            $grandItemStack = $renderItemStack($salesGrandTotals['item_totals'] ?? []);
            $grandPriceStack = $renderPriceStack($salesGrandTotals['price_totals'] ?? []);
          @endphp
          <tr class="grand-total">
            <td colspan="2" class="text-center">TOTAL PENJUALAN</td>
            <td>
              <div class="stack-box">
                <div class="stack-title">Komposisi</div>
                @forelse ($grandItemStack as $item)
                  <div class="stack-row"><span class="stack-label">{{ $item['label'] }}</span><span class="stack-value">{{ $item['value'] }}</span></div>
                @empty
                  -
                @endforelse
              </div>
              <div class="stack-box">
                <div class="stack-title">Harga</div>
                @forelse ($grandPriceStack as $price)
                  <div class="stack-row"><span class="stack-label">{{ $price['label'] }}</span><span class="stack-value">{{ $price['value'] }}</span></div>
                @empty
                  -
                @endforelse
              </div>
            </td>
            <td class="text-center">{{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</td>
            <td class="text-right money">{{ $formatMoney((float) ($salesGrandTotals['shipping_total'] ?? 0)) }}</td>
            <td class="text-right money">Rp {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}</td>
          </tr>
        @endif
      </tbody>
    </table>
  @endif
</body>
</html>
