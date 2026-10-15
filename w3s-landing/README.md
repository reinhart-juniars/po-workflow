# W3S Catering — Landing Page + Template Email

Landing page konversi untuk W3S Catering (CV. Sehati Sejiwa Sepemikiran / 3S),
dibangun dari `W3S_Sales_Proposal_1_(3).docx`, `isi email Rev 3.pdf`, dan
`Binder1Proof Menu Kuliner Tradisional_REV 3.pdf`.

HTML + CSS + JS statis, tanpa framework dan tanpa dependensi eksternal.

---

## Isi folder

```
w3s-landing/
├── index.html              Landing page utama (9 section + footer)
├── wuenak-poll.html        Halaman brand WWP — cerita, galeri, katalog 172 item
├── 3s.html                 Halaman brand 3S — cerita, galeri, 18 hidangan
├── robots.txt              Izinkan semua + penunjuk sitemap
├── sitemap.xml             3 URL (dibuat tools/make-seo.js)
├── favicon.svg             Wordmark W3S (+ favicon-32/512, apple-touch-icon)
├── README.md               Berkas ini
├── docs/DESIGN.md          Design system: referensi, token, penyimpangan
├── assets/
│   ├── css/style.css       Seluruh style semua halaman — titik ungkit utama
│   ├── js/app.js           Link WA, form penawaran, filter & pencarian menu
│   ├── js/motion.js        Parallax hero, tilt 3D, reveal, count-up
│   ├── fonts/              Petrona + Karla variabel (self-host, 74 KB)
│   ├── brand/              Logo Wuenak Poll + Kuliner Tradisional 3S
│   ├── hero/               10 foto tumpukan kartu hero index (5 per brand)
│   ├── wwp/                10 foto studio Warung Wuenak Poll
│   ├── 3s/                 6 foto studio Kuliner Tradisional 3S
│   ├── dish/               8 hidangan 3S (foto studio asli + tumpeng katalog)
│   ├── event/              Foto asli klien — hero tumpeng + bukti operasional
│   ├── menu/               138 thumbnail menu harian WWP
│   ├── logos/              30 logo klien (diekstrak dari .docx)
│   └── img/                og-image.jpg
└── (email/ dan _arsip-aset/ berada di luar folder deploy)
```

`_arsip-aset/` di root proyek menampung aset yang pernah dibuat tapi tidak lagi
dirujuk (QR code, foto operasional cadangan). Sengaja tidak dihapus, tapi juga
tidak ikut ter-publish. **Folder deploy harus selalu nol berkas yatim.**

**Sebelum mengubah tampilan, baca [docs/DESIGN.md](docs/DESIGN.md).** Di sana ada
referensi yang dipakai, token, dan daftar penyimpangan yang disengaja beserta
alasannya — supaya tidak ada yang "memperbaiki" keputusan desain yang sudah
dipertimbangkan.

---

## ⚠️ Harus diselesaikan sebelum online

### 1. ✅ Nomor WhatsApp — SUDAH DIPASTIKAN

Nomor yang benar: **+62 812 3577 3362** (12 digit). Dikonfirmasi klien.

Sudah diterapkan di seluruh berkas: `assets/js/app.js`, `index.html` (2 tempat),
`email/email.html` (2 tempat), `email/email.txt` (4 tempat). Sudah diverifikasi
tidak ada sisa nomor lama.

Catatan riwayat: proposal `isi email Rev 3.pdf` dan header image WWP menulis
`08123577362` (11 digit) — **itu keliru**. Yang benar adalah versi di katalog
menu dan foto Instagram. Sebaiknya materi cetak yang lama ikut dikoreksi.

Kalau suatu saat nomornya berubah, ubah satu baris di `assets/js/app.js`:

```js
var WA_NUMBER = '6281235773362';
```

lalu perbarui teks yang tampil di `index.html` dan `email/`.

### 2. Ganti domain

Semua tautan masih memakai placeholder `https://www.w3scatering.com/`. Perlu diganti di:

Selain canonical, domain juga dipakai di og:url, sitemap.xml, dan seluruh @id
JSON-LD. Untuk yang tiga terakhir cukup ubah konstanta DOMAIN di
tools/make-seo.js lalu jalankan ulang. Canonical tetap manual:

- `index.html` → `<link rel="canonical">`
- `wuenak-poll.html` → `<link rel="canonical">`
- `3s.html` → `<link rel="canonical">`
- `email/email.html` → tombol CTA (2 tempat)
- `email/email.txt` → bagian plain-text & follow-up

