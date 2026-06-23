<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Superadmin - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'superadmin'])

  <div class="page-wrap mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
    <aside class="lg:self-start">
      <div class="app-sidebar sidebar-scroll-panel">
        <nav class="space-y-1">
          <a
            href="{{ route('superadmin.dashboard') }}"
            class="nav-link {{ request()->routeIs('superadmin.dashboard') ? 'nav-link-active' : '' }}"
          >
            Dashboard
          </a>
          <a
            href="{{ route('superadmin.backup.index') }}"
            class="nav-link {{ request()->routeIs('superadmin.backup.*') ? 'nav-link-active' : '' }}"
          >
            Backup &amp; Restore
          </a>
          <a href="{{ url('/owner-app') }}" class="nav-link">Owner App</a>
          <a href="{{ url('/admin-app') }}" class="nav-link">Admin App</a>
          <a href="{{ url('/accounting-app') }}" class="nav-link">Accounting App</a>
          <a href="{{ route('salesapp.dashboard') }}" class="nav-link">Sales App</a>
          <a href="{{ url('/production-app') }}" class="nav-link">Production App</a>
          <a href="{{ url('/delivery-app') }}" class="nav-link">Delivery App</a>
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
