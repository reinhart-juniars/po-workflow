<?php

/*
|--------------------------------------------------------------------------
| Website www.w3scatering.com (Bagian C)
|--------------------------------------------------------------------------
|
| Website adalah situs statis yang dibangun ulang dari data 3S ONE: saat
| marketing menekan "Terbitkan ke Website", 3S ONE memanggil Deploy Hook
| hosting, lalu skrip build website (tools/sync-menu.mjs) mengambil menu dari
| endpoint ber-token /api/website/menu dan menulis ulang halaman katalog.
|
| Kategori di bawah adalah kelompok menu di halaman brand. Urutannya = urutan
| tampil di website. `filter` = tombol saring di halaman Warung Wuenak Poll
| (beberapa kelompok boleh berbagi satu tombol). Brand menu diturunkan dari
| kolom is_3s: menu 3S tampil di halaman 3S, sisanya di Warung Wuenak Poll.
|
*/

return [

    // Token yang dikirim skrip build website (header Authorization: Bearer ...).
    // Kosong = endpoint menolak semua permintaan.
    'api_token' => env('WEBSITE_API_TOKEN'),

    // URL Deploy Hook dari hosting (Cloudflare Pages / Vercel). Kosong = tombol
    // Terbitkan menjelaskan bahwa website belum tersambung.
    'deploy_hook_url' => env('WEBSITE_DEPLOY_HOOK_URL'),

    // Alamat website, untuk tautan "Lihat website" setelah terbit.
    'url' => env('WEBSITE_URL', 'https://www.w3scatering.com'),

    'brands' => [
        'wwp' => ['label' => 'Warung Wuenak Poll', 'page' => 'wuenak-poll'],
        '3s' => ['label' => 'Kuliner Tradisional 3S', 'page' => '3s'],
    ],

    'categories' => [
        'nasi-box' => [
            'brand' => 'wwp',
            'title' => 'Nasi Box Wuenak Poll',
            'filter' => 'ayam',
            'description' => 'Nasi box dengan kemasan bermerek — pilihan paling sering diambil untuk meeting dan training kantor.',
        ],
        'ayam' => [
            'brand' => 'wwp',
            'title' => 'Menu Ayam',
            'filter' => 'ayam',
            'description' => 'Pilihan paling aman untuk acara dengan peserta beragam.',
        ],
        'telur' => [
            'brand' => 'wwp',
            'title' => 'Menu Telur & Sayur',
            'filter' => 'telur',
            'description' => 'Cocok untuk menekan budget per pax, dan tersedia opsi tanpa daging.',
        ],
        'ikan' => [
            'brand' => 'wwp',
            'title' => 'Menu Ikan & Seafood',
            'filter' => 'ikan',
            'description' => 'Alternatif untuk peserta yang menghindari ayam, atau untuk variasi menu mingguan.',
        ],
        'goreng' => [
            'brand' => 'wwp',
            'title' => 'Nasi Goreng, Mie & Bihun',
            'filter' => 'goreng',
            'description' => 'Pilihan praktis untuk konsumsi pagi, coffee break, atau acara santai.',
        ],
        'inter' => [
            'brand' => 'wwp',
            'title' => 'Internasional & Rice Bowl',
            'filter' => 'inter',
            'description' => 'Untuk acara anak muda, kegiatan kemahasiswaan, atau saat ingin tampil beda.',
        ],
        'premium' => [
            'brand' => '3s',
            'title' => 'Nasi Premium & Signature',
            'filter' => 'premium',
            'description' => 'Hidangan porsi lengkap untuk tamu penting, jamuan direksi, atau acara khusus.',
        ],
        'frozen' => [
            'brand' => '3s',
            'title' => 'Frozen Food',
            'filter' => 'frozen',
            'description' => 'Tahan lama dan bisa dikirim untuk stok — cocok untuk hampers atau oleh-oleh perusahaan.',
        ],
        'tumpeng' => [
            'brand' => '3s',
            'title' => 'Tumpeng & Hidangan Acara',
            'filter' => 'tumpeng',
            'description' => 'Untuk syukuran kantor, peresmian, ulang tahun perusahaan, dan acara keluarga.',
        ],
    ],

];