### 3. ✅ Gambar OG — SUDAH DIBUAT

`assets/img/og-image.jpg` — 1200×630, 166 KB. Inilah yang muncul saat link
di-share di WhatsApp, LinkedIn, atau Slack.

Isinya: judul utama dengan tipografi **Petrona wght 700** yang sama persis
dengan situs (teks di-render sebagai vector path langsung dari file font, jadi
tidak perlu memasang font ke sistem dan tidak perlu screenshot), tiga bukti
utama, dan **lockup dua brand** — jadi arsitektur brand sudah terbaca bahkan
sebelum orang membuka halamannya.

Foto yang dipakai: **tumpeng hero yang sama** dengan yang ada di halaman, supaya
thumbnail share dan halaman tujuannya terasa satu kesatuan. Latarnya `#1A0E0A`
(token `--ink`), bukan hitam murni — gradasi penyatunya juga memakai warna itu,
karena hitam murni akan terlihat sebagai kotak dingin di sisi foto yang hangat.

Skrip pembuatnya: **`../tools/make-og.js`** (sengaja di luar folder deploy
supaya tidak ikut ter-publish). Jalankan ulang kalau judul atau fotonya berubah:

```bash
cd tools && npm i sharp fontkit wawoff2 && node make-og.js
```

### 4. ⚠️ Harga di situs sekarang lebih rendah dari materi lama — perlu persetujuan klien

Nama folder foto mengungkap harga per item yang sebenarnya: **Rp10.000 (43 menu),
Rp12.000 (89 menu), Rp15.000 (5), Rp3.000 (1)**. Padahal proposal dan katalog PDF
menulis *"menu special mulai 15 ribu"*.

Karena itu klaim di situs sudah diselaraskan ke data foto:

| Tempat | Sebelumnya | Sekarang |
|---|---|---|
| Katalog menu | mulai Rp15.000 | mulai Rp10.000 |
| Layanan "Family" di beranda | mulai Rp15.000/pax | mulai Rp10.000/pax |
| Jumlah menu | 165+ | 189 (angka pasti) |

**Mohon klien konfirmasi.** Ada kemungkinan harga 10–12 ribu itu adalah menu
harian retail Warung Wuenak Poll, sedangkan paket catering W3S memang mulai
Rp20.000/pax. Kalau memang dua lini berbeda, katalog ini sebaiknya dipisah.

### 5. Lengkapi harga yang belum ada

Di `3s.html`, kategori **Premium**, **Frozen Food**, dan **Tumpeng** masih
tertulis "Hubungi kami untuk harga". Harga di PDF katalog tidak bisa dipetakan
secara pasti ke masing-masing item, jadi sengaja tidak ditebak — mencantumkan
harga yang salah lebih merugikan daripada tidak mencantumkan sama sekali.

Begitu klien mengirim daftar harganya, ganti teks tersebut di bagian
`menu-group__price` pada tiga kategori itu.

---

## Aset yang perlu diminta ke klien

| Aset | Kenapa penting |
|---|---|
| ~~Foto makanan~~ | ✅ **Sudah ada.** 138 foto asli dari `FOTO MENU HARIAN WWP\Harga *` (6000×3368) sudah diperkecil ke `assets/menu/` (560×420 webp, 13 KB/foto, total 1,8 MB) dan 5 foto besar ke `assets/img/sajian-*.webp`. |
| **⚠️ Foto katering premium 3S** | **Paling mendesak.** Lihat shot list di bawah. |
| **Foto dapur, tim, dan proses pengiriman** | Bagian "Kebersihan dijaga di setiap tahap" saat ini hanya teks. Foto asli jauh lebih meyakinkan untuk audit procurement. |
| **Foto untuk 51 menu sisanya** | 138 dari 189 menu sudah berfoto. Sisanya tampil sebagai daftar teks di bawah tiap kategori. |
| **3–5 testimoni + nama & instansi** | Belum ada sama sekali. Untuk B2B, satu kalimat dari GA sebuah bank bernilai lebih dari semua copywriting di halaman ini. |
| **Katalog menu PDF versi resolusi tinggi** | Untuk tombol unduh dan sebagai lampiran email. |
| **Logo vektor (SVG/AI) W3S, 3S, dan Wuenak Poll** | Saat ini header memakai kotak teks "W3S" sebagai pengganti sementara. |
| **Konfirmasi daftar klien** | Lihat catatan di bawah. |
| **Nama PIC** | Proposal `.docx` menyebut "Daniel", PDF Rev 3 hanya menulis "PIC W3S". Placeholder `{{Nama PIC}}` di email perlu diisi. |

