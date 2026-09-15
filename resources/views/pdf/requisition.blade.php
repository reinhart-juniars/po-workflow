<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $requisition->number }}</title>
    @include('pdf._production-style')
</head>
<body>
@php
    $order = $requisition->productionOrder;
    $qty = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $rupiah = fn ($v) => $v === null ? '' : number_format((float) $v, 0, ',', '.');
    $totalBeli = $requisition->lines->sum(fn ($l) => (float) ($l->purchase_qty ?? 0) * (float) ($l->unit_price ?? 0));
    $sudahDiterima = ! $requisition->isDraft();
    $totalAktual = $requisition->lines->sum(fn ($l) => $l->purchaseValue() + $l->damagedValue());
@endphp

<h1>Form Kebutuhan, Stok &amp; Pembelian Barang</h1>
<div class="meta">
    <b>{{ $requisition->number }}</b> · SPK Produksi <b>{{ $order->number }}</b>
    @if ($order->title) — {{ $order->title }} @endif<br>
    Produksi tanggal <b>{{ $order->production_date->format('d/m/Y') }}</b>
    @if ($order->production_time) jam {{ $order->production_time }} @endif
    · Status form: <b>{{ $requisition->statusLabel() }}</b>
</div>

<table>
    <thead>
        <tr>
            <th style="width: 24px">No</th>
            <th>Bahan</th>
            <th style="width: 48px">Satuan</th>
            <th class="right" style="width: 64px">Kebutuhan</th>
            <th class="right" style="width: 64px">Stok Awal</th>
            <th class="right" style="width: 64px">Beli</th>
            <th class="right" style="width: 56px">Diterima</th>
            <th class="right" style="width: 56px">Ditolak</th>
            <th class="right" style="width: 64px">Harga</th>
            <th class="right" style="width: 72px">{{ $sudahDiterima ? 'Nilai' : 'Perkiraan' }}</th>
            <th class="right" style="width: 64px">Pemakaian</th>
            <th class="right" style="width: 56px">Sisa</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($requisition->lines as $i => $line)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td>{{ $line->name }}@if ($line->notes)<br><span class="muted">{{ $line->notes }}</span>@endif</td>
                <td>{{ $line->unit }}</td>
                <td class="right">{{ $qty($line->required_qty) }}</td>
                <td class="right">{!! $line->opening_stock_qty === null ? '<span class="fill"></span>' : $qty($line->opening_stock_qty) !!}</td>
                <td class="right">{!! $line->purchase_qty === null ? '<span class="fill"></span>' : $qty($line->purchase_qty) !!}</td>
                <td class="right">{!! $line->received_qty === null ? '<span class="fill"></span>' : $qty($line->received_qty) !!}</td>
                <td class="right">{!! $line->received_qty === null ? '<span class="fill"></span>' : $qty($line->rejectedQty()) !!}@if ($line->rejectedQty() > 0 && $line->rejected_reason)<br><span class="muted">{{ $line->rejected_reason }}@if ($line->rejected_treatment === \App\Models\RequisitionLine::REJECT_PAID) · dibayar@endif</span>@endif</td>
                <td class="right">{{ $rupiah($sudahDiterima ? $line->purchasePrice() : $line->unit_price) }}</td>
                <td class="right">{{ $rupiah($sudahDiterima ? $line->purchaseValue() + $line->damagedValue() : (float) ($line->purchase_qty ?? 0) * (float) ($line->unit_price ?? 0)) }}</td>
                <td class="right">{!! $line->actual_used_qty === null ? '<span class="fill"></span>' : $qty($line->actual_used_qty) !!}</td>
                <td class="right">{!! $line->remaining_qty === null ? '<span class="fill"></span>' : $qty($line->remaining_qty) !!}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="9" class="right">{{ $sudahDiterima ? 'Total pembelian' : 'Perkiraan total pembelian' }}@if ($requisition->paymentTypeLabel()) ({{ $requisition->paymentTypeLabel() }}@if ($requisition->supplier_name) · {{ $requisition->supplier_name }}@endif)@endif</td>
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
            <div class="muted">Diperiksa (barang dibeli)</div>
            <div style="height: 40px"></div>
            <div class="line">{{ $requisition->checkedBy?->name ?? '' }}<br><span class="muted">{{ $requisition->checked_at?->format('d/m/Y') }}</span></div>
        </td>
    </tr>
</table>
</body>
</html>
