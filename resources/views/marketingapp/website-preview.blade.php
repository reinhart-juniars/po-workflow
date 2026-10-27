@extends('layouts.marketingapp', ['title' => 'Pratinjau Website'])

@push('styles')
<style>
    .wp-summary { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .wp-chip { font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: #f1f5f9; color: #334155; }
    .wp-chip.is-add { background: #dcfce7; color: #166534; }
    .wp-chip.is-del { background: #fee2e2; color: #991b1b; }
    .wp-chip.is-edit { background: #fef3c7; color: #92400e; }
    .wp-brand { margin-top: 24px; }
    .wp-brand > h2 { font-size: 17px; font-weight: 700; color: #0f172a; }
    .wp-brand > p { font-size: 12.5px; color: #64748b; }
    .wp-group { margin-top: 16px; }
    .wp-group h3 { font-size: 14px; font-weight: 600; color: #0f172a; }
    .wp-group .wp-desc { font-size: 12px; color: #64748b; margin: 2px 0 8px; }
    .wp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
    .wp-card { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; overflow: hidden; position: relative; }
    .wp-card.is-changed { border-color: #f59e0b; box-shadow: 0 0 0 1px #f59e0b; }
    .wp-photo { aspect-ratio: 4 / 3; background: #f1f5f9; display: flex; align-items: center; justify-content: center; }
    .wp-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .wp-photo .empty { font-size: 11px; color: #b91c1c; text-align: center; padding: 0 8px; }
    .wp-name { padding: 8px 10px 10px; font-size: 13px; font-weight: 600; color: #0f172a; line-height: 1.3; }
    .wp-old { display: block; font-size: 11px; font-weight: 400; color: #64748b; text-decoration: line-through; }
    .wp-marks { position: absolute; top: 6px; left: 6px; display: flex; flex-wrap: wrap; gap: 4px; }
    .wp-mark { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 999px; background: rgba(15, 23, 42, .8); color: #fff; }
    .wp-empty-group { font-size: 12px; color: #94a3b8; }
    .wp-publish { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; justify-content: space-between; }
</style>
@endpush

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Pratinjau Website</h1>
            <p class="section-subtitle">Menu persis seperti yang akan tampil di www.w3scatering.com. Periksa dulu, baru terbitkan.</p>
        </div>
        <a href="{{ route('marketingapp.catalog.index') }}" class="btn-ghost">Kembali ke Katalog</a>
    </div>

    <section class="app-card mt-4 p-4">
        <div class="wp-publish">
            <div>
                <p class="text-sm font-semibold text-slate-900">
                    {{ number_format($draft['total'], 0, ',', '.') }} menu akan tampil
                    @if ($published)
                        · terbitan terakhir {{ number_format(collect($published['brands'])->sum('count'), 0, ',', '.') }} menu
                    @endif
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($websiteStatus['last'])
                        Terakhir diterbitkan {{ $websiteStatus['last']->created_at->translatedFormat('d M Y, H:i') }} oleh {{ $websiteStatus['last']->user?->name ?? 'pengguna terhapus' }}.
                    @else
                        Belum pernah diterbitkan dari 3S ONE; website masih menampilkan menu lama.
                    @endif
                </p>
                <div class="wp-summary">
                    @if ($changeCount === 0 && $published)
                        <span class="wp-chip">Tidak ada perubahan dari terbitan terakhir</span>
                    @else
                        @if (count($changes['added'])) <span class="wp-chip is-add">{{ count($changes['added']) }} baru</span> @endif
                        @if (count($changes['removed'])) <span class="wp-chip is-del">{{ count($changes['removed']) }} hilang dari website</span> @endif
                        @if (count($changes['renamed'])) <span class="wp-chip is-edit">{{ count($changes['renamed']) }} ganti nama</span> @endif
                        @if (count($changes['moved'])) <span class="wp-chip is-edit">{{ count($changes['moved']) }} pindah kategori</span> @endif
                        @if (count($changes['photo'])) <span class="wp-chip is-edit">{{ count($changes['photo']) }} foto baru</span> @endif
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('marketingapp.website.publish') }}"
                  onsubmit="return confirm('Terbitkan {{ $draft['total'] }} menu ini ke website? Website diperbarui dalam ±1–2 menit.')">
                @csrf
                {{-- Sidik versi yang sedang dilihat: bila menu berubah sebelum ditekan, terbit ditolak. --}}
                <input type="hidden" name="feed_version" value="{{ $draft['version'] }}">
                <button type="submit" class="btn-primary"
                        @disabled(! $websiteStatus['configured'] || $websiteStatus['cooldown'] > 0 || $draft['total'] === 0)>
                    @svg('heroicon-m-globe-alt', 'h-4 w-4')
                    Terbitkan ke Website
                </button>
                @if (! $websiteStatus['configured'])
                    <p class="mt-1 text-xs text-slate-500">Website belum tersambung ke 3S ONE.</p>
                @elseif ($websiteStatus['cooldown'] > 0)
                    <p class="mt-1 text-xs text-slate-500">Website sedang diperbarui. Coba lagi dalam {{ $websiteStatus['cooldown'] }} detik.</p>
                @elseif ($draft['total'] === 0)
                    <p class="mt-1 text-xs text-slate-500">Belum ada menu yang siap tampil.</p>
                @endif
            </form>
        </div>
    </section>

    @include('marketingapp.partials.website-warnings', ['warnings' => $websiteStatus['warnings']])

    @if ($changes['removed'])
        <section class="table-shell mt-4">
            <div class="table-head">Akan hilang dari website ({{ count($changes['removed']) }})</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Nama di website</th><th>Halaman</th><th>Kategori</th></tr></thead>
                    <tbody>
                        @foreach ($changes['removed'] as $item)
                            <tr><td class="font-medium">{{ $item['name'] }}</td><td>{{ $item['brand'] }}</td><td>{{ $item['category'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($excluded)
        <section class="table-shell mt-4">
            <div class="table-head">
                Dicentang tetapi tidak ikut terbit ({{ count($excluded) }})
                <span class="ml-2 text-xs font-normal text-slate-500">betulkan bila menu ini seharusnya tampil</span>
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Menu</th><th>SKU</th><th>Alasan</th><th>Saran perbaikan</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($excluded as $row)
                            <tr>
                                <td class="font-medium">{{ $row['product']->name }}</td>
                                <td>{{ $row['product']->sku ?: '-' }}</td>
                                <td class="text-amber-700">{{ $row['reason'] }}</td>
                                <td class="text-xs text-slate-600">{{ \App\Services\WebsiteMenuWarnings::TYPES[$row['type']][1] }}</td>
                                <td>
                                    <a class="underline text-xs" href="{{ route('marketingapp.catalog.index', ['q' => $row['product']->sku ?: $row['product']->name, 'aktif' => 0]) }}">Buka di Katalog</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @foreach ($draft['brands'] as $brand)
        <section class="wp-brand">
            <h2>{{ $brand['label'] }}</h2>
            <p>{{ number_format($brand['count'], 0, ',', '.') }} menu · halaman /{{ $brand['page'] }}</p>

            @foreach ($brand['categories'] as $category)
                <div class="wp-group">
                    <h3>{{ $category['title'] }} <span class="text-xs font-normal text-slate-500">({{ count($category['items']) }})</span></h3>
                    <p class="wp-desc">{{ $category['description'] }}</p>

                    @if ($category['items'])
                        <div class="wp-grid">
                            @foreach ($category['items'] as $item)
                                @php
                                    $itemMarks = $marks[$item['id']] ?? [];
                                    $renamed = collect($changes['renamed'])->firstWhere('id', $item['id']);
                                @endphp
                                <div @class(['wp-card', 'is-changed' => $itemMarks])>
                                    <div class="wp-photo">
                                        @if ($item['photo'] && ($photos[$item['id']] ?? null))
                                            <img src="{{ $photos[$item['id']] }}" alt="{{ $item['name'] }}" loading="lazy">
                                        @else
                                            <span class="empty">Belum ada foto — tampil tanpa foto</span>
                                        @endif
                                    </div>
                                    @if ($itemMarks)
                                        <div class="wp-marks">
                                            @foreach ($itemMarks as $mark)
                                                <span class="wp-mark">{{ $mark }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="wp-name">
                                        {{ $item['name'] }}
                                        @if ($renamed)
                                            <span class="wp-old">{{ $renamed['old_name'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="wp-empty-group">Belum ada menu di kategori ini; kelompok ini tidak tampil di website.</p>
                    @endif
                </div>
            @endforeach
        </section>
    @endforeach
@endsection
