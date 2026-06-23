<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Owner App - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'owner'])

  <div class="page-wrap mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
    <aside class="lg:self-start">
      <div class="app-sidebar sidebar-scroll-panel">
        <nav class="space-y-1">
          <a
            href="{{ route('ownerapp.dashboard') }}"
            class="nav-link {{ request()->routeIs('ownerapp.dashboard') ? 'nav-link-active' : '' }}"
          >
            Dashboard
          </a>

          @hasanyrole('owner|superadmin')
            <a
              href="{{ route('ownerapp.users.index') }}"
              class="nav-link {{ request()->routeIs('ownerapp.users.*') ? 'nav-link-active' : '' }}"
            >
              Master User
            </a>
          @endhasanyrole

          @hasanyrole('owner|superadmin|admin')
            <a
              href="{{ route('ownerapp.hpp-analysis') }}"
              class="nav-link {{ request()->routeIs('ownerapp.hpp-analysis') ? 'nav-link-active' : '' }}"
            >
              Analisa HPP
            </a>

            <a
              href="{{ route('ownerapp.audit.index') }}"
              class="nav-link {{ request()->routeIs('ownerapp.audit.*') ? 'nav-link-active' : '' }}"
            >
              Audit Logs
            </a>
          @endhasanyrole
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
      @if ($errors->any())
        <div class="flash-error">
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
