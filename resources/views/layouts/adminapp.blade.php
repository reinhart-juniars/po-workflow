<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Admin App - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
  @stack('styles')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'admin'])

  @php
      $adminNav = [
          [
              'section' => 'Ringkasan',
              'items' => [
                  ['route' => 'adminapp.dashboard', 'label' => 'Dashboard', 'match' => 'adminapp.dashboard'],
              ],
          ],
          [
              'section' => 'Master Data',
              'items' => [
                  ['route' => 'adminapp.products.index', 'label' => 'Master Menu', 'match' => 'adminapp.products.*'],
                  ['route' => 'adminapp.customers.index', 'label' => 'Master Customer', 'match' => 'adminapp.customers.*'],
              ],
          ],
          [
              'section' => 'Operasional',
              'items' => [
                  ['route' => 'adminapp.orders.index', 'label' => 'Purchase Orders', 'match' => 'adminapp.orders.*'],
                  ['route' => 'adminapp.spk.index', 'label' => 'SPK', 'match' => 'adminapp.spk.*'],
                  ['route' => 'adminapp.delivery.index', 'label' => 'Delivery', 'match' => 'adminapp.delivery.*'],
                  ['route' => 'adminapp.reports.missing-costs', 'label' => 'Menu Tanpa HPP/OHC', 'match' => 'adminapp.reports.missing-costs*'],
              ],
          ],
          [
              'section' => 'Laporan',
              'items' => [
                  ['route' => 'adminapp.reports.orders', 'label' => 'Laporan PO', 'match' => 'adminapp.reports.orders*'],
                  ['route' => 'adminapp.reports.production', 'label' => 'Laporan Produksi', 'match' => 'adminapp.reports.production*'],
                  ['route' => 'adminapp.reports.delivery', 'label' => 'Laporan Delivery', 'match' => 'adminapp.reports.delivery*'],
                  ['route' => 'adminapp.reports.bestseller', 'label' => 'Laporan Best Seller', 'match' => 'adminapp.reports.bestseller*'],
              ],
          ],
      ];
  @endphp

  <div class="page-wrap mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
    <aside class="lg:self-start">
      <div class="app-sidebar sidebar-scroll-panel sidebar-natural-panel">
        <nav class="space-y-2">
          @foreach ($adminNav as $group)
            <div>
              <div class="nav-section-title">{{ $group['section'] }}</div>
              <div class="space-y-1">
                @foreach ($group['items'] as $item)
                  <a
                    href="{{ route($item['route']) }}"
                    class="nav-link {{ request()->routeIs($item['match']) ? 'nav-link-active' : '' }}"
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
      @if (session('warning'))
        <div class="flash-error">{{ session('warning') }}</div>
      @endif

      @yield('content')
    </main>
  </div>
</body>
</html>
