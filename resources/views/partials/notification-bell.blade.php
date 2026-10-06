{{--
  Lonceng header Blade. Sumbernya tabel `notifications` (Laravel database
  notifications) yang juga dipakai lonceng Filament -- satu daftar untuk
  seluruh 3S ONE. Isi `data` mengikuti format Filament: title, body, status,
  actions[].url.
--}}
@php
    $bellUser = auth()->user();
    $bellItems = $bellUser ? $bellUser->notifications()->latest()->limit(12)->get() : collect();
    $bellUnread = $bellItems->whereNull('read_at')->count();
@endphp
@if ($bellUser)
<details class="sh-bell">
  <summary aria-label="Notifikasi{{ $bellUnread ? ', '.$bellUnread.' belum dibaca' : '' }}">
    @svg('heroicon-o-bell', 'sh-bell-icon')
    @if ($bellUnread > 0)
      <span class="sh-bell-badge">{{ $bellUnread > 9 ? '9+' : $bellUnread }}</span>
    @endif
  </summary>
  <div class="sh-bell-menu">
    <div class="sh-bell-head">
      <strong>Notifikasi</strong>
      @if ($bellUnread > 0)
        <form method="POST" action="{{ route('notifications.read-all') }}">
          @csrf
          <button type="submit">Tandai semua dibaca</button>
        </form>
      @endif
    </div>
    @forelse ($bellItems as $item)
      @php $status = $item->data['status'] ?? 'info'; @endphp
      <a href="{{ route('notifications.open', $item->id) }}" class="sh-bell-item {{ $item->read_at ? 'is-read' : '' }}">
        <span class="sh-bell-dot is-{{ $status }}"></span>
        <span class="sh-bell-text">
          <span class="sh-bell-title">{{ $item->data['title'] ?? '' }}</span>
          @if (! empty($item->data['body']))
            <span class="sh-bell-body">{{ $item->data['body'] }}</span>
          @endif
          <span class="sh-bell-time">{{ $item->created_at->diffForHumans() }}</span>
        </span>
      </a>
    @empty
      <p class="sh-bell-empty">Belum ada notifikasi.</p>
    @endforelse
  </div>
</details>
@endif
