<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $requisition->number }}</title>
    @include('pdf._production-style')
    {{-- Dua belas kolom angka tidak muat di A4 tegak: kertasnya mendatar
         (ProductionDocumentService) dan lebar kolom dikunci supaya kolom
         Bahan tidak terjepit menjadi satu kata per baris. --}}
    <style>
        .req { table-layout: fixed; }
        .req thead { display: table-header-group; }
        .req tr { page-break-inside: avoid; }
        .req td { word-wrap: break-word; }
        .req .fill { min-width: 0; width: 90%; }
    </style>
</head>
<body>
@php
    $order = $requisition->productionOrder;
    // Yang dicetak bisa sebagian bahan saja (pilihan di tombol Cetak):
    // bahan yang stoknya masih cukup tidak perlu ikut di kertas belanja.
    $lines = $lines ?? $requisition->lines;
    $totalLines = $requisition->lines->count();
    $qty = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    $rupiah = fn ($v) => $v === null ? '' : number_format((float) $v, 0, ',', '.');
    $totalBeli = $lines->sum(fn ($l) => (float) ($l->purchase_qty ?? 0) * (float) ($l->unit_price ?? 0));
    $sudahDiterima = ! $requisition->isDraft();
    $totalAktual = $lines->sum(fn ($l) => $l->purchaseValue() + $l->damagedValue());
@endphp

<h1>Form Kebutuhan, Stok &amp; Pembelian Barang</h1>
<div class="meta">
    <b>{{ $requisition->number }}</b> · SPK Produksi <b>{{ $order->number }}</b>
    @if ($order->title) — {{ $order->title }} @endif<br>
    Produksi tanggal <b>{{ $order->production_date->format('d/m/Y') }}</b>
    @if ($order->production_time) jam {{ $order->production_time }} @endif
    · Status form: <b>{{ $requisition->statusLabel() }}</b>
    @if ($lines->count() < $totalLines)
        · Dicetak <b>{{ $lines->count() }}</b> dari {{ $totalLines }} bahan
    @endif
</div>

<table class="req">
    <thead>
        <tr>
            <th style="width: 3%">No</th>
            <th style="width: 21%">Bahan</th>
            <th style="width: 6%">Satuan</th>
            <th class="right" style="width: 8%">Kebutuhan</th>
            <th class="right" style="width: 7.5%">Stok Awal</th>
            <th class="right" style="width: 7.5%">Beli</th>
            <th class="right" style="width: 7.5%">Diterima</th>
            <th class="right" style="width: 7.5%">Ditolak</th>
            <th class="right" style="width: 7%">Harga</th>
            <th class="right" style="width: 9%">{{ $sudahDiterima ? 'Nilai' : 'Perkiraan' }}</th>
            <th class="right" style="width: 8%">Pemakaian</th>
            <th class="right" style="width: 8%">Sisa</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $i => $line)
            <tr>
                <td class="center">{{ $loop->iteration }}</td>
                <td>{{ $line->name }}@if ($line->notes)<br><span class="muted">{{ $line->notes }}</span>@endif</td>
                <td>{{ $line->unit }}</td>
                <td class="right">{{ $qty($line->required_qty) }}</td>
                <td class="right">{!! $line->opening_stock_qty === null ? '<span class="fill"></span>' : $qty($line->opening_stock_qty) !!}</td>
                <td class="right">{!! $line->purchase_qty === null ? '<span class="fill"></span>' : $qty($line->purchase_qty) !!}</td>
                <td class="right">{!! $line->received_qty === null ? '<span class="fill"></span>' : $qty($line->received_qty) !!}</td>
                <td class="right">
                    {!! $line->received_qty === null ? '<span class="fill"></span>' : $qty($line->rejectedQty()) !!}
                    @if ($line->rejectedQty() > 0 && $line->rejected_reason)
                        <br><span class="muted" style="white-space: normal">{{ $line->rejected_reason }}{{ $line->rejected_treatment === \App\Models\RequisitionLine::REJECT_PAID ? ' · dibayar' : '' }}</span>
                    @endif
                </td>
                <td class="right">{{ $rupiah($sudahDiterima ? $line->purchasePrice() : $line->unit_price) }}</td>
                <td class="right">{{ $rupiah($sudahDiterima ? $line->purchaseValue() + $line->damagedValue() : (float) ($line->purchase_qty ?? 0) * (float) ($line->unit_price ?? 0)) }}</td>
                <td class="right">{!! $line->actual_used_qty === null ? '<span class="fill"></span>' : $qty($line->actual_used_qty) !!}</td>
                <td class="right">{!! $line->remaining_qty === null ? '<span class="fill"></span>' : $qty($line->remaining_qty) !!}</td>
            </tr>
        @endforeach
        <tr class="total">
            @php
                // Belanja ditagihkan ke accounting lewat Tagihan Pembelian
                // (revisi 7 Okt 2026), bukan dibayar gudang.
                $tagihan = $requisition->purchaseBill;
                $bayar = $tagihan
                    ? ' (ditagihkan '.$tagihan->number.($requisition->supplier_name ? ' · '.$requisition->supplier_name : '').')'
                    : ($requisition->supplier_name ? ' ('.$requisition->supplier_name.')' : '');
            @endphp
            <td colspan="9" class="right">
                {{ $sudahDiterima ? 'Total pembelian' : 'Perkiraan total pembelian' }}{{ $lines->count() < $totalLines ? ' bahan yang dicetak' : '' }}{{ $bayar }}
            </td>
            <td class="right">{{ $rupiah($sudahDiterima ? $totalAktual : $totalBeli) }}</td>
            <td colspan="2"></td>
        </tr>
    </tbody>
</table>

<table class="sign">
    <tr>
        <td>
            <div class="muted">Dibuat / Diisi</div>
            <div style="height: 40px"></div>
            <div class="line">{{ $requisition->preparedBy?->name ?? '' }}<br><span class="muted">{{ $requisition->prepared_at?->format('d/m/Y') }}</span></div>
        </td>
        <td>
            <div class="muted">Disetujui</div>
            <div style="height: 40px"></div>
            <div class="line">{{ $requisition->approvedBy?->name ?? '' }}<br><span class="muted">{{ $requisition->approved_at?->format('d/m/Y') }}</span></div>
        </td>
        <td>
            {{-- Pemeriksa fisik barang (bukan pengguna sistem); akun yang
                 mencatatnya disebut di bawah namanya. --}}
            <div class="muted">Diperiksa (barang dibeli)</div>
            <div style="height: 40px"></div>
            <div class="line">
                {{ $requisition->inspector_name ?: ($requisition->checkedBy?->name ?? '') }}<br>
                <span class="muted">
                    {{ $requisition->checked_at?->format('d/m/Y') }}
                    @if ($requisition->inspector_name && $requisition->checkedBy)
                        · dicatat {{ $requisition->checkedBy->name }}
                    @endif
                </span>
            </div>
        </td>
    </tr>
</table>
</body>
</html>
