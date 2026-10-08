# Bahasa desain 3S ONE

Aplikasi punya dua lapis UI: **panel Filament** (`/inventory` Inventory,
`/menu` Menu) dan **aplikasi Blade** (Owner, Admin, Accounting, Sales,
Marketing, Production, Delivery) plus halaman masuk. Semuanya harus terasa
satu produk. Acuannya adalah panel Filament: lapis Blade meniru pola dan
angkanya, bukan sebaliknya.

**Penyelarasan menyeluruh 7 Okt 2026** (revisi pasca presentasi): kepala
halaman, tombol, kartu, tabel, badge, stat, field, dan halaman masuk Blade
disamakan dengan angka yang **diukur** dari panel (computed style, bukan
tebakan). Diubah di kelas bersama `resources/css/app.css`, jadi ±30 halaman
ikut sekaligus; sisa gaya inline disapu dengan skrip.

## Acuan dan alasannya

- **Acuan:** tabel panel Filament di `/inventory` (Filament v3), dipilih
  pemilik produk sebagai tampilan yang "simple dan modern".
- **Kenapa cocok:** sistem back-office yang padat data, dipakai di layar
  laptop terang. Filament memang dirancang untuk kerja seperti ini, dan token
  lapis Blade sudah diturunkan dari panel (brand `#3455db`, abu slate, Public
  Sans; lihat `tailwind.config.js`), jadi tidak perlu sistem warna baru.
- **Yang tidak dipakai:** katalog referensi luar (refero, aura, dst.). Acuan
  internal lebih kuat: angkanya bisa diukur dari halaman yang sudah hidup.

## Token

| Peran | Nilai |
|---|---|
| Brand | `brand-500` `#3455db` (tombol utama), `brand-600` `#2f4dc5` (teks chip, badge, fokus), `brand-50` `#f5f7fd` (latar chip) |
| Netral | Tailwind `slate` (teks `slate-950`, sekunder `slate-500`, garis `slate-200`, latar baris `slate-50`) |
| Bahaya | `rose-600` (Hapus, Atur ulang) |
| Font | Public Sans **saja** (Blade, Filament, halaman masuk, wordmark). Space Grotesk dihapus 7 Okt 2026 |
| Teks | utama `slate-950` `#020617`, sekunder `slate-500` |

## Skala

- **Radius:** 6 px (chip, badge), 8 px (input, tombol, popover, tooltip), 12 px (kartu).
  Di luar itu hanya lingkaran (avatar, titik grafik) dan tanda logo (16 px).
- **Tinggi kontrol:** 36 px untuk tombol, field, kotak cari, tombol ikon.
- **Elevasi:** kartu `shadow-sm` + ring 1 px `slate-950/5`; popover `shadow-lg`.
  Selain itu datar. Tanpa gradasi, kaca, atau garis aksen di sisi kartu.

## Angka acuan (diukur dari panel Filament)

| Elemen | Nilai | Kelas Blade |
|---|---|---|
| Judul halaman | 30/36 px, 700, −0.025em, `slate-950`, di kanvas (tanpa kartu) | `.dashboard-hero-title`, `.page-toolbar h1`, `.page-title` |
| Deskripsi halaman | 16/24 px (14 di HP), `slate-500` | `.dashboard-hero-subtitle`, `.page-toolbar h1 + .section-subtitle` |
| Tombol | tinggi 36, radius 8, 14/600, padding 8×12, `shadow-sm` | `.btn-base` → `.btn-primary` (brand-600, hover 500), `.btn-gray` (putih + ring 10%) |
| Kartu | radius 12, putih, ring 1 px 5% + `shadow-sm` | `.app-card` (dipakai `.section-card`, `.stat-card`, `.table-shell`, …) |
| Header tabel | 14/600 `slate-950`, kalimat biasa, padding 14×12 (kolom pertama 24) | `.data-table thead th` |
| Sel tabel | 14 px `slate-950`, padding 16×12, garis `slate-200` | `.data-table tbody td` |
| Badge | radius 6, 12/500, padding 4×8, ring inset 10–20% | `.badge-soft-*`, `.status-badge`, `.chip` |
| Stat | label 14/500 `slate-500`, nilai 28–30/600 −0.025em | `.stat-label`, `.stat-value`, `.metric-*` |
| Field | tinggi 36, radius 8, ring 10% + `shadow-sm`, fokus 2 px brand-600 | `input`, `select`, `textarea`, `.form-control`; label 14/500 `slate-950` |

Aturan warna tombol: **satu** aksi utama (brand) per area. Simpan = utama.
Export/Import = abu. Hijau hanya untuk konfirmasi final yang tidak bisa
diulang (Konfirmasi Pembayaran, Selesaikan Pengiriman, Submit sales actual).

## Komponen (mulai perubahan dari sini)

