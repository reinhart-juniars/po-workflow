# Bahasa desain 3S ONE

Aplikasi punya dua lapis UI: **panel Filament** (`/inventory`) dan **aplikasi
Blade** (Owner, Admin, Accounting, Sales, Production, Delivery). Keduanya
harus terasa satu produk. Acuannya adalah panel Filament Inventory: lapis
Blade meniru pola dan angkanya, bukan sebaliknya.

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
| Font | Public Sans |

## Skala

- **Radius:** 6 px (chip), 8 px (input, tombol ikon, popover, tooltip), 12 px (kartu).
- **Tinggi kontrol:** 36 px (kotak cari, tombol ikon, field popover).
- **Elevasi:** kartu `shadow-sm` + ring 1 px `slate-950/5`; popover `shadow-lg`.
  Selain itu datar.

## Komponen (mulai perubahan dari sini)

| Komponen | Berkas | Isi |
|---|---|---|
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
