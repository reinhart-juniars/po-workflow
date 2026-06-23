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

  <main class="mx-auto mt-6 w-full px-4 sm:px-6 lg:px-8">
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
</body>
</html>
