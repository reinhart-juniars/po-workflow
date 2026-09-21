# UAT — Modul Inventory Terpadu (Bagian A, Phase 1–4)

Diisi bersama Owner W3S Catering di server produksi/staging setelah migrasi data.
Setiap baris: pelaku menjalankan langkah, pemeriksa mencatat hasil (✓ / ✗ + catatan).
Bagian A dinyatakan selesai (Termin 2) bila semua baris **Wajib** ✓ dan tidak ada ✗ terbuka.

Akun uji: satu per peran (`owner`, `admin`, `accounting`, `production`). Jangan memakai
akun pribadi Owner untuk uji tolakan akses.

Legenda kolom "Wajib": W = wajib untuk serah terima, O = opsional/dicatat saja.

## A. Akses & navigasi (Phase 4)

| # | Langkah | Hasil yang diharapkan | W/O | ✓/✗ | Catatan |
|---|---|---|---|---|---|
| A1 | Login `production` (satu halaman login untuk semua) | Mendarat di Dashboard Produksi; bilah atas hanya Inventory & Production; di Inventory sidebar tanpa Pengaturan | W | | |
| A2 | `production` buka Inventory → Item Inventaris → Tambah | Ditolak (403 / tombol tidak ada) | W | | |
| A3 | `accounting` buka Form Kebutuhan draft | Tidak ada tombol Susun/Simpan/Setujui | W | | |
| A4 | `admin` buka form yang sama | Tombol Setujui ada | W | | |
| A5 | `admin` buka Inventory → Pengaturan Inventory | Ditolak; `owner` bisa | W | | |
| A6 | `owner` buka Pengeluaran (Accounting) lalu klik tab Inventory di bilah atas | Bilah aplikasi sama di kedua halaman (tab aktif berpindah dengan animasi), sidebar berganti ke menu Inventory: Inventory, Resep & HPP, Produksi, Sistem | W | | |
| A7 | Klik `/admin/...` lama atau menu lama Master Item di Akunting | Mendarat di halaman inventory yang sesuai | O | | |

## B. Inventory (Phase 1)

| # | Langkah | Hasil yang diharapkan | W/O | ✓/✗ | Catatan |
|---|---|---|---|---|---|
| B1 | Item Inventaris: cari bahan hasil migrasi (mis. "Beras") | Ada, induk = bucket Bahan Baku, harga satuan terisi | W | | |
| B2 | Tambah bahan baru dengan induk, satuan, harga kemasan | Harga satuan terhitung otomatis dari harga kemasan / isi | W | | |
| B3 | Export Excel Item Inventaris | File terunduh, kolom induk/kelompok/harga ada | O | | |
| B4 | Import Excel yang sama tanpa perubahan | Tidak ada perubahan data (idempoten) | O | | |
| B4a | Ubah harga satuan bahan di panel → tab Histori Harga | Baris baru: lama → baru, sumber "panel", siapa & kapan | W | | |
| B4b | Stock Opname / Saldo Awal: Export → ubah nilai di Excel → Import | Nilai berubah; baris item tak dikenal menolak seluruh berkas | W | | |
| B5 | Pembelian Bahan Baku: catat pembelian, tautkan ke Form Kebutuhan | Pembelian tampil di form terkait | W | | |
| B6 | Laporan Mutasi Stok periode berjalan | Kartu "Pemakaian Resep" tampil di samping residual; catatan menyebut sumber HPP aktif | W | | |
| B7 | Login sebagai admin saat ada bahan di bawah ambang | Notifikasi "N bahan di bawah stok minimum" muncul sekali + widget dashboard + badge menu | W | | |

## C. Resep, konversi, pencocokan (Phase 2)

