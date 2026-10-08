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
  Halaman masuk 3S ONE. Dua bidang datar (tanpa gradasi/kaca): form yang lega
  di kiri, dan di kanan panel brand berisi pratinjau isi sistem -- kartu-kartu
  kecil yang memakai bahasa visual aplikasi (KPI, Tagihan Pembelian, susunan
  resep). Interaksi tanpa Alpine: lihat <script> di bawah.
--}}
<body class="min-h-full bg-white">
    <main class="lg-page">
        <section class="lg-form-side">
            <header class="lg-brand">
                @include('partials.brand-mark', ['size' => 'md'])
                <div class="leading-tight">
                    <p class="lg-wordmark">3S ONE</p>
                    <p class="lg-tagline">Business Control System</p>
                </div>
            </header>

            <div class="lg-form-wrap">
                <h1 class="lg-title">Selamat datang kembali</h1>
                <p class="lg-subtitle">Masuk dengan username atau email untuk melanjutkan pekerjaan Anda.</p>

                @if ($errors->any())
                    <div class="flash-error mb-0 mt-6" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ url('/login') }}" class="mt-8 space-y-5" data-login-form novalidate>
                    @csrf

                    <div>
                        <label for="name" class="form-label">Username atau email</label>
                        <input id="name" name="name" type="text" class="form-control lg-input"
                            value="{{ old('name') }}" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
                    </div>

                    <div>
                        <label for="password" class="form-label">Password</label>
                        <div class="lg-password">
                            <input id="password" name="password" type="password" class="form-control lg-input" required
                                autocomplete="current-password" data-password>
                            <button type="button" class="lg-reveal" data-reveal aria-controls="password" aria-pressed="false" aria-label="Tampilkan password">
                                <svg data-eye viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 010-1.186A10.004 10.004 0 0110 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0110 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                <svg data-eye-off viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" hidden><path fill-rule="evenodd" d="M3.28 2.22a.75.75 0 00-1.06 1.06l14.5 14.5a.75.75 0 101.06-1.06l-1.745-1.745a10.029 10.029 0 003.3-4.38 1.651 1.651 0 000-1.185A10.004 10.004 0 009.999 3a9.956 9.956 0 00-4.744 1.194L3.28 2.22zM7.752 6.69l1.092 1.092a2.5 2.5 0 013.374 3.373l1.091 1.092a4 4 0 00-5.557-5.557z" clip-rule="evenodd"/><path d="M10.748 13.93l2.523 2.523a9.987 9.987 0 01-3.27.547c-4.258 0-7.894-2.66-9.337-6.41a1.651 1.651 0 010-1.186A10.007 10.007 0 012.839 6.02L6.07 9.252a4 4 0 004.678 4.678z"/></svg>
                            </button>
                        </div>
                        <p class="lg-caps" data-caps hidden>Caps Lock aktif.</p>
                    </div>

                    <label for="remember" class="inline-flex cursor-pointer select-none items-center gap-2 text-sm font-medium text-slate-950">
                        <input id="remember" type="checkbox" name="remember" class="h-4 w-4">
                        Ingat saya di perangkat ini
                    </label>

                    <button type="submit" class="btn-primary lg-submit" data-submit>
                        <span class="lg-spinner" aria-hidden="true"></span>
                        <span data-submit-label>Masuk</span>
                    </button>
                </form>
            </div>

            <footer class="lg-foot">W3S Catering · 3S ONE {{ \App\Support\AppVersion::label() }}</footer>
        </section>

        {{-- Pratinjau isi sistem: dekoratif, disembunyikan dari pembaca layar. --}}
        <aside class="lg-showcase" aria-hidden="true">
            <div class="lg-showcase-inner">
                <p class="lg-eyebrow">Satu sistem, satu data</p>
                <h2 class="lg-headline">Pesanan, produksi, stok, dan keuangan bekerja di data yang sama.</h2>

                <div class="lg-stack">
                    <div class="lg-card lg-card-kpi" style="--i: 0">
                        <p class="lg-card-label">Penjualan bulan ini</p>
                        <p class="lg-card-value">Rp 128.450.000</p>
                        <p class="lg-card-trend">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.577 4.878a.75.75 0 01.919-.53l4.78 1.281a.75.75 0 01.531.919l-1.281 4.78a.75.75 0 01-1.449-.387l.81-3.022a19.407 19.407 0 00-5.594 5.203.75.75 0 01-1.139.093L7 10.06l-4.72 4.72a.75.75 0 01-1.06-1.061l5.25-5.25a.75.75 0 011.06 0l3.074 3.073a20.923 20.923 0 015.545-4.931l-3.042-.815a.75.75 0 01-.53-.919z" clip-rule="evenodd"/></svg>
                            12,4% dari bulan lalu
                        </p>
                        <svg class="lg-spark" viewBox="0 0 120 32" preserveAspectRatio="none"><polyline points="0,26 15,22 30,24 45,16 60,18 75,11 90,13 105,6 120,8" /></svg>
                    </div>

                    <div class="lg-card lg-card-bills" style="--i: 1">
                        <div class="lg-card-head">
                            <p class="lg-card-title">Tagihan Pembelian</p>
                            <span class="lg-pill is-warn">3 menunggu</span>
                        </div>
                        <ul class="lg-rows">
                            <li><span>TGH-0012 · Pasar Induk</span><b>Rp 1.240.000</b></li>
                            <li><span>TGH-0011 · Toko Sembako</span><b>Rp 860.500</b></li>
                            <li><span>TGH-0010 · Belanja lepas</span><b>Rp 312.000</b></li>
                        </ul>
                        <div class="lg-card-foot">
                            <div>
                                <p class="lg-card-label">Total menunggu dibayar</p>
                                <p class="lg-card-total">Rp 2.412.500</p>
                            </div>
                            <span class="lg-fake-btn">Bayar</span>
                        </div>
                    </div>

                    <div class="lg-card lg-card-tree" style="--i: 2">
                        <p class="lg-card-title">Nasi Ayam Bakar · 80 porsi</p>
                        <ul class="lg-tree">
                            <li><span class="lg-caret is-open"></span>Bumbu Bakar <em>Sub menu</em></li>
                            <li class="is-child">Kecap manis · 1,2 liter</li>
                            <li class="is-child">Bawang merah · 640 gram</li>
                            <li><span class="lg-caret"></span>Sambal Dasar <em>Sub menu</em></li>
                        </ul>
                    </div>
                </div>

                <div class="lg-apps">
                    @foreach (\App\Support\Navigation::apps() as $key => $app)
                        @continue($key === 'superadmin')
                        <span>{{ $app['label'] }}</span>
                    @endforeach
                </div>
            </div>
        </aside>
    </main>

    <script>
        // Lihat/sembunyikan password, peringatan Caps Lock, status tombol saat dikirim.
        (function () {
            var form = document.querySelector('[data-login-form]');
            if (!form) return;
            var password = form.querySelector('[data-password]');
            var reveal = form.querySelector('[data-reveal]');
            var caps = form.querySelector('[data-caps]');
            var submit = form.querySelector('[data-submit]');

            reveal.addEventListener('click', function () {
                var show = password.type === 'password';
                password.type = show ? 'text' : 'password';
                reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
                reveal.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
                reveal.querySelector('[data-eye]').hidden = show;
                reveal.querySelector('[data-eye-off]').hidden = !show;
                password.focus();
            });

            ['keydown', 'keyup'].forEach(function (type) {
                password.addEventListener(type, function (event) {
                    if (event.getModifierState) caps.hidden = !event.getModifierState('CapsLock');
                });
            });
            password.addEventListener('blur', function () { caps.hidden = true; });

            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    form.reportValidity();
                    return;
                }
                submit.disabled = true;
                submit.classList.add('is-loading');
                form.querySelector('[data-submit-label]').textContent = 'Memproses…';
            });
        })();
    </script>
</body>

</html>
