@php
    // Pengalih aplikasi yang sama dengan header Blade: hanya aplikasi yang
    // boleh dibuka pengguna ini, tanpa aplikasi yang sedang dibuka.
    $links = \App\Support\AppSwitcher::linksFor(auth()->user(), 'inventory');
@endphp

@if ($links !== [])
  <div class="hidden items-center gap-1 lg:flex">
    @foreach ($links as $link)
      <a href="{{ $link['url'] }}"
         class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900">
        {{ $link['label'] }}
      </a>
    @endforeach
  </div>
@endif