| # | Langkah | Hasil yang diharapkan | W/O | ✓/✗ | Catatan |
|---|---|---|---|---|---|
| C1 | Resep & Menu: buka resep migrasi (mis. "Nasi Goreng Ikan Asin") → Analisa HPP | HPP per porsi, OHC, profit, rincian bahan; baris bermasalah ditandai merah dengan alasan | W | | |
| C2 | Konversi Satuan → Butuh Aturan: pilih pasangan teratas → "Buat aturan" | Form terisi (bahan, dari, ke; faktor usulan bila nama mengandung ukuran kemasan) → simpan | W | | |
| C3 | Kembali ke HPP resep yang memakai bahan itu | Baris merah hilang, HPP naik sesuai | W | | |
| C4 | Bahan Belum Cocok: tautkan satu nama ke bahan yang ada | Status Ditautkan; baris resep yang memakainya kini terhubung (cek di resep) | W | | |
| C5 | Bahan Belum Cocok: "Buat bahan" untuk satu nama | Bahan baru muncul di Item Inventaris di bawah bucket | W | | |
| C6 | Jalankan ulang migrasi (staging) lalu cek C4 | Tautan manual tetap ada | O | | |
| C7 | Export resep → ubah satu qty di Excel → import | Baris berubah; file dengan bahan tak dikenal ditolak utuh | O | | |
| C8 | Tambah resep baru | OHC/profit bawaan sesuai Pengaturan (40% / 25% bila belum diubah) | W | | |
| C9 | Pencocokan Menu: baris teratas → Tautkan (usulan sudah terpilih) | Status Sudah tertaut; di Admin App → Master Menu produk itu menampilkan "Resep: …"; KPI "Porsi tercakup" naik | W | | |
| C10 | Pencocokan Menu: dua varian harga (mis. 10K & 12K) ditautkan ke resep yang sama | Keduanya tertaut; di form resep kolom Produk yang Dijual memuat keduanya | W | | |
| C11 | Pencocokan Menu: EXTRA / HARGA UP → Tanpa Resep (bisa massal) | Hilang dari daftar kerja; filter Status = Tanpa resep menampilkannya; Lepas mengembalikan | W | | |
| C12 | Pencocokan Menu: produk paket tanpa resep → Buat Resep | Form resep terbuka dengan nama terusul & produk terpilih; setelah simpan produk tertaut | O | | |
| C13 | Login `accounting` buka Pencocokan Menu | Daftar terbaca, tombol Tautkan/Tanpa Resep/Import tidak ada; Export tetap ada | O | | |
| C14 | Pencocokan Menu → Export Excel; isi `terima_usulan` = ya pada 2 baris, `tanpa_resep` = ya pada 1 baris, sisanya biarkan → Import Excel | Notifikasi "2 ditautkan, 1 tanpa resep, N tidak berubah"; produk lain tidak berubah; tautan lama tidak lepas | W | | |
| C15 | Import berkas dengan satu `resep_id` salah | "Import dibatalkan" dengan nomor baris; tidak ada baris lain yang tersimpan | W | | |

## D. Produksi & Form Kebutuhan (Phase 3)

| # | Langkah | Hasil yang diharapkan | W/O | ✓/✗ | Catatan |
|---|---|---|---|---|---|
| D1 | Admin App → SPK → buat slot dari PO draft | Pesan sukses menyebut SPK Produksi SPKP-… ikut tersusun; di Inventory → SPK Produksi ada baris per item PO, produk tanpa resep ditandai | W | | |
| D1a | Production App → Dashboard → tombol "Form Kebutuhan SPKP-…" (atau "Susun Form Kebutuhan" untuk slot lama) | Membuka Form Kebutuhan slot itu di Inventory; di SPK Produksi kolom "Slot SPK & PO" menautkan balik ke PO di Admin App | W | | |
| D2 | Edit SPK → Segarkan dari PO setelah PO berubah | Baris mengikuti PO; baris manual tidak hilang | W | | |
| D3 | Lembar Kerja → Salin dari Template Menu | Pekerjaan per resep terisi; bisa ubah PIC/jam | W | | |
| D4 | Plating | Komponen per menu tampil; Cetak PDF terunduh | W | | |
| D5 | Form Kebutuhan → Susun Form (`production`) | Baris per bahan: Kebutuhan, Stok Awal kosong, Beli = usulan; masalah konversi disebutkan | W | | |
| D6 | Isi Stok Awal semua baris → Simpan → muat ulang | Isian tersimpan; Beli menyesuaikan (Kebutuhan − Stok Awal) | W | | |
| D6a | Ajukan ke Supervisor Gudang (`production`) | Status Diajukan; isian terkunci (tombol Simpan hilang); supervisor gudang (`inventory-supervisor`) menerima lonceng "menunggu persetujuan"; dashboard Inventory supervisor menampilkan "Form menunggu persetujuan (1)" | W | | |
| D6b | Tolak dengan alasan (`inventory-supervisor`) | Status kembali Dibuat; produksi melihat banner merah "Ditolak supervisor gudang — nama · waktu: alasan" + lonceng; tombol Setujui/Tolak **tidak** tampil untuk `production` dan `inventory` | W | | |
| D6c | Produksi memperbaiki → Ajukan lagi | Banner penolakan hilang; Diajukan lagi | W | | |
| D7 | Setujui (`inventory-supervisor`; `admin`/`owner` cadangan) | Status Disetujui; Stok Awal & Beli terkunci; staf gudang (`inventory`) menerima lonceng "siap dibelanjakan"; Setujui **tidak** bisa dilakukan pada form yang belum diajukan | W | | |
| D7a | Penerimaan barang (`inventory`/`accounting`/`admin`): isi Diterima lebih kecil dari Beli untuk satu bahan, Alasan Ditolak, Perlakuan (Retur / Dibayar), Harga Beli dari nota, dan cara pembayaran (Tunai: kategori + akun kas; Kredit: supplier + jatuh tempo) → Simpan | Tersimpan; Ditolak = Beli − Diterima tampil otomatis; peran `production` tidak bisa menyimpan di tahap ini | W | | |
| D7b | Periksa tanpa alasan tolak / harga beli / cara pembayaran | Ditolak dengan pesan "Belum bisa diperiksa: …" yang menyebut kekurangannya | W | | |
| D8 | Periksa (`inventory`/`accounting`/`admin`) | Status Diperiksa; Kartu Stok berisi opening (hanya bahan tanpa riwayat) + purchase **sejumlah Diterima dengan Harga Beli**; Pembelian Bahan Baku dibuat otomatis per bahan (tertaut ke form) beserta Kas Keluar / Hutang senilai sama; barang ditolak-**Dibayar** menjadi pembelian Tidak Baik (Kerugian Barang Rusak), ditolak-**Retur** tidak dibayar; harga master bahan mengikuti Harga Beli (histori harga: "Form FKB-…") bila pengaturannya aktif | W | | |
| D9 | Cetak Form | PDF dengan kolom tanda tangan | W | | |
| D10 | Isi Pemakaian Aktual & Sisa Stok → Tutup SPK (`production`) | Status SPK selesai; Kartu Stok berisi usage (negatif) + adjustment; notifikasi nilai pemakaian | W | | |
| D11 | Coba Tutup SPK lagi / Periksa lagi | Ditolak (tidak bisa diulang) | W | | |
| D12 | Perbandingan HPP, periode tanggal produksi | Kolom Pemakaian Resep & Penyesuaian terisi; residual opname di sampingnya | W | | |
| D13 | Pengaturan: aktifkan "Wajib isi Sisa Stok" → Tutup SPK lain tanpa sisa | Ditolak dengan pesan jumlah bahan yang belum diisi | O | | |
| D14 | Pengaturan: aktifkan "Bulatkan usulan Beli" → Susun form baru | Beli bilangan bulat | O | | |