---

## ✅ SUDAH TERPENUHI — foto premium 3S akhirnya tersedia

Bagian di bawah ini **sudah tidak berlaku**. Klien mengirim dua hal yang
menyelesaikannya:

1. **Katalog `Menu Kuliner Tradisional_Revisi-01.pdf`** — versi **300 dpi**
   (2481×3508 per halaman), 4,2× resolusi katalog lama. Dari sini 9 hidangan
   premium sudah di-crop ke `assets/dish/`: Rawon, Lontong Cap Go Meh, Nasi Empal,
   Nasi Kuning Ayam Bakar, Nasi Liwet, Nasi Pecel Empal, Nasi Sedep Ijo,
   Nasi Uduk Ayam Bakar, Lapis Daging — plus Tumpeng.
2. **Folder `Foto/`** — 30 foto asli 8–12 MP dari acara sungguhan: prasmanan
   dengan chafing dish dan kru berseragam, tumpeng, lini pengemasan, armada
   pengiriman. Sudah diproses ke `assets/event/`.

---

## ✅ SELESAI — file foto studio asli sudah diterima

Klien mengirim folder **`14 Agustus 2026`** berisi file asli hidangan 3S,
sebagian pada 6000 px. Ini menyelesaikan hambatan terbesar proyek.

Sebelumnya semua foto hidangan di-crop dari katalog PDF. Katalog itu memang
300 dpi, tapi **foto yang ditempel di dalamnya tidak** — pada perbesaran 1:1,
Rawon dan Nasi Empal sudah terlihat lunak di 552 px. Grid sajian sempat harus
diperkecil jadi 4 kolom (318 px) hanya supaya tidak pecah.

Dengan file asli, sumber terkecil sekarang 819 px dan terbesar 2896 px, jadi
grid dikembalikan ke **3 kolom (426 px)** — tile 34% lebih besar, skala render
terburuk 0.52, tidak ada satu pun yang pecah.

Yang dipakai: Rawon, Lontong Cap Go Meh, Nasi Empal, Nasi Kuning, Nasi Liwet,
Galantine Classic, Lapis Daging Signature.

### ⚠️ Satu hal untuk diinfokan ke klien

**Banyak foto studio itu membawa properti musiman** — pohon Natal mini,
salju hias, ornamen dan amplop merah Imlek. Untuk landing page yang tayang
sepanjang tahun itu janggal. Crop yang dipakai sudah diketatkan supaya
propertinya tidak masuk frame, tapi ini membatasi pilihan komposisi.

Kalau klien punya versi tanpa properti musiman (atau mau memotret ulang tanpa
dekorasi), hasilnya bisa lebih baik lagi. Bukan penghambat — hanya peluang.

Belum terpakai, tersedia kalau nanti dibutuhkan: Sop Merah (6000 px),
Ayam Goreng Laos, Ayam Bakar Madu, Ayam Bakar Bumbu Rujak, Galantine Crispy,
Nasi Campur 3S, Nasi Uduk, dan seri kemasan FROZEN.

Riwayat permintaan lama disimpan di bawah untuk referensi.

<details>
<summary>Daftar permintaan foto versi lama (sudah terpenuhi sebagian besar)</summary>

## LANGKAH PERTAMA: minta file foto asli ke desainer katalog

**Jangan langsung pesan pemotretan baru.** Foto premium 3S kemungkinan besar
sudah ada — hanya tidak pernah diberikan dalam bentuk file.

Buktinya ada di katalog `Binder1Proof Menu Kuliner Tradisional_REV 3`:
foto Galantine, Sop Merah, dan Tumpeng di sana **sudah bergaya premium** —
piring keramik, meja kayu gelap, pencahayaan hangat, properti gelas. Itu bukan
foto amatir. Seseorang memotret atau membeli foto itu pada resolusi penuh.

Masalahnya cuma satu: **PDF-nya diekspor sebagai satu gambar pipih 596×842 px
per halaman (~72 dpi)**, jadi yang sampai ke kita versi 1/6 resolusi.

Yang harus diminta ke desainer/vendor katalog:

1. **File foto asli** (JPG/PNG/RAW) untuk Tumpeng, Galantine Classic &
   Crispy, Sop Merah, Rawon, Lontong Cap Go Meh — tanpa watermark, tanpa
   bingkai katalog.
