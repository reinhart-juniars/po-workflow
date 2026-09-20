{{--
  Menu pengguna 3S ONE: avatar inisial + nama, popover Profil Akun / Keluar.
  Dipakai header Blade (layouts.shell) DAN topbar Filament (render hook
  USER_MENU_BEFORE, menu bawaan Filament disembunyikan di shell.css) supaya
  pengguna melihat hal yang persis sama di semua aplikasi.
--}}
@php
    $menuUser = auth()->user();
    $menuInitials = collect(explode(' ', trim((string) $menuUser?->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'U';
@endphp
@if ($menuUser)
<details class="sh-user">
  <summary>
    <span class="sh-avatar">{{ $menuInitials }}</span>
    <span class="sh-user-name">{{ $menuUser->name }}</span>
  </summary>
  <div class="sh-user-menu">
    <div class="sh-user-menu-head">
      <strong>{{ $menuUser->name }}</strong>
      <span>{{ $menuUser->roles->pluck('name')->map(fn ($r) => \App\Models\User::roleLabel($r))->implode(', ') }}</span>
    </div>
    <a href="{{ route('profile.edit') }}">@svg('heroicon-m-user-circle') Profil Akun</a>
    <form method="POST" action="{{ url('/logout') }}">
      @csrf
      <button type="submit" class="is-danger">@svg('heroicon-m-arrow-left-on-rectangle') Keluar</button>
    </form>
  </div>
</details>
<script>
  // Menu pengguna menutup saat klik di luar / Escape (details tidak melakukannya sendiri).
  if (!window.__shUserMenuBound) {
    window.__shUserMenuBound = true;
    document.addEventListener('click', (e) => {
      document.querySelectorAll('details.sh-user[open], details.sh-bell[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') document.querySelectorAll('details.sh-user[open], details.sh-bell[open]').forEach((d) => d.removeAttribute('open'));
    });
  }
</script>
@endif
