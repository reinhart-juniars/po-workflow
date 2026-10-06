<article class="table-shell">
  <div class="table-head">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <h2 class="panel-title">{{ $title }}</h2>
        <p class="section-subtitle mt-1">{{ $subtitle }}</p>
      </div>
      {{-- <div class="text-sm text-slate-500">
        {{ $chart['category_count'] > 0 ? number_format($chart['category_count']) . ' kategori ditampilkan penuh' : 'Belum ada kategori' }}
      </div> --}}
    </div>
  </div>

  @if ($chart['has_data'])
    <div class="p-4">
      <div class="relative mx-auto h-[360px] w-full max-w-[460px] sm:h-[420px] sm:max-w-[460px]">
        <svg
          class="h-full w-full"
          viewBox="0 0 {{ $chart['size'] }} {{ $chart['size'] }}"
          role="img"
          aria-label="{{ $title }}"
        >
          <circle
            cx="{{ $chart['center'] }}"
            cy="{{ $chart['center'] }}"
            r="{{ $chart['radius'] }}"
            fill="none"
            stroke="#e2e8f0"
            stroke-width="{{ $chart['stroke_width'] }}"
          />

          @foreach ($chart['slices'] as $slice)
            <circle
              cx="{{ $chart['center'] }}"
              cy="{{ $chart['center'] }}"
              r="{{ $chart['radius'] }}"
              fill="none"
              stroke="{{ $slice['color'] }}"
              stroke-width="{{ $chart['stroke_width'] }}"
              stroke-dasharray="{{ $slice['dasharray'] }}"
              stroke-dashoffset="{{ $slice['dashoffset'] }}"
              transform="rotate(-90 {{ $chart['center'] }} {{ $chart['center'] }})"
            />
          @endforeach

          @foreach ($chart['slices'] as $slice)
            <polyline
              points="{{ $slice['line_start_x'] }},{{ $slice['line_start_y'] }} {{ $slice['line_break_x'] }},{{ $slice['line_break_y'] }} {{ $slice['line_end_x'] }},{{ $slice['label_y'] + 4 }}"
              fill="none"
              stroke="{{ $slice['color'] }}"
              stroke-width="1.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
            <circle
              cx="{{ $slice['line_start_x'] }}"
              cy="{{ $slice['line_start_y'] }}"
              r="2.5"
              fill="{{ $slice['color'] }}"
            />
            <text
              x="{{ $slice['label_x'] }}"
              y="{{ $slice['label_y'] - 2 }}"
              fill="#0f172a"
              font-size="10"
              font-weight="700"
              text-anchor="{{ $slice['label_anchor'] }}"
            >{{ $slice['label_short'] }}</text>
            <text
              x="{{ $slice['label_x'] }}"
              y="{{ $slice['label_y'] + 11 }}"
              fill="#64748b"
              font-size="9.5"
              text-anchor="{{ $slice['label_anchor'] }}"
            >{{ $slice['percentage_label'] }}</text>
          @endforeach
        </svg>

        <div class="absolute inset-0 flex items-center justify-center">
          <div class="flex h-[116px] w-[116px] flex-col items-center justify-center rounded-full bg-white/95 px-3 text-center shadow-sm ring-1 ring-slate-200/80 sm:h-[124px] sm:w-[124px]">
            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Total</p>
            <p class="mt-1 break-words text-sm font-display leading-tight text-slate-900 sm:text-base">{{ $chart['total_label'] }}</p>
          </div>
        </div>
      </div>

      @if (! empty($chart['top_categories']))
        <div class="mt-6">
          <div class="mb-3 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Top 3 Pengeluaran Terbesar</h3>
            <span class="text-xs text-slate-500">Rangkuman kategori utama pada periode aktif</span>
          </div>

          <div class="grid gap-3 md:grid-cols-3">
            @foreach ($chart['top_categories'] as $item)
              <div class="rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
                <div class="flex items-center justify-between gap-3">
                  <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold text-white" style="background: {{ $item['color'] }}">
                    {{ $item['rank'] }}
                  </span>
                  <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ $item['percentage_label'] }}</span>
                </div>
                <p class="mt-3 text-sm font-semibold text-slate-900">{{ $item['label'] }}</p>
                <p class="mt-1 text-lg font-display text-slate-900">{{ $item['value_label'] }}</p>
              </div>
            @endforeach
          </div>
        </div>
      @endif

      {{-- @if (! empty($chart['largest_category']))
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-slate-700">
          <span class="font-semibold text-slate-900">Kategori terbesar:</span>
          {{ $chart['largest_category']['label'] }} ({{ $chart['largest_category']['percentage_label'] }}) dengan nilai {{ $chart['largest_category']['value_label'] }}.
        </div>
      @endif --}}
    </div>
  @else
    <div class="p-4">
      <p class="text-sm text-slate-500">Belum ada data pengeluaran pada periode aktif, jadi pie chart kategori belum bisa ditampilkan.</p>
    </div>
  @endif
</article>
