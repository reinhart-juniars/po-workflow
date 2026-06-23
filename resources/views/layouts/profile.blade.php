<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Profil Akun - {{ $title ?? '' }}</title>
  @vite('resources/css/app.css')
</head>
<body class="app-shell">
  @include('partials.app-header', ['currentApp' => 'profile'])

  <main class="page-wrap mt-6 max-w-3xl space-y-4">
    @if (session('success'))
      <div class="flash-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
      <div class="flash-error">{{ $errors->first() }}</div>
    @endif
    @if (session('warning'))
      <div class="flash-error">{{ session('warning') }}</div>
    @endif

    @yield('content')
  </main>
</body>
</html>
