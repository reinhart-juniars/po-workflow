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

    // Pencarian global ikut terdokumentasi -- termasuk peringatan bahwa
    // hasilnya disaring per peran, yang kalau hilang akan memancing laporan
    // "dokumen saya tidak ketemu" yang sebenarnya perilaku benar.
    expect($html)->toContain('Cari dokumen')
        ->toContain('Ctrl + K')
        ->toContain('boleh Anda buka');

    // v3.1: Master Supplier dan cara menyaring (ikon corong + chip) ikut
    // terdokumentasi, begitu juga gambar-gambarnya.
    expect($html)->toContain('Master Supplier')
        ->toContain('Menyaring daftar dan laporan')
        ->toContain('img/filter-popover.jpg')
        ->toContain('img/master-supplier.jpg');

    @unlink($output);
});
