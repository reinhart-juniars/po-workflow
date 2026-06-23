<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Production App - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'production'])

  <main class="page-wrap mt-6 space-y-4">
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