2. **Sumber foto**: dipotret sendiri atau beli dari stock? Ada watermark
   tercetak di atas makanan pada beberapa foto katalog yang **tidak terbaca
   sebagai nama brand 3S**. Kalau itu foto stock berlisensi preview, foto
   tersebut **tidak boleh** dipasang di situs.
3. **File desain katalog** (AI/PSD/INDD/Canva) — semua foto asli biasanya
   masih tertanam di dalamnya pada resolusi penuh.

Kalau ternyata file aslinya benar-benar hilang, baru jalankan shot list di bawah.

### Kenapa crop dari PDF tidak bisa dipakai

Sudah diuji, bukan perkiraan:

| Hidangan | Resolusi crop asli | Dibutuhkan | Hasil |
|---|---|---|---|
| Tumpeng (gambar besar) | 358×269 | 1600×1100 hero | 4,5× perbesaran — lembek |
| Galantine (kartu grid) | 161×236 | 900×675 kartu | 5,6× perbesaran — tidak layak |

Ditambah setiap crop membawa **watermark di atas makanan** dan **latar merah
marun + bingkai emas katalog**, yang bertabrakan dengan kanvas hitam situs.

## 📋 Daftar permintaan foto — siap dikirim ke klien

> Salin bagian di dalam kotak ini dan kirim apa adanya.

---

**Permintaan aset foto — website W3S**

Halo, untuk melengkapi website, kami butuh beberapa file foto. Sebagian besar
sepertinya sudah ada, hanya belum pernah dikirim dalam bentuk file.

**A. Prioritas utama — file asli foto yang sudah ada di katalog**

Foto-foto ini sudah dipakai di katalog *Kuliner Tradisional 3S*, tapi yang kami
terima hanya versi PDF beresolusi rendah (72 dpi). Mohon file aslinya:

1. Tumpeng 8 Orang
2. Galantine Classic
3. Galantine Crispy
4. Sop Merah
5. Lapis Daging Signature
6. Empal Premium
7. Rawon Authentic
8. Ayam Goreng Laos Aromatic
9. Ayam Bakar Madu Caramelized

Ketentuan: format JPG/PNG asli dari kamera, **tanpa watermark**, tanpa bingkai
katalog.

Ukuran minimal — makin besar, makin banyak yang bisa kami lakukan di website:

| Untuk dipakai sebagai | Sisi terpendek minimal |
|---|---|
| Kartu kecil (seperti tumpeng sekarang) | 400 px |
| Foto sedang di dalam section | 1200 px |
| Foto besar penuh satu kolom | 2000 px |
| **Latar hero, selebar layar** | **2560 px** |

Kalau file aslinya dari kamera (biasanya 4000–6000 px), semua kebutuhan di atas
otomatis terpenuhi — kirim apa adanya, jangan dikecilkan dulu.

Kalau file terpisahnya sudah tidak ada, kirimkan **file desain katalognya**
(Canva / Illustrator / Photoshop / InDesign) — foto aslinya biasanya masih
tertanam di dalam pada resolusi penuh.

**B. Belum ada fotonya sama sekali**

10. Lontong Cap Go Meh
11. Prasmanan — meja tertata lengkap dengan chafing dish
12. Nasi Kuning versi acara (tumpeng mini / nampan saji)

**C. Kalau ada anggaran lebih**

13. Foto dapur saat proses memasak
14. Foto tim / kru saat menyiapkan pesanan
15. Foto proses pengiriman (mobil, kemasan tertata)

Ketiganya untuk memperkuat bagian legalitas dan proses di website.

**Catatan gaya foto**

Untuk 3S (premium), mohon **jangan** difoto seperti menu harian WWP:

| Hindari | Sebaiknya |
|---|---|
| Kotak plastik + saus sachet | Piring keramik, nampan kayu, atau daun pisang |
| Alas kain putih berkerut | Permukaan gelap: kayu tua, batu, kain gelap |
| Lampu HP dari depan | Cahaya dari samping, bayangan lembut |
| Semua tampak atas | Campur 45° dan close-up tekstur |

Foto Galantine dan Sop Merah di katalog sekarang **sudah tepat gayanya** —
tinggal butuh file resolusi penuhnya.

---

### Catatan tambahan (internal, jangan ikut dikirim)

- Dari 138 foto WWP yang ada, **64 di antaranya jepretan HP** (960×1280 sampai
  1536×2048) dan 74 sisanya studio (6000×3368). Beranda hanya memakai yang
  studio. Foto HP tetap dipakai di katalog karena ukurannya kecil, tapi kalau
  ada kesempatan foto ulang, prioritaskan yang jepretan HP.
