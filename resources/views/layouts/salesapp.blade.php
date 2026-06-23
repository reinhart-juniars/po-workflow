<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Sales App - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'sales'])

  @php
    $salesNav = [
      [
        'section' => 'Ringkasan',
        'items' => [
          ['route' => 'salesapp.dashboard', 'label' => 'Dashboard', 'match' => ['salesapp.dashboard']],
        ],
      ],
      [
        'section' => 'Laporan',
        'items' => [
          ['route' => 'salesapp.reports.final-retur', 'label' => 'Laporan Sales Final & Retur', 'match' => ['salesapp.reports.final-retur', 'salesapp.reports']],
          ['route' => 'salesapp.reports.waste', 'label' => 'Laporan Waste', 'match' => ['salesapp.reports.waste']],
          ['route' => 'salesapp.reports.sales', 'label' => 'Laporan Penjualan', 'match' => ['salesapp.reports.sales*']],
        ],
      ],
    ];
  @endphp

  <div class="page-wrap mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
    <aside class="sales-sidebar lg:self-start">
      <div class="app-sidebar sidebar-scroll-panel">
        <nav class="space-y-2">
          @foreach ($salesNav as $group)
            <div>
              <div class="nav-section-title">{{ $group['section'] }}</div>
              <div class="space-y-1">
                @foreach ($group['items'] as $item)
                  <a
                    href="{{ route($item['route']) }}"
                    class="nav-link {{ request()->routeIs(...$item['match']) ? 'nav-link-active' : '' }}"
                  >
                    {{ $item['label'] }}
                  </a>
                @endforeach
              </div>
            </div>
          @endforeach
        </nav>
      </div>
    </aside>

    <main class="app-main-panel space-y-4">
      @if (session('success'))
        <div class="flash-success">{{ session('success') }}</div>
      @endif
      @if (session('error'))
        <div class="flash-error">{{ session('error') }}</div>
      @endif
      @if ($errors->any())
        <div class="flash-error">
          <p class="font-semibold">Gagal menyimpan data:</p>
          <ul class="mt-1 list-disc pl-5">
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @yield('content')
    </main>
  </div>
</body>
</html>
