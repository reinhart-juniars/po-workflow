@extends('layouts.shell', ['title' => 'Hasil Pencarian'])

@section('content')
    <section class="sh-search-page">
        <h1 class="sh-search-page-title">Hasil Pencarian</h1>

        {{-- Kotak milik halaman ini sendiri. Bukan hiasan: tombol cari di bilah
             atas membuka overlay lewat JavaScript, dan tanpa JavaScript tombol
             itu hanya menautkan ke halaman ini -- form inilah jalur
             pencariannya. --}}
        <form method="GET" action="{{ route('search.index') }}" role="search" class="sh-search-page-form">
            <label class="sh-search-field">
                <span class="sr-only">Cari dokumen</span>
                @svg('heroicon-o-magnifying-glass', 'sh-search-icon')
                <input type="search" name="q" value="{{ $term }}" maxlength="100"
                       placeholder="Cari PO, DO, pelanggan, menu, bahan…" autofocus>
            </label>
            <button type="submit" class="btn-primary">Cari</button>
        </form>

        @if ($term === '')
            <p class="sh-search-page-note">Ketik kata kunci lalu tekan Cari.</p>
        @elseif (mb_strlen($term) < $minLength)
            <p class="sh-search-page-note">Kata kunci minimal {{ $minLength }} huruf.</p>
        @else
            <p class="sh-search-page-note">
                Kata kunci <strong>{{ $term }}</strong> —
                {{ $groups->flatten(1)->count() }} hasil yang boleh Anda buka.
            </p>
        @endif

        @forelse ($groups as $group => $rows)
            <section class="sh-search-page-group">
                <h2>{{ $group }}</h2>
                <ul>
                    @foreach ($rows as $row)
                        <li>
                            <a href="{{ $row['url'] }}">
                                @svg($row['icon'], 'sh-search-page-icon')
                                <span>
                                    <strong>{{ $row['title'] }}</strong>
                                    @if ($row['meta'])
                                        <span>{{ $row['meta'] }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            @if (mb_strlen($term) >= $minLength)
                <p class="sh-search-page-empty">
                    Tidak ada dokumen yang cocok. Perlu diingat pencarian hanya menampilkan
                    dokumen yang boleh dibuka oleh peran Anda.
                </p>
            @endif
        @endforelse
    </section>
@endsection