- Watermark pada foto katalog 3S sudah diperiksa: bunyinya
  `@kulinertradisional3s`, jadi itu **milik klien sendiri**, bukan watermark
  stock berlisensi. Tidak ada masalah hak pakai.

## Shot list pemotretan — hanya kalau file asli benar-benar hilang

Seluruh 138 foto yang ada berasal dari folder **"FOTO MENU HARIAN WWP"**:
menu harian Rp3.000–15.000, difoto sebagai **kotak plastik di atas kain putih**.
Foto itu jujur dan bagus untuk katalog WWP, tapi **tidak bisa dipakai untuk
menjual 3S sebagai katering Nusantara premium** — justru menurunkan positioning
yang ingin dinaikkan.

Dari kualitasnya juga terbelah: **74 foto studio** (6000×3368, pencahayaan rata)
dan **64 jepretan HP** (960×1280 sampai 1536×2048, bayangan keras, kain berkerut).
Beranda sengaja hanya memakai yang studio.

### Hidangan yang belum ada fotonya sama sekali

Semuanya disebut sebagai hero product 3S, semuanya belum ada:

- **Tumpeng** (lengkap, tampak atas dan tampak samping)
- **Galantin** — Classic dan Crispy
- **Rawon** — dalam mangkuk saji, bukan kotak
- **Lontong Cap Go Meh** — yang tersedia hanya "Lontong Sayur", hidangan berbeda
- **Prasmanan** — meja tertata lengkap dengan chafing dish
- **Nasi Kuning tampilan acara** — tumpeng mini atau nampan saji

### Arahan gaya (ini yang membedakan dari foto WWP)

| Jangan | Lakukan |
|---|---|
| Kotak plastik + saus sachet | Piring keramik, nampan kayu, atau daun pisang |
| Kain putih berkerut | Permukaan gelap: kayu tua, batu, atau kain gelap |
| Lampu flash dari depan | Cahaya samping, bayangan lembut terarah |
| Tampak atas datar semua | Campur: 45° untuk kedalaman, close-up tekstur |
| Porsi apa adanya | Ditata: taburan bawang goreng, daun, sambal terpisah |

**Minimal 12 foto, sisi terpendek ≥2400px.** Kalau anggaran terbatas, prioritaskan
Tumpeng, Rawon, dan Prasmanan — tiga itu yang paling membedakan 3S dari katering
nasi box biasa.

Setelah foto tersedia, ganti isi `.feature__hero` dan `.showcase` di
`index.html`, lalu buat ulang ukuran webp-nya seperti dijelaskan di
[docs/DESIGN.md](docs/DESIGN.md).

</details>

## Temuan dari dokumen yang dikirim

### Ada 9 klien di grid logo yang tidak disebut di daftar teks

Grid logo di halaman terakhir `.docx` memuat 30 logo, tapi daftar teks
"Dipercaya Melayani" hanya menyebut 24 nama. Yang hanya muncul sebagai logo:

**Universitas Airlangga**, **CIMB Niaga**, **Permata Bank**, **SPIL
(Salam Pacific Indonesia Lines)**, **VinFast**, **Fitgloss**, **City Products**,
**Ciccia Ristorante**, **Salon Saga**.

Beberapa di antaranya nama besar — UNAIR, CIMB, Permata, dan SPIL sudah
dimasukkan ke landing page karena memperkuat kredibilitas. **Mohon klien
konfirmasi bahwa semuanya benar-benar pernah dilayani** sebelum halaman ini
dipublikasikan, karena mencantumkan logo perusahaan tanpa dasar bisa jadi masalah.

Sebaliknya, **Bethany Kendangsari** disebut di teks tapi tidak ada logonya.

### Katalog menu memakai bahasa visual yang berbeda

Proposal Rev 3 bertema hitam–hijau–merah, sedangkan katalog menu bertema
merah marun–emas dengan merek "Kuliner Tradisional 3S". Landing page mengikuti
tema proposal Rev 3. Untuk jangka panjang sebaiknya dua brand ini disatukan
arah visualnya, atau posisinya dibedakan dengan jelas (3S untuk retail/keluarga,
W3S untuk korporat).

### Beberapa nama menu tertulis tidak konsisten di katalog

Sudah diperbaiki (typo yang jelas; kini ada di halaman brand):

