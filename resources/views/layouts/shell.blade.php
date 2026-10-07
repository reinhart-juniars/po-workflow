{{--
  Cangkang 3S ONE (Business Control System) untuk seluruh halaman Blade.

  Susunannya yang sudah dikenal pengguna: bilah aplikasi di atas (Owner /
  Admin / Accounting / Inventory / ...), menu aplikasi yang sedang dibuka di
  sidebar kiri, konten di kanan. Bilah dan gaya yang sama dipakai panel
  Filament (modul inventory) lewat partials.app-bar + resources/css/shell.css.

  Variabel: $appKey (kunci aplikasi), $title (judul halaman), $wide (main selebar
  layar untuk laporan), $narrow (kolom sempit untuk layar harian), $collapsed
  (sidebar ditutup sementara di semua ukuran layar, dibuka lewat tombol menu
  sebagai lapisan -- untuk laporan lebar yang butuh seluruh layar).
--}}
@php
    $shellUser = auth()->user();
    // ($app adalah variabel bawaan Blade untuk Application, jadi kuncinya bernama $appKey.)
    $shellApp = $appKey ?? \App\Support\Navigation::currentApp() ?? 'owner';
    $shellSections = \App\Support\Navigation::sidebar($shellApp, $shellUser);
    $shellHome = $shellUser ? \App\Support\Navigation::dashboardUrl($shellUser) : url('/');
    $mainWidth = ($wide ?? false) ? 'max-w-none' : (($narrow ?? false) ? 'max-w-3xl' : 'max-w-7xl');
    $collapsed = $collapsed ?? false;
    $pageTitle = trim(($title ?? '') !== '' ? $title.' · '.\App\Support\Navigation::appLabel($shellApp) : \App\Support\Navigation::appLabel($shellApp));
@endphp
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{{ $pageTitle }} · 3S ONE</title>
  @vite(['resources/css/app.css', 'resources/css/shell.css'])
  @stack('styles')
  @include('partials.nav-prefetch')
  @include('partials.page-transition')
</head>
<body class="shell-body min-h-full">
  <input type="checkbox" id="shell-nav-toggle" class="peer sr-only">

  {{-- Sidebar: menu aplikasi yang sedang dibuka --}}
  <aside class="shell-sidebar fixed inset-y-0 left-0 z-40 flex w-80 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] peer-checked:translate-x-0 {{ $collapsed ? 'shadow-xl' : 'lg:translate-x-0' }}">
    {{-- Ukuran & jarak persis sidebar Filament: header h-16 px-6, nav px-6 py-8, jarak grup 1.75rem --}}
    <div class="flex h-16 shrink-0 items-center border-b border-gray-950/5 px-6">
      <a href="{{ $shellHome }}" class="flex items-center gap-3">
        @include('partials.brand-mark', ['size' => 'md'])
