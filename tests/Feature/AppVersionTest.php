<?php

use App\Support\AppVersion;

/**
 * Versi sistem tampil di halaman masuk dan diambil dari baris terakhir
 * version.txt, berkas riwayat rilis yang dirawat manual.
 */
it('menampilkan versi dari baris terakhir version.txt di halaman masuk', function () {
    AppVersion::flush();

    $lines = array_values(array_filter(array_map('trim', file(base_path('version.txt'))), fn ($l) => $l !== ''));
    expect(end($lines))->toMatch('/^v\.?\d+(\.\d+)*/');

    $label = AppVersion::label();
    expect($label)->toMatch('/^v\d+(\.\d+)*[a-z]?$/');

    $this->get('/login')->assertOk()->assertSee('3S ONE '.$label);
});

it('menaikkan versi cukup dengan menambah baris di version.txt', function () {
    $path = base_path('version.txt');
    $asli = file_get_contents($path);

    try {
        file_put_contents($path, $asli."\nv.9.9.9 rilis uji\n");
        AppVersion::flush();

        expect(AppVersion::current())->toBe('9.9.9');
        $this->get('/login')->assertSee('v9.9.9');
    } finally {
        file_put_contents($path, $asli);
        AppVersion::flush();
    }
});
