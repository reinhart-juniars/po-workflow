<?php

use function Pest\Laravel\artisan;

/**
 * Pagar pengaman perintah db:clone-to-staging.
 *
 * Perintah ini menimpa seluruh isi database tujuan, jadi yang diuji di sini
 * adalah keadaan-keadaan di mana ia HARUS menolak berjalan. Jalur sukses
 * (dump + restore + verifikasi jumlah baris) menuntut driver MySQL sedangkan
 * suite ini berjalan di SQLite, jadi jalur itu diverifikasi terhadap database
 * staging sungguhan, bukan di sini.
 */
it('menolak menyalin ke database yang sama dengan sumber', function () {
    $source = DB::connection()->getDatabaseName();

    artisan('db:clone-to-staging', ['--target' => $source, '--force' => true])
        ->expectsOutputToContain('Database tujuan sama dengan sumber')
        ->assertFailed();
});

it('meneruskan ke konfirmasi ketika tujuan berbeda dari sumber', function () {
    // Positive control untuk pagar di atas: dengan tujuan yang berbeda,
    // perintah harus lolos pemeriksaan dan benar-benar sampai ke konfirmasi.
    artisan('db:clone-to-staging', ['--target' => 'database_tujuan_lain'])
        ->expectsConfirmation('Seluruh isi database_tujuan_lain akan ditimpa. Lanjutkan?', 'no')
        ->expectsOutputToContain('Dibatalkan.')
        ->assertFailed();
});

it('menolak berjalan di environment production', function () {
    app()->detectEnvironment(fn () => 'production');

    artisan('db:clone-to-staging', ['--target' => 'apa_pun', '--force' => true])
        ->expectsOutputToContain('tidak boleh dijalankan di environment production')
        ->assertFailed();
});
