<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SPK PRA {{ $sheet['order']->number }}</title>
    @include('pdf._production-style')
    <style>
        .pra td { border: 0; padding: 2px 5px; }
        .pra .menu td { padding-top: 8px; border-top: 1px solid #94a3b8; font-weight: bold; }
        .pra .component td:nth-child(2) { font-weight: bold; }
        .pra tr { page-break-inside: avoid; }
    </style>
</head>
<body>
@php
    $order = $sheet['order'];
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
@endphp

<h1>{{ $order->title ?: 'SPK PRA' }}</h1>
<div class="meta">
    SPK PRA untuk dikerjakan tanggal <b>{{ $order->workDate()?->format('d/m/Y') }}</b>
    @if ($order->production_time) jam <b>{{ $order->production_time }}</b> @endif
    · {{ $order->number }}
</div>

<table class="pra">
    <thead>
        <tr>
            <th style="width: 24px">No</th>
            <th>Order</th>
            <th class="right" style="width: 60px">Qty</th>
            <th style="width: 50px">Satuan</th>
            <th style="width: 110px">Pelaksana</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sheet['menus'] as $menu)
            <tr class="menu">
                <td class="center">{{ $menu['no'] }}</td>
                <td>{{ $menu['title'] }}</td>
                <td class="right">{{ $menu['qty'] === null ? '' : $qty($menu['qty']) }}</td>
                <td>{{ $menu['unit'] }}</td>
                <td>{{ $menu['note'] }}</td>
            </tr>
            @foreach ($menu['components'] as $component)
                @if ($component['name'] !== null)
                    <tr class="component">
                        <td></td>
                        <td>{{ $component['name'] }}</td>
                        <td class="right">{{ $component['show_qty'] ? $qty($component['qty']) : '' }}</td>
                        <td>{{ $component['show_qty'] ? $component['unit'] : '' }}</td>
                        <td>{{ $component['note'] }}</td>
                    </tr>
                @endif
                @foreach ($component['rows'] as $row)
                    <tr>
                        <td></td>
                        <td style="padding-left: 14px">{{ $row['name'] }}</td>
                        <td class="right">{{ $qty($row['qty']) }}</td>
                        <td>{{ $row['unit'] }}</td>
                        <td></td>
                    </tr>
                @endforeach
            @endforeach
            @foreach ($menu['issues'] as $issue)
                <tr><td></td><td colspan="4" class="muted">catatan: {{ $issue }}</td></tr>
            @endforeach
        @endforeach
    </tbody>
</table>
</body>
</html>
