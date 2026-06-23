<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - 3S Business Control System</title>
    @vite('resources/css/app.css')
</head>

<body class="min-h-screen bg-slate-950">
    <div
        class="relative flex min-h-dvh items-center justify-center overflow-hidden px-3 py-4 sm:min-h-screen sm:px-6 sm:py-10 lg:px-8">
        <div class="pointer-events-none absolute -left-16 top-8 h-64 w-64 rounded-full bg-cyan-300/25 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-24 bottom-0 h-72 w-72 rounded-full bg-brand-500/35 blur-3xl">
        </div>

        <div
            class="relative grid w-full max-w-md max-h-[calc(100dvh-1rem)] overflow-hidden rounded-2xl border border-white/20 bg-slate-900/65 shadow-[0_40px_100px_-35px_rgba(15,23,42,0.95)] backdrop-blur sm:max-h-[calc(100dvh-2rem)] sm:rounded-3xl lg:max-h-none lg:max-w-5xl lg:grid-cols-2">
            <section
                class="hidden flex-col justify-between border-r border-white/15 bg-gradient-to-br from-slate-950 via-slate-900 to-brand-700 p-10 text-slate-100 lg:flex">
                <div>
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-3xl bg-white/10 ring-1 ring-white/15">
                            <div class="flex flex-col items-center leading-none">
                                <span
                                    class="text-[9px] font-semibold uppercase tracking-[0.24em] text-cyan-200">3S</span>
                                <span class="mt-1 text-xs font-bold tracking-[0.18em] text-white">BCS</span>
                            </div>
                        </div>

                        <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-cyan-200/90">
                            Business Ops Suite
                        </p>
                    </div>

                    <div class="mt-6">
                        <h1
                            class="max-w-md font-display text-[clamp(2rem,3.2vw,3.35rem)] leading-[0.94] tracking-[-0.045em] text-white">
                            <span class="block">3S</span>
                            <span class="block text-white/92">Business Control System</span>
                        </h1>
                    </div>

                    {{-- <p class="mt-6 max-w-md text-sm leading-7 text-slate-300">
                        Sistem kontrol keuangan dan operasional untuk memantau penjualan, pengeluaran, dan performa
                        bisnis secara real-time.
                    </p></br> --}}
                </div>

                <div
                    class="inline-flex w-fit items-center gap-3 rounded-2xl border border-white/20 bg-white/10 px-4 py-3">
                    <div class="h-2.5 w-2.5 rounded-full bg-emerald-300"></div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-100/90">Current Build
                        </p>
                        <p class="mt-1 text-sm font-medium text-white">Version 2.3.10</p>
                    </div>
                </div>
            </section>

            <section class="overflow-y-auto bg-white p-5 sm:p-8 lg:p-10">
                <div class="mx-auto w-full max-w-md">
                    <div class="mb-4 rounded-2xl border border-brand-100 bg-brand-50/70 p-3 lg:hidden">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-950 via-slate-900 to-brand-700 text-white">
                                <div class="flex flex-col items-center leading-none">
                                    <span
                                        class="text-[8px] font-semibold uppercase tracking-[0.24em] text-cyan-200">3S</span>
                                    <span class="mt-0.5 text-[10px] font-bold tracking-[0.16em] text-white">BCS</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-700">3S
                                    Business Control System</p>
                                <p class="mt-0.5 text-xs text-slate-600">Version 2.3.10</p>
                            </div>
                        </div>
                    </div>

                    <h2 class="font-display text-2xl text-slate-900 sm:text-3xl">Login</h2>
                    <p class="mt-1.5 text-sm text-slate-500">Masuk menggunakan username dan password kamu.</p>

                    @if ($errors->any())
                        <div class="flash-error mt-6 mb-0">{{ $errors->first() }}</div>
                    @endif

                    <form method="POST" action="{{ url('/login') }}" class="mt-6 space-y-4 sm:space-y-5">
                        @csrf

                        <div>
                            <label for="name" class="mb-1.5 block">Username</label>
                            <input id="name" name="name" type="text" class="py-3 text-base"
                                value="{{ old('name') }}" required autofocus autocomplete="username"
                                autocapitalize="none">
                        </div>

                        <div>
                            <label for="password" class="mb-1.5 block">Password</label>
                            <input id="password" name="password" type="password" class="py-3 text-base" required
                                autocomplete="current-password">
                        </div>

                        <div
                            class="sticky bottom-0 -mx-5 mt-5 space-y-3 border-t border-slate-200 bg-white/95 px-5 py-3 pb-[calc(env(safe-area-inset-bottom)+0.75rem)] backdrop-blur sm:static sm:mx-0 sm:mt-0 sm:space-y-0 sm:border-0 sm:bg-transparent sm:px-0 sm:py-0 sm:pb-0">
                            <label for="remember"
                                class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600">
                                <input id="remember" type="checkbox" name="remember"
                                    class="h-5 w-5 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30 sm:h-4 sm:w-4">
                                Remember me
                            </label>

                            <div class="sm:flex sm:justify-end">
                                <button type="submit"
                                    class="btn-primary w-full px-5 py-3 text-base sm:w-auto sm:py-2.5 sm:text-sm">
                                    Masuk
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</body>

</html>
