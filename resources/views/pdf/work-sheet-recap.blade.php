<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Lembar Kerja {{ $recap['date']->format('d/m/Y') }}</title>
    @include('pdf._production-style')
</head>
<body>
<h1>Rekap Lembar Kerja</h1>
<div class="meta">
    Dikerjakan <b>{{ $recap['date']->copy()->locale('id')->translatedFormat('l, d F Y') }}</b>
    @if ($recap['orders'] !== [])
        <br>Sumber:
        @foreach ($recap['orders'] as $order)
            {{ $order['number'] }} (SPK {{ $order['date'] }}, {{ $order['days'] === 0 ? 'H' : 'H-'.$order['days'] }})@if (! $loop->last), @endif
        @endforeach
    @endif
</div>

<table>
    <thead>
        <tr>
            <th style="width: 24px">No</th>
            <th style="width: 70px">Pelaksana</th>
            <th style="width: 130px">Menu</th>
            <th style="width: 70px">Pekerjaan</th>
            <th>Bahan / Objek</th>
            <th style="width: 80px">Jumlah</th>
            <th style="width: 40px" class="center">Selesai</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($recap['rows'] as $i => $row)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td>{{ $row['worker'] }}</td>
                <td class="muted">{{ implode(' + ', $row['menus']) }}</td>
                <td>{{ $row['task'] }}</td>
                <td>{{ $row['object'] }}</td>
                <td>{{ $row['quantity'] }}</td>
                <td class="center">{{ $row['done'] ? '✓' : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="center muted">Belum ada pekerjaan untuk tanggal ini.</td></tr>
        @endforelse
    </tbody>
</table>

@if ($recap['chores']->isNotEmpty())
    <div class="section">Tugas Umum</div>
    <table>
        <tbody>
            @foreach ($recap['chores'] as $chore)
                <tr>
                    <td style="width: 110px"><b>{{ $chore->worker_name }}</b></td>
                    <td>{{ $chore->task }}</td>
                    <td style="width: 40px"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