<span class="leading-tight">
          <span class="block text-sm font-bold tracking-tight text-gray-900">3S ONE</span>
          <span class="block text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-500">Business Control System</span>
        </span>
      </a>
    </div>

    {{-- Grup & item bergeser -mx-2 seperti fi-sidebar-nav-groups: ikon di x=24, item mulai x=16 --}}
    {{-- Pencarian global. Di sidebar, bukan di bilah atas: bilah itu sudah
         penuh oleh tab aplikasi (lihat partials/global-search). Di luar <nav>
         karena mencari bukan menavigasi. --}}
    <div class="shrink-0 px-6 pt-5">
      @include('partials.global-search')
    </div>

    <nav class="flex flex-1 flex-col gap-y-7 overflow-y-auto px-6 pb-8 pt-6" aria-label="Menu {{ \App\Support\Navigation::appLabel($shellApp) }}">
      <p class="shell-app-name">{{ \App\Support\Navigation::appLabel($shellApp) }}</p>

      @foreach ($shellSections as $section)
        <details class="shell-group -mx-2" open>
          <summary class="shell-group-label">
            <span class="flex-1">{{ $section['label'] }}</span>
            @svg('heroicon-m-chevron-up', 'shell-chevron')
          </summary>
          <div class="mt-1 space-y-1">
            @foreach ($section['items'] as $item)
              <a href="{{ $item['url'] }}" class="shell-item {{ $item['active'] ? 'shell-item-active' : '' }}" @if ($item['active']) aria-current="page" @endif>
                @if ($item['icon'])
                  @svg($item['icon'], 'shell-icon')
                @else
                  <span class="shell-bullet"></span>
                @endif
                <span class="flex-1 truncate">{{ $item['label'] }}</span>
              </a>
            @endforeach
          </div>
        </details>
      @endforeach

      {{-- Di layar kecil bilah aplikasi tidak muat di atas: ditaruh di sini --}}
      <div class="-mx-2 border-t border-gray-100 pt-4 lg:hidden">
        <p class="shell-group-label">Aplikasi</p>
        <div class="mt-1 space-y-1">
          @foreach (\App\Support\Navigation::tabs($shellUser, $shellApp) as $tab)
            <a href="{{ $tab['url'] }}" class="shell-item {{ $tab['active'] ? 'shell-item-active' : '' }}">
              @svg($tab['icon'], 'shell-icon')
              <span class="flex-1 truncate">{{ $tab['label'] }}</span>
            </a>
          @endforeach
        </div>
      </div>

      @include('partials.sidebar-scroll-memory', ['app' => $shellApp])
    </nav>
  </aside>

  {{-- Penutup sidebar di layar kecil --}}
  <label for="shell-nav-toggle" class="fixed inset-0 z-30 hidden bg-gray-950/40 peer-checked:block {{ $collapsed ? '' : 'lg:peer-checked:hidden' }}"></label>

  <div class="flex min-h-screen flex-col {{ $collapsed ? '' : 'lg:pl-80' }}">
    <header class="shell-topbar sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
      <label for="shell-nav-toggle" class="cursor-pointer rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 {{ $collapsed ? '' : 'lg:hidden' }}" aria-label="Menu" title="Tampilkan menu">
        @svg('heroicon-o-bars-3', 'h-6 w-6')
      </label>

      <div class="hidden min-w-0 flex-1 lg:block">
        @include('partials.app-bar', ['currentApp' => $shellApp])
      </div>
      <p class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 lg:hidden">{{ $title ?? \App\Support\Navigation::appLabel($shellApp) }}</p>

      @include('partials.notification-bell')
      @include('partials.user-menu')
    </header>

    <main class="shell-main mx-auto w-full {{ $mainWidth }} flex-1 space-y-4 px-4 py-6 sm:px-6 lg:px-8">
      @if (session('success'))
        <div class="flash-success">{{ session('success') }}</div>
      @endif
      @if (session('error'))
        <div class="flash-error">{{ session('error') }}</div>
      @endif
      @if (session('warning'))
        <div class="flash-error">{{ session('warning') }}</div>
      @endif
      @if (isset($errors) && $errors->any())
        <div class="flash-error">
          <p class="mb-1 font-semibold">Gagal menyimpan data:</p>
          <ul class="list-disc pl-5 text-sm">
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @yield('content')
    </main>
  </div>

  {{-- Tombol unggah satu-klik (mis. Import Excel): input file ber-data-auto-submit
       mengirim formnya begitu berkas dipilih. Tanpa Alpine (cangkang tidak memuatnya). --}}
  <script>
    document.addEventListener('change', function (event) {
      var input = event.target;
      if (!input.matches || !input.matches('input[type=file][data-auto-submit]') || !input.files.length) return;
      var label = input.closest('label') && input.closest('label').querySelector('[data-import-label]');
      if (label) label.textContent = 'Mengunggah ' + input.files[0].name + '…';
      input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
    });
  </script>

  {{-- Penanda akhir halaman: transisi menunggu sampai sini (partials/page-transition) --}}
  <span id="sh-page-end" hidden></span>
</body>
</html>
