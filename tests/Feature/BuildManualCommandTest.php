<?php

/**
 * Panduan Pengguna dibangun dari HTML di docs/panduan; perintah ini yang
 * dipakai untuk memperbaruinya setiap kali panduannya disunting.
 */
it('membangun panduan pengguna pdf dari sumber html', function () {
    $output = storage_path('app/panduan-uji.pdf');
    @unlink($output);

    $this->artisan('panduan:pdf', ['--output' => $output])->assertSuccessful();

    expect(is_file($output))->toBeTrue()
        ->and(filesize($output))->toBeGreaterThan(50_000)
        ->and(file_get_contents($output))->toStartWith('%PDF');

    // Isi sumbernya memang panduan sistem ini, bukan berkas kosong.
    $html = file_get_contents(base_path('docs/panduan/panduan-3s-one.html'));
    expect($html)->toContain('Form Kebutuhan')->toContain('Supervisor Gudang')->toContain('Kartu Stok');

    @unlink($output);
});
