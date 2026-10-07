<!doctype html>
<html lang="id" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · 3S ONE</title>
    @vite(['resources/css/app.css', 'resources/css/shell.css'])
</head>

{{--
  Halaman masuk: satu pintu untuk seluruh 3S ONE. Mengikuti halaman masuk
  panel Filament (kartu di tengah kanvas slate-50) supaya layar pertama
  sudah satu bahasa dengan isi sistem. Di bawah kartu, deretan aplikasi
  memakai pil bilah aplikasi yang sama (shell.css .sh-tab) -- bukan tautan,
  hanya menunjukkan apa saja yang ada di dalam.
--}}
<body class="min-h-full bg-slate-50">
    <main class="login-page">
        <div class="login-column">
            <div class="login-brand">
                @include('partials.brand-mark', ['size' => 'lg'])
                <div class="leading-tight">
                    <p class="login-wordmark">3S ONE</p>
                    <p class="login-tagline">Business Control System</p>
                </div>
            </div>

            <section class="login-card" aria-labelledby="login-title">
                <h1 id="login-title" class="login-title">Masuk ke 3S ONE</h1>
                <p class="login-subtitle">Pakai username atau email beserta password.</p>

                @if ($errors->any())
                    <div class="flash-error mb-0 mt-6" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ url('/login') }}" class="mt-6 space-y-5">
                    @csrf

                    <div>
                        <label for="name" class="form-label">Username atau email</label>
                        <input id="name" name="name" type="text" class="form-control"
                            value="{{ old('name') }}" required autofocus autocomplete="username" autocapitalize="none">
                    </div>

                    <div>
                        <label for="password" class="form-label">Password</label>
                        <input id="password" name="password" type="password" class="form-control" required
                            autocomplete="current-password">
                    </div>

                    <label for="remember" class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-950">
                        <input id="remember" type="checkbox" name="remember" class="h-4 w-4">
                        Ingat saya
                    </label>

                    <button type="submit" class="btn-primary w-full">Masuk</button>
                </form>
            </section>

            <div class="login-apps" aria-label="Aplikasi di dalam 3S ONE">
                @foreach (\App\Support\Navigation::apps() as $key => $app)
                    @continue($key === 'superadmin')
                    <span class="sh-tab">
                        @svg($app['icon'], '', ['aria-hidden' => 'true'])
                        <span>{{ $app['label'] }}</span>
                    </span>
                @endforeach
            </div>

            <p class="login-foot">W3S Catering · 3S ONE {{ \App\Support\AppVersion::label() }}</p>
        </div>
    </main>
</body>

</html>
