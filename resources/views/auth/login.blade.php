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
  Halaman masuk sistem internal W3S Catering. Gaya portal korporat, bukan
  halaman produk SaaS: panel identitas di kiri (tanpa angka atau data contoh
  apa pun -- supaya tidak dikira data perusahaan yang sebenarnya) dan form di
  kanan. Bidang datar, tanpa gradasi/kaca. Interaksi tanpa Alpine (script bawah).
--}}
<body class="min-h-full bg-white">
    <main class="lg-page">
        <aside class="lg-identity">
            <div class="lg-identity-top">
                @include('partials.brand-mark', ['size' => 'lg'])
                <div class="leading-tight">
                    <p class="lg-identity-name">3S ONE</p>
                    <p class="lg-identity-tag">Business Control System</p>
                </div>
            </div>

            <div class="lg-identity-body">
                <p class="lg-identity-company">W3S Catering</p>
                <h2 class="lg-identity-title">Sistem internal perusahaan</h2>
                <p class="lg-identity-text">
                    Pesanan, produksi, persediaan, pembelian, dan keuangan dikelola dalam satu sistem.
                </p>
            </div>

            <div class="lg-identity-notice">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                <p>Hanya untuk karyawan dan pihak yang diberi akun oleh perusahaan.</p>
            </div>
        </aside>

        <section class="lg-form-side">
            <header class="lg-brand">
                @include('partials.brand-mark', ['size' => 'md'])
                <div class="leading-tight">
                    <p class="lg-wordmark">3S ONE</p>
                    <p class="lg-tagline">W3S Catering</p>
                </div>
            </header>

            <div class="lg-form-wrap">
                <h1 class="lg-title">Masuk</h1>
                <p class="lg-subtitle">Gunakan username atau email dan password akun Anda.</p>

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

                <p class="lg-help">Lupa password atau akun terkunci? Hubungi Owner atau administrator sistem untuk reset.</p>
            </div>

            <footer class="lg-foot">3S ONE {{ \App\Support\AppVersion::label() }} · W3S Catering</footer>
        </section>
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
