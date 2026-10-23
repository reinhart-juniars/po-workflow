{{--
  Bilah aplikasi 3S ONE: tab Owner / Admin / Accounting / Inventory / ...
  sesuai peran pengguna. Dipakai layout Blade dan topbar Filament, jadi
  hanya memakai kelas dari resources/css/shell.css.

  Variabel: $currentApp (kunci aplikasi yang sedang dibuka).
--}}
@php
    $tabs = \App\Support\Navigation::tabs(auth()->user(), $currentApp ?? null);
@endphp

@if ($tabs !== [])
  <nav class="sh-appbar" aria-label="Aplikasi">
    <div class="sh-appbar-scroll">
      @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" class="sh-tab {{ $tab['active'] ? 'is-active' : '' }}" title="{{ $tab['label'] }}" @if ($tab['active']) aria-current="page" @endif>
          @svg($tab['icon'], '', ['aria-hidden' => 'true'])
          <span>{{ $tab['label'] }}</span>
        </a>
      @endforeach
    </div>
  </nav>
  {{-- Tab tidak muat (mis. superadmin dengan 9 aplikasi di layar laptop): tab
       yang tidak aktif tinggal ikon (nama tetap ada di title), supaya strip
       tidak perlu digeser ke samping. Diukur ulang setiap lebar berubah. --}}
  <script>
    (function () {
      var nav = document.currentScript.previousElementSibling;
      var strip = nav && nav.querySelector('.sh-appbar-scroll');
      if (!strip || !window.ResizeObserver) return;
      function fit() {
        nav.classList.remove('is-compact');
        if (strip.scrollWidth > strip.clientWidth + 1) nav.classList.add('is-compact');
      }
      new ResizeObserver(fit).observe(nav.parentElement || nav);
      fit();
      // Web font yang baru termuat melebarkan label tanpa mengubah lebar
      // wadah, jadi ResizeObserver tidak terpicu: ukur ulang setelahnya.
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
      window.addEventListener('load', fit);
    })();
  </script>
@endif
