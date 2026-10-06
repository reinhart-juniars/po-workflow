<article class="table-shell">
  <div class="table-head">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h2 class="panel-title">{{ $title }}</h2>
        <p class="section-subtitle mt-1">{{ $subtitle }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        @foreach ($chart['series'] as $series)
          <span class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
            <span class="chart-dot" style="background: {{ $series['color'] }}"></span>
            {{ $series['name'] }}
          </span>
        @endforeach
      </div>
    </div>
  </div>

  <div class="p-4">
    <div class="mb-3 flex items-center justify-between text-xs text-slate-500">
      <span>Puncak skala: {{ $chart['max_label'] }}</span>
      <span>{{ count($chart['x_ticks']) > 0 ? 'Tren periode aktif' : 'Belum ada data' }}</span>
    </div>

    <svg
      class="owner-line-chart"
      viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}"
      preserveAspectRatio="none"
      role="img"
      aria-label="{{ $title }}"
    >
      @foreach ($chart['y_ticks'] as $tick)
        <line
          x1="18"
          y1="{{ $tick['y'] }}"
          x2="{{ $chart['width'] - 18 }}"
          y2="{{ $tick['y'] }}"
          stroke="rgba(148, 163, 184, 0.18)"
          stroke-width="1"
          stroke-dasharray="4 6"
        />
        <text x="18" y="{{ $tick['y'] - 6 }}" fill="#94a3b8" font-size="10">{{ $tick['label'] }}</text>
      @endforeach

      @foreach ($chart['series'] as $series)
        <polyline
          points="{{ $series['points'] }}"
          fill="none"
          stroke="{{ $series['color'] }}"
          stroke-width="3"
          stroke-linecap="round"
          stroke-linejoin="round"
        />

        @if (! empty($series['last_point']))
          <circle
            cx="{{ $series['last_point']['x'] }}"
            cy="{{ $series['last_point']['y'] }}"
            r="4"
            fill="{{ $series['color'] }}"
            stroke="white"
            stroke-width="2"
          />
        @endif
      @endforeach

      @foreach ($chart['x_ticks'] as $tick)
        <text
          x="{{ $tick['x'] }}"
          y="{{ $chart['height'] - 8 }}"
          fill="#64748b"
          font-size="11"
          text-anchor="middle"
        >{{ $tick['label'] }}</text>
      @endforeach
    </svg>
  </div>
</article>