- "Rawon Aunthentic Frozen" → **Rawon Authentic Frozen**
- "Nasi Chieken Mayo Garlic" → **Nasi Chicken Mayo Garlic**

Dibiarkan apa adanya karena perlu keputusan klien, bukan typo yang pasti:

- **"Nasi Ayam Bumbu Behek"** — mungkin maksudnya "Bebek", tapi bisa juga
  istilah bumbu yang memang dipakai di dapur. Perlu dikonfirmasi.
- **"Spagetti"** — ejaan ini lazim dipakai di Indonesia; ganti ke "Spaghetti"
  hanya kalau klien memang mau.
- Penulisan campur **"Chicken"/"Ayam"** dan **"Sambel"/"Sambal"** di banyak item.
  Sebaiknya dipilih satu gaya lalu diseragamkan di katalog PDF sekalian, supaya
  situs dan PDF tidak berbeda.

---

## Cara menjalankan secara lokal

**Harus lewat server — membuka `index.html` langsung dari disk tidak lagi
bekerja.** Tautan antar-halaman ditulis root-relative tanpa ekstensi
(`/wuenak-poll`, `/3s`) mengikuti aturan URL produksi; di `file://` bentuk itu
diartikan sebagai akar drive, jadi browser melaporkan `ERR_FILE_NOT_FOUND`.

Skrip server ini tanpa dependensi, jadi tidak perlu unduhan:

```bash
node tools/serve.js 4321
```

Lalu buka http://localhost:4321. Jalankan dari folder induk (`W3S Sales`).

Server itu sengaja meniru Vercel, termasuk redirect 308 untuk `/3s.html` dan
`/3s/`. Kalau ia lebih permisif daripada produksi, tautan yang salah akan
terlihat sehat di lokal dan baru patah setelah deploy.

## Cara publikasi

Situs ini statis dan sekarang tayang di **Vercel** (domain `www.w3scatering.com`).
Aturan URL-nya dikunci di `vercel.json`:

```json
{ "cleanUrls": true, "trailingSlash": false }
```

Berkas itu **menang atas pengaturan di dasbor**, jadi setiap salinan deploy
berperilaku sama. Jangan menghapusnya: seluruh canonical, `og:url`, `@id`
JSON-LD, dan `sitemap.xml` ditulis tanpa `.html` dengan asumsi aturan ini aktif.

Kalau suatu saat pindah host, penggantinya harus menyajikan Clean URLs juga —
Netlify dan Cloudflare Pages sama-sama bisa, tapi konfigurasinya lain dan
`vercel.json` tidak terbaca di sana.

---

## Catatan teknis

- **Semua tombol WhatsApp** memakai kelas `.js-wa` dengan atribut `data-msg`.
  Pesan otomatis terisi sesuai section, jadi klien tahu prospek datang dari
  bagian mana (paket mana, halaman menu, dsb).
- **Form** tidak mengirim data ke server mana pun. Isinya diubah menjadi pesan
  WhatsApp terformat, lalu membuka WhatsApp. Tidak ada backend, tidak ada
  data pribadi yang tersimpan.
- **Satu-satunya aset eksternal adalah Google Analytics** (`gtag.js`, properti
  klien `G-411D6LYS5E`), dipasang atas permintaan klien. Selain itu tidak ada
  Google Fonts, CDN, atau pelacak pihak ketiga — font di-self-host di
  `assets/fonts/`. Kalau suatu saat halaman terasa berat atau terblokir jaringan
  kantor, `gtag.js` adalah tempat pertama yang perlu diperiksa.
- **Klik WhatsApp terekam sebagai event `generate_lead`.** Tanpa itu GA4 tidak
  bisa mengukur apa pun yang berguna di situs ini — lihat DESIGN.md.
- **Marquee logo** berhenti saat kursor diarahkan ke sana, dan otomatis
  berubah jadi grid statis untuk pengguna yang mengaktifkan
  `prefers-reduced-motion`.
- **Sticky CTA** muncul hanya di layar di bawah 760px.
- **Animasi** ada di `assets/js/motion.js` dan seluruhnya bersifat tambahan —
  kalau berkas itu gagal dimuat, ada jaring pengaman di `<head>` yang memaksa
  semua konten tetap tampil. Jangan hapus penjaga itu; penjelasannya ada di
  [docs/DESIGN.md](docs/DESIGN.md).
- Untuk memasang Google Analytics, tempel snippet-nya tepat sebelum
  `</body>` di kedua halaman. Tautan di email sudah membawa parameter UTM.
