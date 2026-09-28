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

@if (($tampilan ?? 'global') === 'kartu')
    {{-- Komponen per menu: dua kartu per baris (dompdf tidak mendukung grid/flex). --}}
    <table class="plate-cards">
        @foreach (array_chunk($sheet['rows'], 2) as $pair)
            <tr>
                @foreach ($pair as $row)
                    <td style="width: 50%; vertical-align: top; padding: 0 6px 10px 0; border: 0">
                        <table>
                            <tr>
                                <td style="width: 44px; font-size: 20px; font-weight: bold; text-align: center">{{ $qty($row['qty']) }}</td>
                                <td><b>{{ $row['name'] }}</b><br><span class="muted">{{ $row['unit'] ?: 'porsi' }}{{ $row['remark'] ? ' · '.$row['remark'] : '' }}</span></td>
                            </tr>
                            @for ($slot = 0; $slot < max(count($row['components']), \App\Services\PlatingService::MIN_SLOTS); $slot++)
                                <tr>
                                    <td class="center muted">{{ $slot + 1 }}</td>
                                    <td style="height: 16px">{{ $row['components'][$slot] ?? '' }}</td>
                                </tr>
                            @endfor
                        </table>
                    </td>
                @endforeach
                @if (count($pair) === 1)<td style="border: 0"></td>@endif
            </tr>
        @endforeach
    </table>
@else
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
@endif
</body>
</html>
