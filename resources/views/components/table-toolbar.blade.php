{{--
  Toolbar tabel ala panel Filament (Inventory): kotak cari + ikon filter
  berbadge jumlah filter aktif, popover berisi field filter, dan baris
  "Filter aktif" berisi chip yang bisa dilepas satu per satu.

  Field filter ditulis sebagai data, bukan markup, supaya tampilan, badge,
  dan label chip (label opsi, bukan nilai mentahnya) seragam di semua halaman.

  Pemakaian (di kepala .table-card, menggantikan .table-card-head):
    <x-table-toolbar title="Daftar Pengeluaran" :action="route('...index')"
      search="q" :search-value="$q"
      :filters="[
        ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
         'value' => [$dateFrom, $dateTo], 'presets' => true],
        ['type' => 'select', 'name' => 'category_id', 'label' => 'Kategori',
         'options' => $categories->pluck('name', 'id'), 'value' => $categoryId, 'placeholder' => 'Semua kategori'],
        ['type' => 'text', 'name' => 'supplier', 'label' => 'Supplier', 'value' => $supplier, 'list' => 'supplier-options'],
      ]" />

  Jenis field: select, text, number, date, month, date-range.
  live: pencarian berjalan sendiri 500 ms setelah berhenti mengetik (seperti
  Filament); kursor dikembalikan ke kotak cari setelah halaman dimuat ulang.
  Opsi per field: removable (bawaan true; date-range hanya bila tanggalnya
  ada di URL -- periode bawaan controller tampil sebagai keterangan),
  placeholder, list (datalist), presets.
  standalone: toolbar berdiri sebagai kartu sendiri (mis. grid kartu).
  inline: tanpa kartu, chip filter & ikon dalam satu baris -- untuk filter
  yang berlaku ke seluruh halaman (laporan/dashboard), ditaruh di hero.
  open: popover langsung terbuka (mis. laporan yang wajib memilih customer).
--}}
@props([
    'action',
    'title' => null,
    'meta' => null,
    'filters' => [],
    'search' => null,
    'searchValue' => null,
    'searchPlaceholder' => 'Cari',
    'live' => false,
    'keep' => [],
    'shortcuts' => [],
    'standalone' => false,
    'inline' => false,
    'open' => false,
])

@php
    $formatDate = function ($value, string $type = 'date'): ?string {
        if (blank($value)) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return (string) $value;
        }

        return $type === 'month' ? $date->translatedFormat('F Y') : $date->translatedFormat('j M Y');
    };

    $stringValue = fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? '' : (string) $value);

    // Chip "Filter aktif" dari nilai efektif (termasuk bawaan controller).
    $chips = [];

    foreach ($filters as $filter) {
        $type = $filter['type'] ?? 'text';

        if ($type === 'date-range') {
            [$from, $to] = array_pad(array_map($stringValue, (array) ($filter['value'] ?? [])), 2, '');

            if ($from === '' && $to === '') {
                continue;
            }

            $text = match (true) {
                $from !== '' && $to !== '' => $formatDate($from).' – '.$formatDate($to),
                $from !== '' => 'sejak '.$formatDate($from),
                default => 'sampai '.$formatDate($to),
            };

            // Periode bawaan controller (tidak ada di URL) tampil sebagai
            // keterangan; yang dipilih pengguna bisa dilepas.
            $userChosen = request()->filled($filter['from']) || request()->filled($filter['to']);
            $chips[] = ['text' => ($filter['label'] ?? 'Periode').': '.$text, 'params' => [$filter['from'], $filter['to']], 'removable' => $filter['removable'] ?? $userChosen];

            continue;
        }

        $value = $stringValue($filter['value'] ?? null);

        if ($value === '') {
            continue;
        }

        $display = match ($type) {
            'select' => collect($filter['options'] ?? [])->get($value, $value),
            'date', 'month' => $formatDate($value, $type),
            default => $value,
        };

        $chips[] = ['text' => $filter['label'].': '.$display, 'params' => [$filter['name']], 'removable' => $filter['removable'] ?? true];
    }

    $filterNames = collect($filters)->flatMap(fn ($f) => ($f['type'] ?? '') === 'date-range' ? [$f['from'], $f['to']] : [$f['name']])->all();
    $baseQuery = collect(request()->query())->only(array_merge($keep, $search ? [$search] : []))->filter(fn ($v) => filled($v))->all();
    $resetUrl = $action.($baseQuery ? '?'.http_build_query($baseQuery) : '');
    $popoverId = 'tt-popover-'.substr(md5($action.implode(',', $filterNames)), 0, 8);
    // Popover dipindah ke <body> saat dibuka (lihat skrip di bawah); field-nya
    // tetap milik form ini lewat atribut form="...".
    $formId = $popoverId.'-form';
