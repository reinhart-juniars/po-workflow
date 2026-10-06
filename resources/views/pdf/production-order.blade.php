<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $order->number }}</title>
    @include('pdf._production-style')
</head>
<body>
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $menus = $order->lines->where('kind', \App\Models\ProductionOrderLine::KIND_MENU);
    $manual = $order->lines->where('kind', \App\Models\ProductionOrderLine::KIND_MANUAL);
@endphp

<h1>SPK Produksi {{ $order->number }}</h1>
<div class="meta">
    <b>{{ $order->title }}</b><br>
    Dikerjakan tanggal <b>{{ $order->production_date->format('d/m/Y') }}</b>
    @if ($order->production_time) jam <b>{{ $order->production_time }}</b> @endif
    · Status: {{ $order->statusLabel() }}
    @if ($order->spk) · Slot {{ $order->spk->spk_code }} @endif
    @if ($order->notes)<br>{{ $order->notes }}@endif
</div>

<div class="section">Menu yang Dimasak</div>
<table>
    <thead>
        <tr>
            <th style="width: 28px">No</th>
            <th>Menu</th>
            <th class="right" style="width: 70px">Jumlah</th>
            <th style="width: 60px">Satuan</th>
            <th>Keterangan</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->lines as $i => $line)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td>{{ $line->displayName() }}@if (! $line->isMenu()) <span class="muted">(manual)</span>@endif</td>
                <td class="right">{{ $qty($line->qty) }}</td>
                <td>{{ $line->unit }}</td>
                <td class="muted">{{ $line->remark }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($requirements['issues'])
    <div class="issues">
        <b>Belum terhitung:</b>
        <ul>
            @foreach ($requirements['issues'] as $issue)<li>{{ $issue }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="section">Rekap Kebutuhan Bahan</div>
<table>
    <thead>
        <tr>
            <th>Bahan</th>
            <th class="right" style="width: 80px">Kebutuhan</th>
            <th style="width: 60px">Satuan</th>
            <th>Dipakai oleh</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($requirements['rows'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="right">{{ $qty($row['qty']) }}</td>
                <td>{{ $row['unit'] }}</td>
                <td class="muted">{{ implode(', ', $row['menus']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="center muted">Tidak ada baris menu yang bisa dihitung.</td></tr>
        @endforelse
    </tbody>
</table>

@if ($order->tasks->isNotEmpty())
    <div class="section">Lembar Kerja</div>
    <table>
        <thead>
            <tr>
                <th style="width: 28px">No</th>
                <th>Pekerjaan</th>
                <th style="width: 120px">Menu</th>
                <th style="width: 90px">Pelaksana</th>
                <th style="width: 40px" class="center">Selesai</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->tasks as $i => $task)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $task->summary() }}</td>
                    <td class="muted">{{ $task->menu_label }}</td>
                    <td>{{ $task->worker_name }}</td>
                    <td class="center">{{ $task->is_done ? '✓' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
