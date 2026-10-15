<?php

/**
 * Panduan Pengguna dibangun dari HTML di docs/panduan; perintah ini yang
 * dipakai untuk memperbaruinya setiap kali panduannya disunting.
 */
it('membangun panduan pengguna pdf dari sumber html', function () {
    $output = storage_path('app/panduan-uji.pdf');
    @unlink($output);

    // Batas memori CLI bawaan (128 MB) tidak cukup untuk panduan 120+ halaman
    // bergambar; perintahnya sendiri yang menaikkan batas, bukan penggunanya.
    $previousLimit = ini_get('memory_limit');
    ini_set('memory_limit', '128M');

    try {
        $this->artisan('panduan:pdf', ['--output' => $output])->assertSuccessful();
        expect(ini_get('memory_limit'))->toBe('1024M');
    } finally {
        ini_set('memory_limit', $previousLimit);
    }

    expect(is_file($output))->toBeTrue()
        ->and(filesize($output))->toBeGreaterThan(50_000)
        ->and(file_get_contents($output))->toStartWith('%PDF');

    // Isi sumbernya memang panduan sistem ini, bukan berkas kosong.
    $html = file_get_contents(base_path('docs/panduan/panduan-3s-one.html'));
    expect($html)->toContain('Form Kebutuhan')->toContain('Kartu Stok');

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
        ->toContain('img/master-supplier.jpg')
        // Hak akses per peran tertulis untuk pengguna; gudang satu peran
        // (Supervisor Gudang digabung ke Inventory, 12 Okt 2026).
        ->toContain('Hak akses rinci per peran')
        ->toContain('Satu peran untuk gudang: Inventory')
        ->not->toContain('Staf Gudang dan Supervisor Gudang: apa bedanya?');

    // v3.1.2 (revisi meeting 14 Okt): Rencana Masak sampai Barang Sisa, harga
    // lapak, saran rincian dari HPP resep, Laporan Stok, dan Terbitkan ke Website
    // -- dengan label yang sama persis dengan tombol di layar.
    expect($html)->toContain('Versi 3.1.2')
        ->toContain('15. Rencana Masak: stok siap untuk lapak')
        ->toContain('Ambil dari stok siap (Rencana Masak)')
        ->toContain('Tutup Stok Siap')
        ->toContain('Stok Siap Dapur (internal)')
        ->toContain('Impor Rencana Masak')
        ->toContain('Harga Lapak')
        ->toContain('Isi dari HPP resep')
        ->toContain('Export Laporan Stok')
        ->toContain('Export Data Master')
        ->toContain('Terbitkan ke Website')
        ->toContain('Dicentang, belum ada kategori')
        // Laporan stok gudang diimpor lewat pratinjau, bukan Import Excel.
        ->toContain('Impor Laporan Stok Gudang')
        ->toContain('Mirip — pilih dulu')
        ->toContain('Ini laporan stok gudang');
    expect(base_path('docs/panduan/img/alur-rencana-masak.jpg'))->toBeFile();

    @unlink($output);
});

/**
 * Panduan khusus pengguna Master Menu Revamp yang pindah ke aplikasi Inventory.
 */
it('membangun panduan inventory untuk pengguna master menu', function () {
    $output = storage_path('app/panduan-inventory-uji.pdf');
    @unlink($output);

    $this->artisan('panduan:pdf', ['panduan' => 'inventory-master-menu', '--output' => $output])->assertSuccessful();

    expect(is_file($output))->toBeTrue()
        ->and(filesize($output))->toBeGreaterThan(50_000)
        ->and(file_get_contents($output))->toStartWith('%PDF');

    $html = file_get_contents(base_path('docs/panduan/panduan-inventory-master-menu.html'));

    // Peta pindah: setiap menu Master Menu Revamp punya padanannya.
    expect($html)->toContain('Bahan Baku')->toContain('Item Inventaris')
        ->toContain('Pra SPK')->toContain('Breakdown Bahan')
        ->toContain('Mismatch')->toContain('Bahan Belum Cocok')
        ->toContain('Menu Plating')->toContain('Lembar Kerja');

    // Diagram alur ikut, dan berkas gambarnya memang ada (kalau hilang,
    // dompdf diam-diam mencetak kotak kosong).
    preg_match_all('/src="(img\/alur-[^"]+)"/', $html, $diagrams);
    // 5 diagram awal + alur Menu ke harga jual (revisi Okt 2026).
    expect($diagrams[1])->toHaveCount(6);
    foreach ($diagrams[1] as $diagram) {
        expect(base_path('docs/panduan/'.$diagram))->toBeFile();
    }

    @unlink($output);
});

/**
 * Rencana pelatihan Oktober 2026: tiga pertemuan menuju live 1 November.
 */
it('membangun rencana pelatihan dengan jadwal dan target tiap bagian', function () {
    $output = storage_path('app/panduan-pelatihan-uji.pdf');
    @unlink($output);

    $this->artisan('panduan:pdf', ['panduan' => 'rencana-pelatihan', '--output' => $output])->assertSuccessful();

    expect(is_file($output))->toBeTrue()
        ->and(file_get_contents($output))->toStartWith('%PDF');

    $html = file_get_contents(base_path('docs/panduan/panduan-rencana-pelatihan.html'));

    expect($html)->toContain('14 Oktober')->toContain('21 Oktober')->toContain('28 Oktober')->toContain('1 November')
        ->toContain('TIM MENU')->toContain('INVENTORY')->toContain('ADMIN')->toContain('ACCOUNTING');
    expect(base_path('docs/panduan/img/alur-pelatihan.jpg'))->toBeFile();

    // Versi ringkas untuk Owner: hanya materi pertemuan (termasuk 7 Okt) dan lembar progres.
    $this->artisan('panduan:pdf', ['panduan' => 'pelatihan-owner', '--output' => $output])->assertSuccessful();
    $owner = file_get_contents(base_path('docs/panduan/panduan-pelatihan-owner.html'));
    expect($owner)->toContain('Pertemuan 1 · Rabu 7 Oktober')->toContain('Siap live: diperiksa 28 Oktober')
        ->not->toContain('Target mingguan per bagian')->not->toContain('Sabtu 31 Oktober dan hari pertama');

    @unlink($output);
});

it('menolak nama panduan yang bukan nama berkas sederhana', function (string $nama) {
    $output = storage_path('app/panduan-tolak-uji.pdf');
    @unlink($output);

    $this->artisan('panduan:pdf', ['panduan' => $nama, '--output' => $output])->assertFailed();

    expect(is_file($output))->toBeFalse();

    // Kontrol positif: nama yang sah ke lokasi yang sama tetap berhasil.
    $this->artisan('panduan:pdf', ['panduan' => '3s-one', '--output' => $output])->assertSuccessful();
    expect(is_file($output))->toBeTrue();

    @unlink($output);
})->with(['../../.env', 'Inventory', 'tidak-ada']);
