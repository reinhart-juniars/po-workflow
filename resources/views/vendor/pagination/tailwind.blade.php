{{--
    Pagination app Blade (menggantikan tampilan bawaan Laravel).

    Bawaan Laravel memakai kelas dark:* dan sm:*; berkasnya di vendor/ sehingga
    tidak ikut dipindai Tailwind. Akibatnya hanya sebagian kelas yang ada di
    CSS: di Windows mode gelap tombol "Next" tampil teks terang di atas latar
    putih (terlihat buram/nonaktif), dan versi layar lebar tidak pernah muncul.
    App Blade hanya bertema terang, jadi tampilan ini tanpa kelas dark:*.
--}}
@if ($paginator->hasPages())
    @php
        $btn = 'relative inline-flex items-center border border-gray-300 bg-white px-3 py-2 text-sm font-medium leading-5';
        $aktif = $btn.' text-gray-700 transition hover:bg-gray-50 hover:text-brand-600 focus:z-10 focus:outline-none focus:ring-2 focus:ring-brand-500';
        $mati = $btn.' cursor-default text-gray-400';
    @endphp

    <nav role="navigation" aria-label="Navigasi halaman" class="flex items-center justify-between gap-3">
        {{-- Layar sempit: Sebelumnya / Berikutnya saja. --}}
        <div class="flex flex-1 justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="{{ $mati }} rounded-md">&laquo; Sebelumnya</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $aktif }} rounded-md">&laquo; Sebelumnya</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $aktif }} rounded-md">Berikutnya &raquo;</a>
            @else
                <span aria-disabled="true" class="{{ $mati }} rounded-md">Berikutnya &raquo;</span>
            @endif
        </div>

        {{-- Layar lebar: ringkasan + nomor halaman. --}}
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-3">
            <p class="text-sm leading-5 text-gray-600">
                @if ($paginator->firstItem())
                    Menampilkan <span class="font-medium text-gray-800">{{ $paginator->firstItem() }}</span>–<span class="font-medium text-gray-800">{{ $paginator->lastItem() }}</span>
                @else
                    Menampilkan {{ $paginator->count() }}
                @endif
                dari <span class="font-medium text-gray-800">{{ $paginator->total() }}</span> data
            </p>

            <span class="relative z-0 inline-flex rounded-md shadow-sm">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="Sebelumnya" class="{{ $mati }} rounded-l-md">&laquo;</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya" class="{{ $aktif }} rounded-l-md">&laquo;</a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-disabled="true" class="{{ $mati }} -ml-px">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="{{ $btn }} -ml-px z-10 cursor-default border-brand-500 bg-brand-50 text-brand-700">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="Halaman {{ $page }}" class="{{ $aktif }} -ml-px">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Berikutnya" class="{{ $aktif }} -ml-px rounded-r-md">&raquo;</a>
                @else
                    <span aria-disabled="true" aria-label="Berikutnya" class="{{ $mati }} -ml-px rounded-r-md">&raquo;</span>
                @endif
            </span>
        </div>
    </nav>
@endif