@endphp

<div {{ $attributes->class(['tt', 'tt-standalone' => $standalone, 'tt-inline' => $inline]) }} data-tt>
  <form method="GET" action="{{ $action }}" class="tt-bar" id="{{ $formId }}" data-tt-form>
    <div class="tt-start">
      @if ($inline)
        {{-- Varian inline: chip filter langsung di baris ini. --}}
        <div class="tt-chips">
          @foreach ($chips as $chip)
            <span class="tt-chip">
              {{ $chip['text'] }}
              @if ($chip['removable'])
                <a href="{{ request()->fullUrlWithoutQuery(array_merge($chip['params'], ['page'])) }}" class="tt-chip-remove" aria-label="Lepas filter {{ $chip['text'] }}">
                  @svg('heroicon-m-x-mark', 'h-3.5 w-3.5')
                </a>
              @endif
            </span>
          @endforeach
        </div>
      @elseif (isset($start))
        {{ $start }}
      @elseif ($title)
        <span class="tt-title">{{ $title }}</span>
        @if (filled($meta))
          <span class="tt-meta">{{ $meta }}</span>
        @endif
      @endif
    </div>

    <div class="tt-end">
      @foreach ($keep as $name)
        @if (filled(request()->query($name)))
          <input type="hidden" name="{{ $name }}" value="{{ request()->query($name) }}">
        @endif
      @endforeach

      @if ($search)
        <label class="tt-search">
          @svg('heroicon-m-magnifying-glass', 'tt-search-icon')
          <input type="search" name="{{ $search }}" value="{{ $searchValue }}" placeholder="{{ $searchPlaceholder }}" autocomplete="off" aria-label="{{ $searchPlaceholder }}"
                 @if ($live) data-tt-live @endif>
        </label>
      @endif

      {{ $actions ?? '' }}

      @if ($filters)
        <button type="button" class="tt-icon-btn" data-tt-toggle @if ($open) data-tt-open @endif aria-haspopup="dialog" aria-expanded="false"
                aria-controls="{{ $popoverId }}" aria-label="Filter" data-tooltip="Filter">
          @svg('heroicon-m-funnel', 'h-5 w-5')
          @if (count($chips) > 0)
            <span class="tt-badge">{{ count($chips) }}</span>
          @endif
        </button>

        <div id="{{ $popoverId }}" class="tt tt-popover" role="dialog" aria-label="Filter" data-tt-popover hidden>
          <div class="tt-popover-head">
            <span class="tt-popover-title">Filter</span>
            <a href="{{ $resetUrl }}" class="tt-reset">Atur ulang</a>
          </div>

          @if ($shortcuts)
            <div class="tt-shortcuts">
              @foreach ($shortcuts as $label => $url)
                <a href="{{ $url }}" class="tt-preset">{{ $label }}</a>
              @endforeach
            </div>
          @endif

          <div class="tt-fields">
            @foreach ($filters as $filter)
              @php($type = $filter['type'] ?? 'text')

              @if ($type === 'date-range')
                @php([$from, $to] = array_pad(array_map($stringValue, (array) ($filter['value'] ?? [])), 2, ''))
                <div class="tt-field" data-tt-range>
                  <span class="tt-label">{{ $filter['label'] ?? 'Periode' }}</span>
                  @if ($filter['presets'] ?? true)
                    <div class="tt-presets">
                      <button type="button" class="tt-preset" data-tt-preset="this_month">Bulan Ini</button>
                      <button type="button" class="tt-preset" data-tt-preset="last_month">Bulan Lalu</button>
                      <button type="button" class="tt-preset" data-tt-preset="this_year">Tahun Berjalan</button>
                    </div>
                  @endif
                  <div class="tt-range">
                    <span class="tt-range-label">Dari</span>
                    <input type="date" form="{{ $formId }}" name="{{ $filter['from'] }}" value="{{ $from }}" class="tt-input" data-tt-from aria-label="{{ $filter['fromLabel'] ?? 'Dari tanggal' }}">
                    <span class="tt-range-label">Sampai</span>
                    <input type="date" form="{{ $formId }}" name="{{ $filter['to'] }}" value="{{ $to }}" class="tt-input" data-tt-to aria-label="{{ $filter['toLabel'] ?? 'Sampai tanggal' }}">
                  </div>
                </div>
              @elseif ($type === 'select')
                <label class="tt-field">
                  <span class="tt-label">{{ $filter['label'] }}</span>
                  <select form="{{ $formId }}" name="{{ $filter['name'] }}" class="tt-input" @if ($filter['required'] ?? false) required @endif>
                    @unless ($filter['required'] ?? false)
                      <option value="">{{ $filter['placeholder'] ?? 'Semua' }}</option>
                    @endunless
                    @foreach ($filter['options'] ?? [] as $optionValue => $optionLabel)
                      <option value="{{ $optionValue }}" @selected($stringValue($filter['value'] ?? null) === (string) $optionValue)>{{ $optionLabel }}</option>
                    @endforeach
                  </select>
                </label>
              @else
                <label class="tt-field">
                  <span class="tt-label">{{ $filter['label'] }}</span>
                  <input type="{{ $type }}" form="{{ $formId }}" name="{{ $filter['name'] }}" value="{{ $stringValue($filter['value'] ?? null) }}" class="tt-input"
                         @if (filled($filter['placeholder'] ?? null)) placeholder="{{ $filter['placeholder'] }}" @endif
                         @if (filled($filter['list'] ?? null)) list="{{ $filter['list'] }}" autocomplete="off" @endif
                         @if (isset($filter['min'])) min="{{ $filter['min'] }}" @endif
                         @if (isset($filter['max'])) max="{{ $filter['max'] }}" @endif
                         @if ($filter['required'] ?? false) required @endif>
                </label>
              @endif
            @endforeach
          </div>

          {{ $extra ?? '' }}

          <button type="submit" form="{{ $formId }}" class="tt-apply">Terapkan filter</button>
        </div>
      @endif
    </div>
  </form>

  @if ($chips && ! $inline)
    <div class="tt-indicators">
      <span class="tt-indicators-label">Filter aktif</span>
      <div class="tt-chips">
        @foreach ($chips as $chip)
          <span class="tt-chip">
            {{ $chip['text'] }}
            @if ($chip['removable'])
              <a href="{{ request()->fullUrlWithoutQuery(array_merge($chip['params'], ['page'])) }}" class="tt-chip-remove" aria-label="Lepas filter {{ $chip['text'] }}">
                @svg('heroicon-m-x-mark', 'h-3.5 w-3.5')
              </a>
            @endif
          </span>
        @endforeach
      </div>
      @if (collect($chips)->contains('removable', true))
        <a href="{{ $resetUrl }}" class="tt-clear" aria-label="Lepas semua filter" data-tooltip="Lepas semua filter">
          @svg('heroicon-m-x-mark', 'h-5 w-5')
        </a>
      @endif
    </div>
  @endif
