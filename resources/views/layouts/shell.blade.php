{{--
  Cangkang 3S ONE (Business Control System) untuk seluruh halaman Blade.

  Susunannya yang sudah dikenal pengguna: bilah aplikasi di atas (Owner /
  Admin / Accounting / Inventory / ...), menu aplikasi yang sedang dibuka di
  sidebar kiri, konten di kanan. Bilah dan gaya yang sama dipakai panel
  Filament (modul inventory) lewat partials.app-bar + resources/css/shell.css.

  Variabel: $appKey (kunci aplikasi), $title (judul halaman), $wide (main selebar
  layar untuk laporan), $narrow (kolom sempit untuk layar harian).
--}}
@php
    $shellUser = auth()->user();
    // ($app adalah variabel bawaan Blade untuk Application, jadi kuncinya bernama $appKey.)
    $shellApp = $appKey ?? \App\Support\Navigation::currentApp() ?? 'owner';
    $shellSections = \App\Support\Navigation::sidebar($shellApp, $shellUser);
    $shellHome = $shellUser ? \App\Support\Navigation::dashboardUrl($shellUser) : url('/');
    $shellInitials = collect(explode(' ', trim((string) $shellUser?->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'U';
    $mainWidth = ($wide ?? false) ? 'max-w-none' : (($narrow ?? false) ? 'max-w-3xl' : 'max-w-7xl');
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
</head>
<body class="shell-body min-h-full">
  <input type="checkbox" id="shell-nav-toggle" class="peer sr-only">

  {{-- Sidebar: menu aplikasi yang sedang dibuka --}}
  <aside class="shell-sidebar fixed inset-y-0 left-0 z-40 flex w-80 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] peer-checked:translate-x-0 lg:translate-x-0">
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
    <nav class="flex flex-1 flex-col gap-y-7 overflow-y-auto px-6 py-8" aria-label="Menu {{ \App\Support\Navigation::appLabel($shellApp) }}">
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
    </nav>
  </aside>

  {{-- Penutup sidebar di layar kecil --}}
  <label for="shell-nav-toggle" class="fixed inset-0 z-30 hidden bg-gray-950/40 peer-checked:block lg:peer-checked:hidden"></label>

  <div class="flex min-h-screen flex-col lg:pl-80">
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
      <label for="shell-nav-toggle" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Menu">
        @svg('heroicon-o-bars-3', 'h-6 w-6')
      </label>

      <div class="hidden min-w-0 flex-1 lg:block">
        @include('partials.app-bar', ['currentApp' => $shellApp])
      </div>
      <p class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 lg:hidden">{{ $title ?? \App\Support\Navigation::appLabel($shellApp) }}</p>

      @if ($shellUser)
        <details class="sh-user">
          <summary>
            <span class="sh-avatar">{{ $shellInitials }}</span>
            <span class="sh-user-name hidden sm:inline">{{ $shellUser->name }}</span>
          </summary>
          <div class="sh-user-menu">
            <div class="sh-user-menu-head">
              <strong>{{ $shellUser->name }}</strong>
              <span>{{ $shellUser->roles->pluck('name')->implode(', ') }}</span>
            </div>
            <a href="{{ route('profile.edit') }}">@svg('heroicon-m-user-circle') Profil Akun</a>
            <form method="POST" action="{{ url('/logout') }}">
              @csrf
              <button type="submit" class="is-danger">@svg('heroicon-m-arrow-left-on-rectangle') Keluar</button>
            </form>
          </div>
        </details>
      @endif
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

  <script>
    // Menu pengguna menutup saat klik di luar / Escape (details tidak melakukannya sendiri).
    document.addEventListener('click', (e) => {
      document.querySelectorAll('details.sh-user[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') document.querySelectorAll('details.sh-user[open]').forEach((d) => d.removeAttribute('open'));
    });
  </script>
</body>
</html>
