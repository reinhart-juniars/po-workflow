<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Delivery App - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'delivery'])

  <main class="page-wrap mt-4 max-w-3xl space-y-3 sm:mt-6 sm:space-y-4">
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