## E. Data migrasi (Phase 4)

| # | Langkah | Hasil yang diharapkan | W/O | ✓/✗ | Catatan |
|---|---|---|---|---|---|
| E1 | `php artisan inventory:validate-migration` | 0 error; baris "sumber vs tujuan" sama | W | | |
| E2 | Bandingkan 5 resep acak: HPP di Master Menu vs Analisa HPP di po-workflow | Sama, atau selisih dijelaskan oleh konversi/mismatch yang belum diputuskan | W | | |
| E3 | Bandingkan 5 bahan acak: harga kemasan & isi | Sama | W | | |
| E4 | SPK riwayat Master Menu (17) tampil di SPK Produksi tab Selesai | Ada, tidak bisa diedit | O | | |
| E5 | Perbandingan HPP resep vs residual opname untuk 2 periode lampau | Angka dicatat, dibahas; Owner memutuskan kapan opname berhenti jadi sumber utama | W | | |
| E6 | Setelah disetujui: Pengaturan → "Sumber pemakaian bahan untuk HPP" = Resep x produksi | Laporan Laba Rugi "Bahan Baku Terpakai" & Laporan Pemakaian Bahan berganti ke angka resep; residual tetap tampil sebagai pembanding | W | | |

## G. Bagian B — kebutuhan tambahan

| No | Langkah | Hasil yang diharapkan | Peran | Hasil | Catatan |
|---|---|---|---|---|---|
| G1 | Ubah harga satu bahan di Item Inventaris (`admin`) | Owner & accounting menerima lonceng "Harga bahan X naik/turun N%" berisi harga lama → baru dan sumbernya; admin (pelaku) tidak; lonceng tampil di header Owner app maupun Inventory | W | | |
| G2 | Ubah harga jual satu menu di Admin › Master Menu | Lonceng "Harga jual X naik/turun N%" ke pemegang izin notifikasi harga; tombol Buka membawa ke halaman edit menu | W | | |
| G3 | Pengaturan Inventory › Profit Menu: set batas bawah/atas (mis. 20 / 60) → `php artisan profit:check` | Dashboard Inventory menampilkan "Profit menu keseluruhan" + rentang; lonceng ke owner/admin hanya saat keadaan berubah (normal ↔ di bawah ↔ di atas), tidak berulang bila tetap | W | | |
| G4 | Inventory › Resep & HPP › Menu Tidak Diproduksi | Daftar menu aktif tanpa SPK Produksi dalam rentang (bawaan 3 bulan, dari Pengaturan), kolom terakhir diproduksi & terakhir terjual, export Excel; `sales` tidak bisa membuka | W | | |
| G5 | Admin › Master Data › Katalog Foto Menu: unggah JPG/PNG/WEBP ≤ 5 MB untuk satu menu, ganti, hapus | Foto tampil dengan SKU & harga; foto tersimpan sebagai JPG maks. 1600 px (foto HP 5 MB jadi ±150-300 KB, foto potret tetap tegak); berkas lama terhapus saat diganti; PDF ditolak; Sales › Marketing › Katalog Foto Menu hanya melihat (tanpa tombol unggah) | W | | |

## F. Paralel HPP (definition of done Phase 3)

Diisi tiap akhir bulan selama masa paralel (minimal 2 periode).

| Periode | Residual opname (Bahan Baku) | Pemakaian resep + penyesuaian | Selisih | Penjelasan | Disetujui Owner |
|---|---|---|---|---|---|
| | | | | | |
| | | | | | |

## Tanda tangan

| Peran | Nama | Tanggal | Tanda tangan |
|---|---|---|---|
| Owner W3S Catering | | | |
| Admin | | | |
| Pengembang | Reinhart Juniars | | |
