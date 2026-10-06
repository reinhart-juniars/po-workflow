<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Pagination app Blade memakai tampilan sendiri (resources/views/vendor/pagination),
 * bukan bawaan Laravel yang tombolnya tampak buram di Windows mode gelap.
 */
beforeEach(function () {
    // Tes Livewire/Filament sebelumnya mengganti view default paginator secara
    // statis dan nilainya terbawa antar-tes; kembalikan ke bawaan Laravel.
    Paginator::defaultView('pagination::tailwind');
});

function halamanUji(int $page): string
{
    return (new LengthAwarePaginator(range(1, 10), 45, 10, $page, ['path' => '/uji']))->links()->toHtml();
}

it('menampilkan tombol Berikutnya yang aktif tanpa kelas mode gelap', function () {
    $html = halamanUji(1);

    expect($html)
        ->toContain('href="/uji?page=2" rel="next"')
        ->toContain('Berikutnya &raquo;')
        ->toContain('Menampilkan <span class="font-medium text-gray-800">1</span>')
        ->toContain('dari <span class="font-medium text-gray-800">45</span> data')
        ->not->toContain('dark:')
        ->not->toContain('Next');
});

it('menonaktifkan tombol Berikutnya hanya di halaman terakhir', function () {
    // Kontrol positif: halaman 4 masih punya tautan berikutnya.
    expect(halamanUji(4))->toContain('rel="next"');

    expect(halamanUji(5))
        ->not->toContain('rel="next"')
        ->toContain('<span aria-disabled="true" aria-label="Berikutnya"')
        ->toContain('href="/uji?page=4" rel="prev"');
});
