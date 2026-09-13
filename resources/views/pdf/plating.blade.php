<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Plating {{ $order->number }}</title>
    @include('pdf._production-style')
</head>
<body>
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$order->production_date->dayOfWeek];
@endphp

<h1>Lembar Plating</h1>
<div class="meta">
    <b>{{ $order->number }}</b> @if ($order->title) — {{ $order->title }} @endif<br>
    {{ $hari }}, <b>{{ $order->production_date->format('d/m/Y') }}</b>
    @if ($order->production_time) jam {{ $order->production_time }} @endif
    · Total <b>{{ $qty($sheet['total_qty']) }}</b> porsi
</div>

<table>
    <thead>
        <tr>
            <th style="width: 28px">No</th>
            <th style="width: 180px">Menu</th>
            <th class="right" style="width: 60px">Jumlah</th>
            <th>Komponen di piring</th>
            <th style="width: 120px">Keterangan</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sheet['rows'] as $i => $row)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td><b>{{ $row['name'] }}</b></td>
                <td class="right">{{ $qty($row['qty']) }} {{ $row['unit'] }}</td>
                <td>
                    @if ($row['components'])
                        @foreach ($row['components'] as $component)
                            &#9633; {{ $component }}@if (! $loop->last) &nbsp; @endif
                        @endforeach
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td class="muted">{{ $row['remark'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
