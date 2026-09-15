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
  Halaman masuk: satu pintu untuk seluruh 3S ONE. Dua bidang datar -- panel
  gelap berisi identitas, panel putih berisi form -- tanpa kabut/gradasi.
--}}
<body class="min-h-full bg-gray-50">
    <main class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <section class="hidden flex-col justify-between bg-slate-950 px-12 py-12 text-white lg:flex">
            <div class="flex items-center gap-3">
                @include('partials.brand-mark', ['size' => 'lg'])
                <div class="leading-tight">
                    <p class="text-lg font-bold tracking-tight text-white">3S ONE</p>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Business Control System</p>
                </div>
            </div>

            <div>
                <h1 class="max-w-lg font-display text-5xl leading-[1.02] tracking-[-0.035em] text-white">
                    Satu sistem untuk pesanan, produksi, stok, dan keuangan.
                </h1>
                <p class="mt-6 max-w-md text-[15px] leading-7 text-slate-300">
                    Owner, Admin, Accounting, Inventory, Sales, Production, dan Delivery bekerja di data yang sama.
                </p>
            </div>

            <p class="text-xs text-slate-500">W3S Catering · 3S ONE {{ \App\Support\AppVersion::label() }}</p>
        </section>

        <section class="flex items-center justify-center px-5 py-8 sm:px-8">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    @include('partials.brand-mark', ['size' => 'md'])
                    <div class="leading-tight">
                        <p class="text-sm font-bold tracking-tight text-slate-900">3S ONE</p>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">Business Control System · {{ \App\Support\AppVersion::label() }}</p>
                    </div>
                </div>

                <h2 class="font-display text-3xl tracking-tight text-slate-900">Masuk ke 3S ONE</h2>
                <p class="mt-2 text-sm text-slate-500">Pakai username atau email beserta password.</p>

                @if ($errors->any())
                    <div class="flash-error mt-6 mb-0" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ url('/login') }}" class="mt-8 space-y-5">
                    @csrf

                    <div>
                        <label for="name" class="form-label">Username atau email</label>
                        <input id="name" name="name" type="text" class="form-control py-3 text-base"
                            value="{{ old('name') }}" required autofocus autocomplete="username" autocapitalize="none">
                    </div>

                    <div>
                        <label for="password" class="form-label">Password</label>
                        <input id="password" name="password" type="password" class="form-control py-3 text-base" required
                            autocomplete="current-password">
                    </div>

                    <div class="flex items-center justify-between gap-4 pt-1">
                        <label for="remember" class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600">
                            <input id="remember" type="checkbox" name="remember"
                                class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
                            Ingat saya
                        </label>

                        <button type="submit" class="btn-primary px-6">Masuk</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</body>

</html>
