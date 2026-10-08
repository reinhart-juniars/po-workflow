{{--
  Pengeluaran per kategori: batang horizontal berurutan (terbesar di atas).
  Menggantikan donat 11 irisan yang labelnya bertumpuk. Satu seri, satu warna
  brand (#2f4dc5, lolos validator dataviz); identitas dari nama di kiri, jadi
  tanpa legenda. Nilai & persen ditulis di tiap baris (data sedikit), tooltip
  saat hover/fokus memuat ketiganya.

  Variabel: $title, $subtitle, $chart = ['total' => float, 'rows' => [label, value, percentage, width]].
--}}
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
@endphp

<article class="table-shell">
    <div class="table-head">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="panel-title">{{ $title }}</h2>
                <p class="section-subtitle mt-1">{{ $subtitle }}</p>
            </div>
            @if ($chart['rows'] !== [])
                <div class="text-left sm:text-right">
                    <p class="text-sm font-medium text-slate-500">Total</p>
                    <p class="text-lg font-semibold tracking-tight tabular-nums text-slate-950">{{ $rp($chart['total']) }}</p>
                </div>
            @endif
        </div>
    </div>

    @if ($chart['rows'] !== [])
        <ol class="cat-bars" aria-label="{{ $title }}">
            @foreach ($chart['rows'] as $row)
                <li class="cat-bar-row" tabindex="0">
                    <span class="cat-bar-label" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                    <span class="cat-bar-track" aria-hidden="true">
                        <span class="cat-bar-fill" style="width: {{ max(0.6, $row['width']) }}%"></span>
                    </span>
                    <span class="cat-bar-value">{{ $rp($row['value']) }}</span>
                    <span class="cat-bar-pct">{{ $pct($row['percentage']) }}</span>
                    <span class="cat-bar-tip" role="tooltip">{{ $row['label'] }} · {{ $rp($row['value']) }} · {{ $pct($row['percentage']) }} dari total</span>
                </li>
            @endforeach
        </ol>
    @else
        <div class="p-6">
            <p class="text-sm text-slate-500">Belum ada pengeluaran pada periode aktif.</p>
        </div>
    @endif
</article>