</div>

@once
  <script>
    (() => {
      // Popover filter dipindah ke <body> saat dibuka dan diposisikan fixed:
      // kartu tabel memakai overflow-hidden dan backdrop-filter, yang
      // memotong/menggeser elemen fixed di dalamnya. Sama seperti Filament
      // yang meneleport popover-nya ke atas halaman.
      const place = (button, popover) => {
        const rect = button.getBoundingClientRect();
        const width = Math.min(320, window.innerWidth - 32);
        const left = Math.max(16, Math.min(rect.right - width, window.innerWidth - width - 16));
        const spaceBelow = window.innerHeight - rect.bottom;

        popover.style.width = width + 'px';
        popover.style.left = left + 'px';

        if (spaceBelow < 360 && rect.top > spaceBelow) {
          popover.style.top = '';
          popover.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
          popover.style.maxHeight = (rect.top - 24) + 'px';
        } else {
          popover.style.bottom = '';
          popover.style.top = (rect.bottom + 8) + 'px';
          popover.style.maxHeight = (spaceBelow - 24) + 'px';
        }
      };

      let open = null;

      const close = () => {
        if (!open) return;
        open.popover.hidden = true;
        open.button.setAttribute('aria-expanded', 'false');
        open = null;
      };

      document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-tt-toggle]');

        if (toggle) {
          const popover = document.getElementById(toggle.getAttribute('aria-controls'));
          const wasOpen = open && open.popover === popover;
          close();

          if (!wasOpen && popover) {
            if (popover.parentElement !== document.body) {
              document.body.appendChild(popover);
            }

            popover.hidden = false;
            place(toggle, popover);
            toggle.setAttribute('aria-expanded', 'true');
            open = { button: toggle, popover };
            popover.querySelector('input, select')?.focus({ preventScroll: true });
          }

          return;
        }

        const preset = event.target.closest('[data-tt-preset]');

        if (preset) {
          const range = preset.closest('[data-tt-range]');
          const now = new Date();
          const pad = (n) => String(n).padStart(2, '0');
          const fmt = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
          const ranges = {
            this_month: [new Date(now.getFullYear(), now.getMonth(), 1), new Date(now.getFullYear(), now.getMonth() + 1, 0)],
            last_month: [new Date(now.getFullYear(), now.getMonth() - 1, 1), new Date(now.getFullYear(), now.getMonth(), 0)],
            this_year: [new Date(now.getFullYear(), 0, 1), new Date(now.getFullYear(), 11, 31)],
          };
          const [from, to] = ranges[preset.dataset.ttPreset] || [];

          if (range && from) {
            range.querySelector('[data-tt-from]').value = fmt(from);
            range.querySelector('[data-tt-to]').value = fmt(to);
          }

          return;
        }

        if (open && !open.popover.contains(event.target)) {
          close();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) {
          const button = open.button;
          close();
          button.focus();
        }
      });

      // Field kosong tidak ikut ke URL (?kategori=&akun=...), sama seperti
      // URL filter Filament. Diaktifkan lagi saat halaman dipulihkan dari
      // cache tombol Kembali.
      document.addEventListener('submit', (event) => {
        if (!event.target.matches('form[data-tt-form]')) return;

        [...event.target.elements].forEach((field) => {
          if (field.name && field.value === '') field.disabled = true;
        });
      });

      window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-tt-form]').forEach((form) => {
          [...form.elements].forEach((field) => { field.disabled = false; });
        });
      });

      // Cari langsung saat mengetik. Halaman dimuat ulang, jadi kursor
      // dikembalikan ke kotak cari supaya pengguna bisa lanjut mengetik.
      let searchTimer = null;

      document.addEventListener('input', (event) => {
        const input = event.target.closest('[data-tt-live]');
        if (!input) return;

        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          try { sessionStorage.setItem('tt-search-focus', location.pathname); } catch (e) {}
          input.form.requestSubmit();
        }, 500);
      });

      try {
        if (sessionStorage.getItem('tt-search-focus') === location.pathname) {
          sessionStorage.removeItem('tt-search-focus');
          const input = document.querySelector('[data-tt-live]');

          if (input) {
            input.focus({ preventScroll: true });
            input.setSelectionRange(input.value.length, input.value.length);
          }
        }
      } catch (e) {}

      // Laporan yang belum bisa tampil tanpa filter wajib: popover dibuka.
      document.querySelector('[data-tt-open]')?.click();

      window.addEventListener('resize', () => open && place(open.button, open.popover));
      window.addEventListener('scroll', () => open && place(open.button, open.popover), true);
    })();
  </script>
@endonce
