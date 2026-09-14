{{--
  Bilah aplikasi 3S BCS: tab Owner / Admin / Accounting / Inventory / ...
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
        <a href="{{ $tab['url'] }}" class="sh-tab {{ $tab['active'] ? 'is-active' : '' }}" @if ($tab['active']) aria-current="page" @endif>
          @svg($tab['icon'], '', ['aria-hidden' => 'true'])
          <span>{{ $tab['label'] }}</span>
        </a>
      @endforeach
    </div>
  </nav>
@endif
