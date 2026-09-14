{{--
  Cangkang 3S Business Control System untuk seluruh halaman Blade.

  Sidebar, topbar, dan menunya identik dengan panel Filament (inventory /
  resep / produksi) karena keduanya membaca App\Support\Navigation -- dari
  sudut pandang pengguna ini satu sistem, bukan beberapa "app".

  Variabel: $title (judul halaman), $wide (main selebar layar untuk laporan),
  $narrow (kolom sempit untuk layar harian produksi/pengiriman).
--}}
@php
    $shellUser = auth()->user();
    $shellGroups = \App\Support\Navigation::forUser($shellUser);
    $shellDashboardUrl = $shellUser ? \App\Support\Navigation::dashboardUrl($shellUser) : url('/');
    $shellDashboardActive = \App\Support\Navigation::isDashboardActive($shellUser);
    $shellInitials = collect(explode(' ', trim((string) $shellUser?->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'U';
    $mainWidth = ($wide ?? false) ? 'max-w-none' : (($narrow ?? false) ? 'max-w-3xl' : 'max-w-7xl');
@endphp
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{{ isset($title) && $title !== '' ? $title.' · ' : '' }}3S BCS</title>
  @vite('resources/css/app.css')
  @stack('styles')
</head>
<body class="shell-body min-h-full">
  <input type="checkbox" id="shell-nav-toggle" class="peer sr-only">

  {{-- Sidebar --}}
  <aside class="shell-sidebar fixed inset-y-0 left-0 z-40 flex w-[18rem] -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform peer-checked:translate-x-0 lg:translate-x-0">
    <div class="flex h-16 items-center gap-3 border-b border-gray-100 px-5">
      <a href="{{ $shellDashboardUrl }}" class="flex items-center gap-3">
        <span class="flex h-10 w-10 flex-col items-center justify-center rounded-2xl bg-gradient-to-br from-slate-950 via-slate-900 to-brand-700 text-white shadow-sm ring-1 ring-slate-900/10 leading-none">
          <span class="text-[7px] font-semibold uppercase tracking-[0.24em] text-cyan-200">3S</span>
          <span class="mt-0.5 text-[10px] font-bold tracking-[0.16em]">BCS</span>
        </span>
        <span class="leading-tight">
          <span class="block text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-500">3S Business</span>
          <span class="block text-sm font-bold text-gray-900">Control System</span>
        </span>
      </a>
    </div>

    <nav class="flex-1 space-y-5 overflow-y-auto px-4 py-5">
      <a href="{{ $shellDashboardUrl }}" class="shell-item {{ $shellDashboardActive ? 'shell-item-active' : '' }}">
        @svg('heroicon-o-home', 'shell-icon')
        <span>Dashboard</span>
      </a>

      @foreach ($shellGroups as $group)
        <details class="shell-group" {{ $group['active'] || $loop->first ? 'open' : '' }}>
          <summary class="shell-group-label">
            @svg($group['icon'], 'shell-icon')
            <span class="flex-1">{{ $group['label'] }}</span>
            @svg('heroicon-m-chevron-down', 'shell-chevron')
          </summary>
          <div class="mt-1 space-y-0.5">
            @foreach ($group['items'] as $item)
              <a href="{{ $item['url'] }}" class="shell-subitem {{ $item['active'] ? 'shell-subitem-active' : '' }}">
                <span class="shell-bullet"></span>
                <span class="flex-1 truncate">{{ $item['label'] }}</span>
              </a>
            @endforeach
          </div>
        </details>
      @endforeach
    </nav>
  </aside>

  {{-- Penutup sidebar di layar kecil --}}
  <label for="shell-nav-toggle" class="fixed inset-0 z-30 hidden bg-gray-950/40 peer-checked:block lg:peer-checked:hidden"></label>

  <div class="flex min-h-screen flex-col lg:pl-[18rem]">
    <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-gray-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
      <label for="shell-nav-toggle" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Menu">
        @svg('heroicon-o-bars-3', 'h-6 w-6')
      </label>

      <div class="min-w-0 flex-1">
        @if (isset($title) && $title !== '')
          <p class="truncate text-sm font-semibold text-gray-700">{{ $title }}</p>
        @endif
      </div>

      @if ($shellUser)
        <details class="relative">
          <summary class="flex cursor-pointer list-none items-center gap-2 rounded-full p-1 pr-3 hover:bg-gray-100">
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $shellInitials }}</span>
            <span class="hidden text-sm font-medium text-gray-700 sm:inline">{{ $shellUser->name }}</span>
          </summary>
          <div class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
            <div class="border-b border-gray-100 px-4 py-2">
              <p class="truncate text-sm font-semibold text-gray-900">{{ $shellUser->name }}</p>
              <p class="truncate text-xs text-gray-500">{{ $shellUser->roles->pluck('name')->implode(', ') }}</p>
            </div>
            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
              @svg('heroicon-m-user-circle', 'h-4 w-4 text-gray-400') Profil Akun
            </a>
            <form method="POST" action="{{ url('/logout') }}">
              @csrf
              <button class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">
                @svg('heroicon-m-arrow-left-on-rectangle', 'h-4 w-4') Keluar
              </button>
            </form>
          </div>
        </details>
      @endif
    </header>

    <main class="mx-auto w-full {{ $mainWidth }} flex-1 space-y-4 px-4 py-6 sm:px-6 lg:px-8">
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
</body>
</html>
