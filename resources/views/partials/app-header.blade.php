@php
    // Judul & pengalih aplikasi dibaca dari satu sumber (App\Support\AppSwitcher)
    // supaya sama persis dengan topbar Inventory App.
    $user = auth()->user();
    $apps = \App\Support\AppSwitcher::apps();

    $currentApp = $currentApp ?? 'owner';
    $title = $currentApp === 'profile' ? 'Profil Akun' : ($apps[$currentApp]['title'] ?? 'App');
    $switchLinks = \App\Support\AppSwitcher::linksFor($user, $currentApp);
@endphp

<header class="border-b border-slate-200/80 bg-white/85 backdrop-blur">
  <div class="page-wrap py-4">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex items-center gap-3">
        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-950 via-slate-900 to-brand-700 text-white shadow-sm ring-1 ring-slate-900/10">
          <div class="flex flex-col items-center leading-none">
            <span class="text-[8px] font-semibold uppercase tracking-[0.24em] text-cyan-200">3S</span>
            <span class="mt-0.5 text-[11px] font-bold tracking-[0.16em] text-white">BCS</span>
          </div>
        </div>
        <div>
          <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">3S Business Control System</p>
          <p class="font-display text-lg leading-tight text-slate-900">{{ $title }}</p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        @foreach ($switchLinks as $link)
          <a href="{{ $link['url'] }}" class="btn-ghost text-slate-600">
            {{ $link['label'] }}
          </a>
        @endforeach

        @if ($user)
          <span class="hidden rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 sm:inline-flex">
            {{ $user->name }}
          </span>

          <a href="{{ route('profile.edit') }}" class="btn-ghost">
            Profile
          </a>

          <form method="POST" action="{{ url('/logout') }}">
            @csrf
            <button class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-100">
              Logout
            </button>
          </form>
        @endif
      </div>
    </div>
  </div>
</header>