| Komponen | Berkas | Isi |
|---|---|---|
| Kelas dasar | `resources/css/app.css` (`@layer components`: `.btn-*`, `.app-card`, `.data-table`, `.badge-soft-*`, `.stat-*`, `.dashboard-hero*`, `.page-title`) | Angka di tabel acuan. Mulai perubahan apa pun dari sini. |
| Toolbar tabel | `resources/views/components/table-toolbar.blade.php` | Kotak cari, ikon filter berbadge, popover filter, chip "Filter aktif". Field filter ditulis sebagai **data** (`select`, `text`, `number`, `date`, `month`, `date-range`). |
| Aksi baris | `resources/views/components/row-action.blade.php` | Tombol ikon Edit/Hapus dengan tooltip; form hapus + konfirmasi sekaligus. |
| Transisi halaman | `resources/views/partials/page-transition.blade.php` | Geser konten antar menu, sidebar & bilah atas diam. |
| CSS | `resources/css/app.css` (bagian `.tt-*`, `.row-action*`) | Angka di atas. |

Di Filament, Edit/Hapus sebagai ikon diatur global di
`AdminPanelProvider::boot()`.

### Pola toolbar

- **Tabel daftar:** `<x-table-toolbar title="..." ...>` menggantikan
  `.table-card-head` di kepala kartu tabel.
- **Laporan/dashboard** (filter berlaku ke seluruh halaman): varian `inline`
  di hero; chip periode tetap terlihat di bawah judul.
- **Grid kartu** (Katalog): varian `standalone`.
- Filter wajib (mis. customer di Best Seller): `open` membuka popover saat
  halaman dimuat.

## Do / Don't

- **Do:** filter di popover ikon, chip "Filter aktif" untuk yang sedang
  berlaku; label chip memakai label opsi, bukan nilai mentahnya.
- **Do:** aksi baris sebagai ikon dengan tooltip; aksi utama halaman
  (Tambah, Export) tetap tombol berteks.
- **Do:** frame pertama halaman harus sudah utuh tanpa menunggu JavaScript
  (lihat `resources/views/filament/first-paint.blade.php`).
- **Don't:** kartu filter besar di atas tabel, tombol "Lihat/Filter" terpisah,
  atau preset tanggal yang ditulis ulang per halaman.
- **Don't:** susun nama kelas CSS secara dinamis (`'row-action-'.$tone`):
  Tailwind hanya menyertakan kelas yang tertulis utuh di berkas sumber.

## Penyimpangan yang disengaja

- **Laporan keuangan tetap padat.** Laba Rugi, Laporan Final, lembar
  Penjualan, Analisa HPP, dan Laporan Produksi memakai 10–12 px karena
  tabelnya selebar layar seperti lembar kerja. Yang disamakan hanya huruf
  kapital berspasi dan ketebalan (800 → 600). Dokumen cetak (`pdf/`,
  `*/exports/`, `*_pdf`) tidak disentuh: dompdf punya gayanya sendiri.
- **Master Menu sedikit lebih rapat** (13 px, padding 12×10) dari tabel
  standar karena 9 kolom; tetap kalimat biasa.
- **Halaman masuk = portal korporat, bukan halaman produk SaaS** (revisi
  9 Okt 2026). Ini sistem internal perusahaan: panel kiri biru brand gelap
  `#1e3080` solid berisi identitas (3S ONE, W3S Catering, "Sistem internal
  perusahaan", pemberitahuan akses terbatas); form di kanan (maks. 400 px,
  field & tombol 44 px, lihat/sembunyikan password, Caps Lock, "Memproses…",
  arahan reset lewat Owner). **Jangan menaruh angka, nama supplier, atau
  kartu data contoh di halaman masuk** — pengguna bisa mengira itu data
  perusahaan yang sebenarnya (versi pratinjau dashboard ditolak karena ini).
- **Tagline merek "Business Control System" tetap kapital berspasi.** Itu
  bagian logo, bukan label UI.
- **Detektor impeccable "gray-on-color"** pada `.row-action-*` dan
  `.role-builder-remove` adalah positif palsu: teksnya berubah warna saat
  hover bersama latarnya.

- **Popover diteleport ke `<body>`.** Kartu tabel memakai `overflow-hidden`
  dan `backdrop-filter`, yang memotong elemen `fixed` di dalamnya. Field
  popover tetap milik form lewat atribut `form="..."`.
- **Periode bawaan tidak bisa dilepas.** Filament menganggap nilai bawaan
  sebagai filter biasa; di sini banyak controller memberi periode bawaan
  (bulan berjalan), jadi chip-nya tampil sebagai keterangan. Periode yang
  dipilih pengguna (ada di URL) bisa dilepas.
- **Pencarian memuat ulang halaman** (Filament memakai Livewire). Kursor
  dikembalikan ke kotak cari, dan muat ulang halaman yang sama tidak
  memicu animasi geser.
- **Kartu form (Tambah Pengeluaran, dst.) tertutup bawaan**, dibuka lewat
  tombolnya. Filament memakai halaman/modal terpisah; tab yang bisa dibuka
  dan ditutup menjaga form lama tanpa membangun halaman baru.
- **Closing Penjualan tidak memakai toolbar.** Pemilih tanggalnya adalah
  langkah kerja utama, bukan filter daftar.
