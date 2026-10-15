# W3S Catering — Design System v2 "Warm Table"

Sumber kebenaran untuk tampilan situs. Baca ini sebelum mengubah desain, dan
lanjutkan arahnya — jangan memulai bahasa visual baru tanpa alasan.

---

## Kenapa v1 diganti

v1 memakai referensi **Athletics** — sebuah *studio desain*. Kanvas hitam netral,
monokrom, editorial dingin, tracking negatif ketat. Bahasa visual itu benar untuk
agency atau SaaS, **salah untuk bisnis makanan**. Klien menyebutnya "kerasa AI".

Alasan v1 memilih Athletics masih valid pada waktunya: saat itu **tidak ada satu
pun foto** yang layak, jadi dipilih sistem yang ditopang tipografi. Premis itu
gugur ketika klien mengirim 30 foto event 8–12 MP dan katalog 3S versi 300 dpi.

---

## Referensi

**Utama: Limón** (`styles.refero.design`) — restoran.
Deskripsi sistemnya: *"evening brasserie, warm overhead light, food photographed
as editorial still-life"*. Itu **persis** karakter foto katalog 3S: lilin, meja
kayu gelap, piring putih, latar marun.

Tiga aturan Limón memperbaiki tiga keluhan nyata:

| Aturan | Memperbaiki |
|---|---|
| Kanvas `#1d0b0d` — near-black **hangat** | v1 memakai `#000000` netral-dingin, yang menekan selera |
| *"Food full-bleed atau tile besar, hindari thumbnail kecil"* | "tumpeng kecil sekali" |
| Tracking display **positif lebar** | v1 negatif ketat → terasa generik |

**Pendukung: Amrit Palace** — hanya nada krem hangatnya, untuk section jeda.

**Ditolak: Amrit Palace sebagai acuan utama** — kanvas parchment terangnya
membuang identitas gelap klien (proposal Rev 3 mereka hitam–hijau–merah).

---

## Token

### Permukaan gelap — semuanya HANGAT
| Token | Nilai | Peran |
|---|---|---|
| `--ink` | `#1A0E0A` | Kanvas utama |
| `--ink-2` | `#241310` | Band selang-seling |
| `--ink-3` | `#2F1A15` | Kartu |
| `--ink-4` | `#3B231C` | Kartu terangkat |

Ketiganya mengandung merah/coklat. **Jangan menggantinya dengan abu netral** —
itu tepat kesalahan v1.

### Permukaan terang
`--cream #F7F0E4` untuk section jeda. Halaman tidak boleh gelap terus-menerus;
saat ini tiga section memakai krem: `#masalah`, `#cara-pesan`, `#faq`.

### Teks
`--paper #FDF8F0` — putih **hangat**, bukan `#fff`.

`--muted-2` pernah bernilai `.42` dan **gagal kontras di keempat permukaan
gelap** (3.83–3.92:1) padahal dipakai untuk label mikro ~10px yang menuntut
4.5:1. Sekarang `.50` — nilai terkecil yang lolos di semuanya, termasuk kartu
terangkat `--ink-4` (4.57:1), sambil tetap menyisakan jenjang yang terbaca di
bawah `--muted` (8.1:1). **Jangan diturunkan lagi.**

### Permukaan yang digambar browser
`color-scheme: dark` di `:root`. Scrollbar, daftar `<option>`, dan pemilih
tanggal digambar oleh browser dan defaultnya terang — di halaman segelap ini
ketiganya muncul sebagai kotak putih. Satu baris itu menyerahkan semuanya ke
palet gelap secara native, jauh lebih benar daripada menambal
`::-webkit-scrollbar` satu per satu.

Konsekuensinya: `filter: invert(1)` pada ikon kalender **dihapus**. Dengan
`color-scheme: dark` browser sudah menggambarnya terang; membalikkannya lagi
justru menggelapkannya di atas field gelap.

### Aksen
| Token | Nilai | Aturan pakai |
|---|---|---|
| `--zest` | `#8DC63F` | Hijau merek klien, dipakai sebagai "zest" tunggal ala Limón: label, CTA utama, filter aktif |
| `--gold` | `#C8A46A` | **Hanya** penanda Kuliner Tradisional 3S (premium) |
| `--ember` | `#E32219` | Identitas merek. **Hanya** untuk garis/blok — jangan untuk teks kecil |
| `--ember-srf` | `#BE1C13` | Permukaan promo (`.offer`); teks `--paper` di atasnya = 5.90:1 |
| `--ember-dk` | `#EF5B50` | Teks merah **di atas permukaan gelap** = 5.34–5.65:1 |
| `--ember-lt` | `#C81C13` | Teks merah **di atas `--cream`** = 5.11:1 |

Merah dipecah empat karena satu nilai tidak bisa mengerjakan tiga pekerjaan
sekaligus. `#E32219` adalah merah jenuh setengah-terang: sebagai **permukaan**
ia cuma memberi 4.40:1 pada teks `--paper`, dan sebagai **teks** ia gagal di
kedua ground — 3.84:1 di atas `--ink-2`, 4.11:1 di atas `--cream`. Ketiganya di
bawah ambang 4.5:1 dan semuanya terpakai pada teks ~10px. Hue-nya dipertahankan;
hanya terangnya yang digeser sesuai ground.

Hijau menandai aksi, emas menandai kelas premium. Jangan ditukar.

### Radius
**Satu nilai: `--r: 2px`.** Tidak ada varian pil. Signature Limón adalah geometri
yang sengaja tidak dilembutkan.

Satu pengecualian yang sudah dipertimbangkan: chip logo brand di `#brand`
memakai `border-radius: 50%` — alasannya di bagian "Logo brand W3S" di bawah.
Terverifikasi: **hanya dua elemen itu** yang memakai radius selain 2px.

### Tipografi
| Peran | Font | Catatan |
|---|---|---|
| Display | **Petrona** variable | wght 700–800, tracking **+0.012em** |
| Body / UI | **Karla** variable | Label mikro tracking .2–.26em, uppercase |

Keduanya self-host di `assets/fonts/` (74 KB total — lebih ringan dari 152 KB v1).
Tidak ada permintaan ke CDN untuk font. (Sejak Google Analytics dipasang, situs
punya tepat **satu** permintaan ke luar — lihat bagian "Analytics" di bawah.)

---

## Aturan foto — inti sistem ini

### Aturan pertama: rasio bingkai mengikuti rasio foto

Ini aturan yang paling mahal dipelajari di proyek ini, dan yang paling sering
dilanggar. Sebuah tile hanya boleh diberi rasio yang **sudah dimiliki fotonya**.
Melawan rasio asli menimbulkan dua kerusakan sekaligus:

- `object-fit: cover` membuang tepi foto → hidangannya terpotong;
- untuk menutupi bingkai yang lebih besar, sumber harus dibesarkan → pecah.

Contoh nyatanya: platter tumpeng berkomposisi 2:1. Dipasang di kotak 853×853,
kedua tepinya terpotong **dan** sumber 880 px harus dibesarkan. Dipindah ke
bingkai 2:1 (853×427) dengan sumber 1792 px, platter tampil **utuh 100%**
sekaligus **diperkecil** ke skala 0.48 — jadi tajam. Bingkainya justru mengecil,
bukan membesar.

Aturan turunannya: **tidak ada gambar yang boleh di-render melebihi resolusi
aslinya.** Semua skala render saat ini ≤ 1.0.

### Aturan kedua: besar tile ditentukan resolusi sumber, bukan selera

Aturan empiris: sebuah tile baru terlihat tajam kalau **skala render ≤ ~0.62**
dari lebar aslinya. Ukur `naturalWidth` terhadap lebar render **sebelum**
memperbesar bingkai — jangan pernah menentukan ukuran tile dari selera.

Riwayat nyata di proyek ini, dan kenapa angkanya berubah dua kali:

| Sumber | Susunan | Lebar tile @1440 | Skala | Hasil |
|---|---|---|---|---|
| crop katalog ±552 px | 2 kolom (kotak 853) | 853 | 0.97 | pecah |
| crop katalog ±552 px | 3 kolom | 426 | 0.77 | masih lunak |
| crop katalog ±552 px | 4 kolom | 318 | 0.58 | tajam tapi kecil |
| **foto studio 819–2896 px** | **3 kolom** | **426** | **0.52** | **tajam & besar** |

Klien akhirnya mengirim **file foto studio aslinya** (folder `14 Agustus 2026`),
dan itulah yang mengembalikan grid ke 3 kolom: tile 34% lebih besar tanpa satu
pun jadi pecah. Kalau suatu saat ada hidangan bersumber < 700 px masuk ke grid
ini, turunkan lagi ke 4 kolom — jangan biarkan satu foto lemah merusak barisnya.

### Sisanya

1. **Tile lebar 853×427, tile biasa 426×426** pada layar 1440.
2. **Hero adalah split editorial, bukan foto full-bleed** — berlaku untuk kedua
   halaman brand. (Hero `index.html` sudah diganti dua tumpukan kartu; lihat
   bagian "Hero index" di bawah.) Foto makanan bergaya yang tersedia berformat
   potret 1086×1448; di balik teks secara full-bleed, foto itu habis terpotong.
   Kolom kanan hero dikunci pada `aspect-ratio: 3/4` — rasio asli foto — jadi
   tidak ada satu piksel pun yang dibuang, di lebar layar mana pun. Di bawah
   900px kolom itu naik ke atas dan berubah 1/1, karena potongan persegi masih
   memuat piring secara utuh.
3. **Foto makanan tetap berwarna penuh.** Hanya logo klien yang di-grayscale.
4. Foto katalog 3S membawa latar marun bawaan — itu **menguntungkan**, karena
   menyatu dengan `--ink`. Ini salah satu alasan Limón dipilih di atas Amrit Palace.

### Logo brand W3S — satu-satunya lingkaran di situs ini

`assets/brand/wuenak-poll.webp` dan `assets/brand/kuliner-3s.webp` (440×440,
alfa transparan), tampil 104×104 di kartu `#brand`. Dibuat oleh
`tools/make-brand-logos.js`.

**Penyimpangan disengaja dari aturan "radius hanya 2px".** Chip-nya bulat
(`border-radius: 50%`). Dua alasan, keduanya sudah diuji langsung:

1. Logo harus duduk di permukaan terang. Latarnya sudah dibuat transparan, tapi
   mark Wuenak Poll berisi **mangkuk panggangan hitam** — di atas `--ink-3` ia
   lenyap, dan logonya terbaca sebagai ayam melayang tanpa panggangan.
2. Chip persegi terlihat seperti kotak putih tertempel. Lingkaran mengikuti
   bentuk logo 3S yang memang bundar, jadi terbaca sebagai lencana merek.

Ini penanda merek, bukan komponen UI — **jangan jadikan preseden untuk memakai
radius bulat di tempat lain.** Terverifikasi: hanya dua elemen ini yang memakai
radius selain 2px.

#### Cara latar putihnya dibuang

Bukan dengan "hapus semua piksel putih" — kedua logo justru memakai putih
sebagai warna isi (tulisan "3S" dan "KULINER TRADISIONAL", jempol, outline
"WNP", mata ayam, kilatan panggangan). Menghapus berdasarkan warna akan
melubangi semuanya.

Yang dipakai: **flood fill 4-arah dari seluruh piksel tepi kanvas.** Hanya
putih yang tersambung ke tepi yang dibuang; putih yang terkurung garis gelap
tidak pernah tersentuh.

Tiga hal yang tidak terduga dan sudah ditangani di skripnya:

- **Kantong latar terkurung.** Area di antara kaki biru panggangan Wuenak Poll
  adalah latar, tapi tersegel sehingga fill dari tepi tidak sampai. Diberi
  titik bibit eksplisit. Menaikkan toleransi global bukan jawabannya — itu
  berisiko membocorkan fill ke putih yang memang bagian desain.
- **Wordmark dibuang.** Tulisan "WUENAK POLL" di bawah mark pasti tertebas tepi
  lingkaran, dan nama brandnya toh sudah jadi judul kartu tepat di bawah chip.
  Yang masuk chip hanya mark-nya.
- **Padding berbeda per logo.** Artwork persegi bersisi S baru muat penuh di
  lingkaran berdiameter D bila S ≤ D/√2, jadi Wuenak Poll dipadding 1.42×.
  Logo 3S sudah bulat dan sudutnya kosong — cukup 1.10×, kalau dipaksa 1.42×
  ia mengambang dengan cincin krem tebal.

Verifikasi hasil crop: komposit di atas magenta terang, lalu cek tidak ada
putih desain yang bolong. Kalau klien mengirim ulang file logo, ulangi cek ini.

**Warna penuh dipertahankan** — ini brand milik klien sendiri, beda perlakuan
dengan logo klien pihak ketiga di `.logo-grid` yang sengaja di-grayscale.

### Sumber aset
- `assets/dish/` — 7 hidangan dari **file foto studio asli klien**
  (folder `14 Agustus 2026`), bukan lagi crop katalog. Sumber 819–2896 px.

  **Crop dipilih manual, bukan `sharp.strategy.attention`.** Banyak foto
  membawa properti musiman — pohon Natal mini, ornamen Imlek, amplop merah —
  di tepi frame, dan `attention` justru sering mengunci ke properti itu alih-alih
  ke makanannya. Skrip pembuatnya: `tools/` + parameter zoom/geser per foto.
  Kalau menambah hidangan, periksa hasil crop-nya secara visual dulu.

- `assets/dish/tumpeng.webp` 1792×896 — satu-satunya yang masih dari katalog
  300 dpi, diambil dari **halaman sampul** (halaman 2 hanya 1388×1047 dan penuh
  latar marun kosong).
- `assets/event/hero-tumpeng.webp` 1086×1448 — satu-satunya foto makanan
  **bergaya** di seluruh set aset klien (meja kayu, kain batik, cahaya hangat).
  Karena itu ia dipakai di hero dan sebagai sumber og-image.
- `assets/event/` lainnya — foto operasional asli klien dari folder `Foto/`.
- `assets/menu/` — 138 thumbnail menu harian WWP.

Katalog versi lama beresolusi 596×842 (72 dpi) — **jangan dipakai lagi**, crop
darinya hanya menghasilkan 160–360 px.

**Jujur soal isi folder `Foto/`:** dari 41 foto, sebagian besar adalah logistik
(kantong merah, mobil pengiriman, lini packing) yang diambil dengan ponsel di
bawah matahari keras. Hanya satu yang benar-benar tertata sebagai foto makanan.
Bahan hero yang layak masih perlu diminta ke klien — lihat `README.md`.

Aset yang tidak lagi dirujuk dipindah ke `../_arsip-aset/` (di luar folder
deploy), bukan dihapus. Folder deploy harus selalu nol berkas yatim.

---

## Gerak

Semua di `assets/js/motion.js`, seluruhnya progressive enhancement.

| Efek | Keterangan |
|---|---|
| **Parallax hero** | Foto bergeser lebih lambat dari scroll (maks 4.5%), diperbesar **1.10×**. Angkanya sengaja kecil: setiap parallax menuntut pembesaran, dan setiap pembesaran memakan tepi foto. Pada 1.10× hanya 5% tiap sisi yang jadi ruang gerak — bagian meja kayu, bukan tampahnya. **Jangan dinaikkan di atas ~1.14**, daun pisang mulai terpotong. |
| **Tilt 3D** pada tile makanan | Perspektif 1200px, maksimal **4°**. Sengaja kecil — cukup terasa hidup, tidak norak. Hanya perangkat bermouse. |
| **Reveal** | IntersectionObserver, jeda bertahap 80 ms antar elemen bersaudara. |
| **Count-up** | Angka statistik hero, easing kubik. |

Shader bara api WebGL dari v1 **dihapus**: efek itu dibuat untuk kanvas hitam
netral dan tidak lagi relevan. Kedalaman sekarang datang dari fotonya sendiri.

### Testimoni — kenapa hanya teks, tidak pernah screenshot

Klien mengirim 9 screenshot WhatsApp dan Instagram. **Tidak satu pun dipakai
sebagai gambar**, dan itu keputusan yang harus dipertahankan. Isinya memuat:

- notifikasi transfer bank atas nama **pihak ketiga** yang tidak ada
  hubungannya dengan testimoni;
- wallpaper chat berupa foto keluarga pelanggan — di satu screenshot **wajah
  anak terlihat jelas**;
- nama lengkap, foto profil, dan username Instagram lengkap;
- percakapan pribadi di luar konteks katering.

Yang naik ke situs hanya **kutipannya**, dengan **nama depan saja**. Screenshot
mentahnya disimpan di `../_arsip-aset/testimonial-mentah/`, di luar folder
deploy. Kalau nanti ada permintaan "pasang screenshot-nya biar terlihat asli",
jawabannya tetap tidak sampai klien menyensor sendiri dan mengantongi izin.

**Framing sengaja dipisah dari `#klien`.** Semua pesan itu datang dari pelanggan
**perorangan** yang memesan besek, bukan dari institusi di daftar 30+ klien.
Menggabungkannya akan terbaca seolah UBAYA atau BCA yang berkata begitu — klaim
yang tidak benar dan mudah dibantah.

**Ejaan aslinya dipertahankan** ("uenak pool", "mantul", "gurih-gurih asin").
Begitu diformalkan jadi "sangat enak", kutipannya langsung terbaca seperti
karangan tim marketing. Kredibilitasnya justru ada di logat Suroboyoannya.

Satu masukan produk yang TIDAK dipasang tapi sudah diteruskan ke klien: seorang
pelanggan menyebut kering kentangnya "standar" sementara lauk lain enak.

### Aturan layar sentuh — jangan dihapus saat merapikan CSS

Lima hal berikut terlihat seperti nilai acak, padahal masing-masing memperbaiki
kerusakan nyata yang terukur:

| Aturan | Kalau dilanggar |
|---|---|
| Input & kolom cari **16px** di ≤760px | Safari iOS men-zoom halaman tiap kali field disentuh dan tidak mengembalikannya — formulir terasa "meloncat" di tiap isian. Nilai desktop `.97rem` = 15.5px, tepat di bawah ambang. |
| `body { padding-bottom: calc(88px + env(safe-area-inset-bottom)) }` | Bar CTA melayang setinggi 85px menutupi baris terakhir footer. Angka mati tidak cukup — di iPhone ber-home-indicator selisihnya bertambah. |
| `.btn:active { transform: scale(.975) }` | Layar sentuh tidak punya `:hover` sama sekali; tanpa ini tombol terasa mati saat ditekan. |
| `@media (hover: none)` menetralkan seluruh `:hover` tombol | iOS memutar ulang `:hover` setelah tap, jadi warna tombol "nyangkut" terang setelah dilepas. |
| Filter katalog `min-height: 42px`, tautan navigasi `min-height: 40px` | Sembilan tombol filter setinggi 31px berjajar rapat praktis mustahil ditekan tepat dengan ibu jari — padahal itu interaksi utama halaman katalog. |

Semuanya dibatasi ke `max-width: 760px`; tampilan desktop sengaja tetap rapat.
Terverifikasi di 375px: **nol** target sentuh di bawah 36px pada kedua halaman.

### Gerak di layar sempit

Jeda bertahap reveal dipendekkan (80ms → 45ms, total dibatasi 360ms) dan
jaraknya dikurangi (18px → 12px). Alasannya bukan selera: di layar sempit semua
grid runtuh jadi satu kolom, jadi elemen bersaudara per layar jauh lebih banyak
— dengan angka desktop, elemen ke-8 baru muncul 640ms setelah yang pertama dan
terasa tertinggal jauh di belakang jempol.

FAQ: `<details>` tidak bisa ditransisi tingginya secara lintas-browser, jadi
yang dianimasikan **isinya** (`.faq details[open] p`), bukan wadahnya. Efeknya
terbaca halus tanpa hack `max-height`.

### Jaring pengaman yang wajib dipertahankan
`[data-reveal] { opacity: 0 }` hanya berlaku di dalam kelas `.js`, yang dipasang
skrip sebaris di `<head>` dan dilepas otomatis setelah 2,5 detik bila `motion.js`
gagal dimuat. Tanpa ini, satu kegagalan JS membuat halaman tampak kosong.

---

## Jebakan yang sudah ditemui — jangan diulang

### `<figure>` punya margin bawaan
`.plate` adalah `<figure>`. Browser memberinya `margin: 1em 40px`, yang menyusutkan
**setiap tile makanan sebesar 80px** dan merusak grid — tile terukur 426px padahal
kolomnya 425.6px hanya setelah `margin: 0` ditambahkan. Sebelum diperbaiki: 346px.

Ini hanya ketahuan dari pengukuran computed style, tidak dari melihat sekilas.

### Grid dengan kolom `max-content` tidak sejajar antar baris
Kalau setiap baris adalah grid tersendiri, `max-content` dihitung ulang per baris.
Untuk kolom yang harus rata antar baris, kunci lebarnya dengan `clamp()`.

### "Besar" tidak sama dengan "menggugah"
Tile 853×853 terlihat mengesankan di CSS dan hancur di layar, karena sumbernya
cuma 880 px dan komposisi fotonya lebar. Ukuran tile harus dipilih dari
**resolusi dan rasio sumber**, bukan dari keinginan agar sesuatu tampil besar.
Selalu ukur `naturalWidth` terhadap lebar render sebelum memperbesar bingkai.

### Teks yang menyebut POSISI akan salah di salah satu layout
Lead formulir dulu berbunyi *"Isi form di samping"*. Benar di atas 940px, salah
di bawahnya — di sana form pindah ke bawah. Kalimat yang menunjuk arah selalu
punya breakpoint di mana ia jadi bohong. Tulis netral: *"Cukup isi sekali."*

### Label dan heading dibaca sebagai satu kalimat
Pengunjung membaca label mikro dan heading sebagai satu blok. Heading harus tetap
utuh sebagai kalimat kalau label ikut dibaca di depannya.

---

## Rev 2 — struktur konversi (mengalahkan review sebelumnya)

Klien mengirim dokumen `W3S_Rev_2_5_Conversion_Changes.docx` berisi 5 revisi.
Beberapa di antaranya **membalik** arahan review sebelumnya. Rev 2 yang menang.
Jangan "mengembalikan" hal-hal berikut tanpa dokumen baru dari klien:

| Sebelumnya | Rev 2 | Alasan klien |
|---|---|---|
| Lockup "Two brands. One reliable kitchen." di section ke-3 | W3S berdiri sendiri di atas; hubungan brand pindah ke **Tentang Kami** (section ke-8) | W3S adalah brand utama. Wuenak Poll tidak perlu ditonjolkan di bagian atas. |
| 30 logo, dua baris marquee, tepat di bawah hero | **9 logo** diam + kalimat "+30 institusi"; 30 logo lengkap turun ke `#klien` | Logo adalah social proof, bukan elemen utama halaman. |
| `#cara-pesan` di urutan ke-9 | naik ke **tepat setelah `#paket`** | Itu sales mechanism utama, harus menempel pada harga. |

### Revisi minor sesudah Rev 2 — sebagian membalik Rev 2 sendiri

Klien mengirim tiga koreksi setelah Rev 2 diterapkan. **Koreksi ini yang
berlaku**, meski dua di antaranya mengembalikan hal yang baru saja dihapus:

| Item | Rev 2 | Koreksi terakhir (berlaku) |
|---|---|---|
| Hero | Nama WWP/3S dihapus total dari layar pertama | Baris brand **dikembalikan** ke hero: *"W3S menghadirkan Wuenak Poll untuk kebutuhan reguler dan 3S untuk kebutuhan premium & corporate."* |
| Nama tier | Essential / Standard / Executive / Premium | **Dikembalikan** ke Value Meal / Meeting & Campus / Corporate & Seminar / Premium Indonesian Catering — klien menilai nama lama membuat customer tidak perlu bertanya "harganya berapa" |
| CTA hero | "Kirim Budget, Kami Pilihkan Menu" | **"Minta Penawaran"** + **"Lihat Menu"** |
| Daftar jenis layanan di `#paket` | ditambahkan | **dihapus lagi** |

### Aturan `#paket` — DIBALIK oleh klien, baca seluruhnya

> **Nama dapur SEKARANG ada di nama tier.** "Value Meal by Warung Wuenak Poll",
> "Premium Indonesian Catering by 3S". Jangan "diperbaiki" kembali.

Aturan lama berbunyi kebalikannya — kartu paket dilarang menyebut brand dapur —
dan itu bukan aturan sembarangan: label brand di kartu sudah dicoba dan dihapus
**dua kali** sebelumnya:

1. label brand per kartu → dihapus;
2. daftar "Yang kami layani" (Corporate / Education / Event & Gathering /
   Routine Catering) sebagai sumbu kedua → dihapus, karena setelah nama tier
   dikembalikan kata "Corporate" jadi muncul di dua tempat sekaligus.

Klien memintanya kembali, dan bentuknya berbeda dari dua percobaan yang gagal
itu — perbedaan inilah yang membuatnya bisa bertahan:

| | Yang dulu dihapus | Yang sekarang |
|---|---|---|
| Bentuk | chip/label brand terpisah, atau sumbu kategori kedua | melekat di dalam nama tier |
| Efek pada customer | jadi **pilihan** yang harus diputuskan | jadi **keterangan** siapa yang memasak |
| Jumlah sumbu keputusan | dua (tier + brand) | tetap satu (tier) |

Customer tetap memilih lewat **budget, jenis acara, dan jumlah pax**. Kalau suatu
saat nama dapur mau dipindah keluar dari nama tier — jadi chip, badge, atau
kolom sendiri — itu mengulang kesalahan yang sudah dihapus dua kali. Jangan.

**Tinggi `.pkg__name` dikunci dua baris (`min-height: calc(2 * 1.2em)`).** Sejak
nama tier memuat nama dapur, panjang keduanya nyaris sama tapi titik putus
katanya berbeda, jadi ada rentang lebar sempit di mana yang satu muat satu baris
dan yang lain jadi dua — dan karena nama duduk di ATAS harga, selisih satu baris
menggeser Rp10.000 dan Rp35.000 sejauh 24px terhadap satu sama lain.

Diukur dengan menyapu lebar kontainer memakai elemen sungguhan: **9 dari 451
lebar timpang sebelum diperbaiki, 2 sesudahnya** — dan kedua sisa itu hanya ada
di sapuan buatan, karena di alur responsif asli lebar segitu sudah masuk mode
satu kolom (mode satu kolom: 0 dari 181 lebar timpang). Kalau nama tier diganti
lagi, ulangi pengukuran ini; jangan memakai probe elemen tiruan — probe seperti
itu sempat memberi dua rentang yang ternyata salah.

Yang tersisa sebagai penanda kelas hanyalah **garis emas di tier premium**
(`.pkg--3s`) — itu penanda tingkat, bukan penanda brand. Hubungan antar-brand
dijelaskan di section **Tentang kami** dan di hero.

Sejak tier dipangkas jadi dua, `.pkgs` memakai `repeat(2,1fr)` lebar penuh —
sama seperti `.brands` di "Tentang kami", jadi keduanya terbaca sebagai sepasang
kartu. Dengan `repeat(4,1fr)` yang lama, dua kartu tersisa hanya mengisi separuh
baris dan menyisakan dua kolom kosong.

**Urutan section dikunci oleh flow Rev 2:**

```
HEADLINE → TRUST → SOLUSI → HARGA → "KAMI BANTU PILIHKAN MENU" → WHATSAPP

hero → logos → sajian → paket → cara-pesan → dapur → masalah → klien → testimoni → brand → faq → kontak
```

`#testimoni` menyusul `#klien` supaya seluruh bukti sosial berkumpul: logo dan
daftar institusi sebagai bukti kelembagaan, kutipan pelanggan sebagai bukti
manusiawi. Urutan inti Rev 2 tetap utuh.

`#cara-pesan` harus tetap **persis setelah** `#paket` — itu sales mechanism
utama halaman, dan Rev 2 menempatkannya menempel pada harga.

---

## Arsitektur brand

```
W3S Catering  (CV. Sehati Sejiwa Sepemikiran)
│
├── Warung Wuenak Poll     → reguler / value      → mulai Rp10.000
└── Kuliner Tradisional 3S → premium / corporate  → mulai Rp35.000
```

Paket dibagi berdasarkan **kelas kebutuhan acara**, bukan tipe customer. Susunan
lama (Corporate / Education / Family) membuat "Corporate" tampil sebagai paket
termurah — merusak positioning 3S.

| Tier | Mulai | Dapur (internal — **tidak ditampilkan di situs**) |
|---|---|---|
| Value Meal by Warung Wuenak Poll | Rp10.000 | Wuenak Poll |
| Premium Indonesian Catering by 3S | Rp35.000 | 3S |

Sebelumnya ada **empat** tier (Rp20.000 / 25.000 / 35.000 / 50.000). Klien
memangkasnya menjadi dua sekaligus menurunkan kedua harganya; "Meeting & Campus"
dan "Corporate & Seminar" dihapus seluruhnya.

Angka di tabel ini adalah satu-satunya sumber untuk **kartu `.pkg`, kartu
`.brand-card`, dan meta description** — ketiganya harus selalu sama. Saat ini:
Value Meal Rp10.000 = brand card WWP Rp10.000 = meta "Mulai Rp10.000/pax".

Deskripsi kartu Premium masih deskripsi yang ditulis untuk tier Rp50.000 lama
(termasuk *snack & dessert*). Isinya belum dikonfirmasi ulang untuk harga mulai
Rp35.000 — lihat daftar terbuka di README.

Kolom ketiga dulu informasi **internal** yang sengaja tidak ditampilkan. Sejak
klien memasukkan nama dapur ke dalam nama tier, pemetaan itu kini terbaca
langsung dari kartunya — lihat aturan `#paket` di atas.

---

## Di mana mengubah sesuatu

Situs statis tanpa build. Titik ungkitnya satu berkas:

- **`assets/css/style.css`** — semua token & komponen. Ubah di blok `:root`.
- **`assets/js/motion.js`** — seluruh animasi.
- **`assets/js/app.js`** — perilaku fungsional. Nomor WhatsApp di baris paling atas.
- **`../tools/make-og.js`** — pembuat og-image (di luar folder deploy).
- **`../tools/make-brand-logos.js`** — pembuat logo brand transparan.
- **`../tools/make-brand-pages-photos.js`** — pengolah foto studio ke `assets/wwp/` & `assets/3s/`.
- **`../tools/make-hero-decks.js`** — 10 foto tumpukan kartu hero ke `assets/hero/`.
- **`../tools/make-pengiriman.js`** — foto armada `#dapur` (potong potret ke 1.19).
- **`../tools/make-seo.js`** — JSON-LD, `sitemap.xml`, `robots.txt`. Konstanta `DOMAIN` ada di sini.
- **`../tools/make-favicon.js`** — favicon dari wordmark, font dirender jadi path.
- **`../tools/serve.js`** — server statis tanpa dependensi untuk pratinjau lokal.

## Hasil verifikasi

Diukur langsung di browser:

- Petrona & Karla termuat (`document.fonts.check` = true)
- `body` — latar `rgb(26,14,10)`, teks `rgb(253,248,240)`
- `h1` — Petrona 700, tracking **+0.4992px** (v1: −1.99px)
- Radius: **hanya 2px** (85 elemen), di kedua halaman
- Hero: kolom foto 460×613, rasio terukur **0.750** = 3/4 = rasio asli foto →
  **nol pemotongan**, di 1440px maupun 1000px
- Tile makanan: lebar 853×427 (**100% foto terpakai**), biasa 426×426
- **Skala render tertinggi di seluruh halaman: 0.52** (`nasi-empal.webp`) pada
  1440px, dan 0.41 pada 375px. Tidak ada satu pun gambar yang diregangkan.
- Urutan section = flow Rev 2 persis:
  `hero → sajian → paket → cara-pesan → dapur → masalah → klien → brand → faq → kontak`
- Logo klien: **9** di atas, **31** di `#klien` (GKA Agape ditambahkan)
- Logo brand: 2 × 104×104 di kartu `#brand`, chip bulat `rgb(253,248,240)`;
  seluruh elemen lain di halaman tetap radius 2px
- Tumpeng: hero = **Komplit 10 Orang**, tile lebar = **Super Premium 20 Orang**
- Katalog menu **190 item**; 26 harga dikoreksi, seluruh header "Mulai RpX"
  dihitung ulang dan konsisten dengan item termurah di grupnya
- Paket: Value Meal Rp20.000 · Meeting & Campus Rp25.000 · Corporate & Seminar
  Rp35.000 · Premium Indonesian Catering Rp50.000. Di dalam `#paket`,
  **"Wuenak Poll" dan "3S" tidak muncul sama sekali** (terukur); keduanya hanya
  muncul di hero dan di section Tentang kami
- Hero h1 48px; ketiga `.reveal-line` masing-masing tepat **1 baris**;
  tinggi hero 772px (sebelum ukuran dikoreksi: 7 baris, 1141px)
- Tidak ada overflow horizontal di 1440px maupun 375px
- 0 gambar hilang, 0 aset yatim
- Katalog menu: 189 item, filter & pencarian berfungsi
- Seluruh tautan WhatsApp ke `6281235773362`
- Kontras: **614 elemen teks diperiksa** (222 di index @1440, 392 di menu @1440,
  plus kedua halaman @375) — **0 gagal**. Ambang WCAG AA: 4.5:1 teks biasa,
  3:1 teks besar/tebal.
- 49 aset diperiksa via HEAD: 0 gagal, 0 gambar rusak, 0 aset yatim
- Di 375px: **0 target sentuh < 36px**, **0 teks < 10.4px**, input 16px,
  ruang bawah cukup untuk bar CTA (88px ≥ 85px), tidak ada elemen melewati layar
- Desktop tidak terpengaruh: bar CTA `display:none`, `padding-bottom` 0,
  input kembali 15.5px, filter 31px, label mikro tetap 10.2px
- Testimoni: 6 kutipan, Petrona 17.9px di atas `--cream-2`, sumber terlabeli
  (WhatsApp / Komentar Instagram), **0 screenshot dipublikasikan**
- Tinggi halaman **12.374px** di 1440px. FAQ tetap 5 pertanyaan.

---

## Halaman brand — `wuenak-poll.html` & `3s.html`

Klien meminta satu halaman untuk tiap brand, dapat diklik dari LP utama, dengan
logo masing-masing. Naskah cerita brand ditulis klien sendiri dan dipakai
**verbatim** di `.story` — jangan disunting saat merapikan copy.

### Pembagian katalog: `menu.html` diganti, bukan ditambah

`menu.html` lama sudah berlabel "Katalog menu — Warung Wuenak Poll" dan memuat
190 item dari sembilan grup. Grup-grup itu pindah ke dua halaman brand tanpa ada
satu item pun yang hilang:

| Halaman | Grup | Item |
|---|---|---|
| `wuenak-poll.html` | Nasi Box WWP, Ayam, Telur & Sayur, Ikan & Seafood, Nasi Goreng/Mie/Bihun, Internasional & Rice Bowl | 172 |
| `3s.html` | Nasi Premium & Signature, Frozen Food, Tumpeng & Hidangan Acara | 18 |

`menu.html` sudah **dihapus** dari proyek setelah kedua halaman brand menyerap
seluruh isinya. Tidak ada lagi yang menautnya, dan tidak ada satu item menu pun
yang hilang dalam perpindahan itu.

Toolbar cari + filter hanya ada di halaman WWP. Di 3S ada 18 item dalam tiga
grup — di sana toolbar adalah perabot yang lebih besar daripada masalah yang
dipecahkannya.

### Suhu halaman datang dari fotonya, bukan dari selera

Ini keputusan desain paling menentukan di dua halaman ini, dan sumbernya empiris:

- **Foto WWP** — studio latar putih, jepretan tegak lurus dari atas. Rata-rata
  warna keempat sudutnya `#E5D7C9`. Token `--cream-2` adalah `#EBE0CE`. Karena
  praktis sama, section galeri WWP memakai `--cream-2` dan tepi foto nyaris
  lenyap — yang tersisa di layar hanya piringnya, mengambang di halaman.
- **Foto 3S** — kayu hangat, daun pisang, gerabah. Rata-rata sudut `#9E8A63`.
  Ini dunia visual LP utama, jadi halaman 3S tetap gelap dan tile-nya sengaja
  terbaca sebagai bidang hangat di atas `--ink`.

Tidak ada warna baru yang diperkenalkan. Aksen 3S memakai `--gold` yang sudah
ada sebagai penanda `.brand-card--premium`, diperluas ke satu halaman lewat
kelas `.t-3s` di `<body>`. Kontras terukur: `--gold` di atas `--ink` = 8.1:1;
teks `#2A1B08` di atas permukaan `--gold` = 7.1:1.

### Kapsi turun ke bawah foto — alasannya bukan gaya

`.plate__cap` di LP utama menumpang di atas foto dengan gradien gelap. Gradien
itu dirancang untuk foto bermeja kayu gelap. Di atas foto WWP yang latarnya
nyaris putih, pita gelap itu mendarat sebagai palang hitam di sepertiga bawah
setiap piring. `.plate--menu` memindahkan kapsi ke bawah foto (`position: static`),
dan sebagai efek samping nama hidangan jadi terbaca sebagai daftar menu — yang
memang fungsi halaman ini.

### Tirai `clip-path` — kenapa bukan naik-memudar

Seluruh situs memakai `[data-reveal]`: naik 18px sambil memudar dari `opacity: 0`.
Untuk foto makanan gerakan itu salah — yang memudar adalah **hidangannya sendiri**,
jadi setengah durasi animasi dihabiskan menampilkan piring setengah transparan.

`.plate--curtain` mematikan peredam itu dan menggantinya dengan
`clip-path: inset(0 0 100% 0)` → `inset(0 0 0 0)`: fotonya utuh dan pekat sejak
frame pertama, yang bergerak hanya batas tirainya. Hanya `clip-path` dan
`transform` yang dianimasikan — keduanya lewat compositor, tanpa layout maupun
paint ulang.

Kurva `--ease-out: cubic-bezier(.23,1,.32,1)` ditambahkan khusus untuk ini.
`--ease` yang lama terlalu lembut untuk gerak sepanjang tinggi penuh satu tile —
sepertiga terakhirnya terbaca menggantung. **Jangan pakai `--ease-out` untuk
hover atau tombol**; di jarak pendek ia terasa patah.

Blok `prefers-reduced-motion` di CSS hanya memangkas *durasi* transisi. Keadaan
awal `clip-path` tetap harus dilepas secara eksplisit — kalau tidak, foto
berangkat dari tersembunyi dan tidak pernah muncul. Aturan itu ada di paling
bawah `style.css`; jangan dihapus.

### Grid galeri: 3 kolom, `.wrap` bukan `.wrap--wide`

Setelah hidangan hero dikeluarkan dan foto kembar Nasi Campur Bakwan Goreng
dibuang, WWP menyisakan **9** tile (tepat 3×3) dan 3S menyisakan **5** (3+2).
Keduanya berbaris terakhir utuh, tidak ada tile yatim — jadi grid `.plates`
bawaan sudah cukup dan tidak ada grid khusus di sini.

`.wrap`, bukan `.wrap--wide`: pada 3 kolom di 1600px satu tile jadi ~530px CSS,
yang di layar 2× menuntut 1060px — di atas sumber 1000px, dan foto mulai
dibesarkan. Di `.wrap` (maks 1320) satu tile ~391px, jadi 781px di 2×. Aman.

Satu-satunya penyimpangan grid: `.plates--brand` menahan **2 kolom** sampai layar
terkecil. `.plates` bawaan turun ke 1 kolom di bawah 560px — benar untuk tile
persegi LP utama, salah di sini: tile 3/4 selebar layar jadi ~500px tinggi, dan
sembilan di antaranya membuat galeri sepanjang 4.500px yang harus digulir habis
sebelum pengunjung sampai ke katalognya. Dengan 2 kolom, galeri turun ke 1.667px.

### Foto yang sengaja tidak dipakai

`tools/make-brand-pages-photos.js` melewati dua berkas, dan alasannya harus
tetap tercatat supaya tidak "diperbaiki" oleh orang berikutnya:

| Berkas | Alasan |
|---|---|
| `3S/Ayam Bakar Bumbu Rujak.png` | Berlatar dekorasi Natal — ranting pinus, bola emas, buah beri, kartu bersalju. Tidak bisa dipakai di halaman yang tayang sepanjang tahun. Pengambilan `… 2.png` adalah adegan yang sama tanpa dekor, dan itu yang dipakai. |
| `WWP/Nasi Campur Bakwan Goreng.png` | Di menu klien ini **satu produk, bukan dua**. Foto resminya adalah versi dengan sambal (`… with Sambal.png`), yang di-`RENAME` menjadi `nasi-campur-bakwan-goreng.webp`. Versi polos dilewati supaya galeri tidak memuat dua tile nyaris identik untuk satu hidangan. |

### Perubahan yang menjalar ke LP utama

Tiga hal berubah di luar dua halaman baru:

1. **Nav** — entri `Menu` diganti `Wuenak Poll` + `3S`, dan `Tentang` dilepas.
   Isinya adalah dua kartu brand yang kini menjadi pintu ke halamannya
   masing-masing, jadi entri nav-nya jadi jalan memutar. Jumlah entri tetap 7.
2. **`.plate:hover img` sekarang dipagari `@media (hover: hover) and (pointer: fine)`.**
   Di layar sentuh `:hover` ikut menyala saat di-tap lalu **nyangkut** sampai tap
   berikutnya — tile membesar dan tidak pernah kembali.
3. **`p.fine > a.link:only-child` → `p.fine > a.link`.** Sejak katalog pecah jadi
   dua brand, ada dua tautan berdampingan di `#sajian`, dan `:only-child` justru
   mencabut sasaran sentuh 40px persis di tempat yang paling membutuhkannya.
4. **Ambang nav 1040px → 1120px, dan `.header-cta { white-space: nowrap }`.**
   Lihat bagian di bawah — ini konsekuensi dari nama brand yang benar.

### Nama brand di nav: "Warung Wuenak Poll", bukan "Wuenak Poll"

Entri nav memakai nama brand lengkap. Itu menambah ~38px ke satu-satunya baris
yang anggarannya paling ketat di situs ini, dan konsekuensinya terukur.

Anggaran header pada satu baris: brand 123 + nav 560 + CTA 231 + dua gap 24 =
**962px isi**. Dengan `--gutter: clamp(20px, 5vw, 72px)`, 962px baru tersedia
di sekitar **1070px** viewport. Pada ambang lama (1040px) hasilnya terukur di
1042px: CTA membungkus jadi **dua baris — 63px di dalam header 76px** — dan nav
diperas 30px di bawah lebar alaminya (530 dari 560).

Dua perbaikan, keduanya wajib dipertahankan:

- Ambang nav dinaikkan ke **1120px**. Di 1130px tersisa 39px kelonggaran; di
  1100px nav sudah tersembunyi dan CTA kembali satu baris (43px). Nav yang
  disembunyikan lebih baik daripada header yang remuk.
- `.header-cta { white-space: nowrap }` — tinggi tombol header dikunci oleh
  header 76px, jadi baris kedua selalu merusak barisnya, berapa pun ambangnya.

Nama pendek "Wuenak Poll" **tetap dipakai di dua tempat dan itu disengaja**:
naskah hero `.lead` dan meta description/og. Keduanya naskah klien dari Rev 2 —
jangan disunting untuk "konsistensi". Begitu pula `<h2>Nasi Box Wuenak Poll</h2>`
di katalog: itu nama kategori produk dari katalog klien, bukan label navigasi.

Entri "3S" sengaja dibiarkan pendek, bukan "Kuliner Tradisional 3S" — klien
sendiri menulisnya begitu di naskah mereka ("3S — Kuliner Tradisional Premium,
…"), dan memanjangkannya akan menambah ~150px lagi ke anggaran yang sudah ketat.

### Hasil verifikasi halaman brand

Diukur langsung di browser, keadaan reveal dipaksa selesai lebih dulu supaya yang
diukur adalah halaman sebagaimana dilihat pengunjung:

| | index | wuenak-poll | 3s |
|---|---|---|---|
| Elemen diperiksa | 576 | 806 | 174 |
| Gagal kontras @1440 | 0 | 0 | 0 |
| Gagal kontras @375 | — | 0 | 0 |
| Sasaran sentuh < 36px @375 | — | 0 | 0 |
| Overflow horizontal | tidak | tidak | tidak |
| Gambar rusak | 0 | 0 | 0 |

- 401 rujukan `href`/`src` di empat halaman diperiksa → **0 rusak**, 0 anchor mati.
- 207 berkas aset → **0 yatim**.
- Tinggi hero halaman brand 760px @1440, sejajar hero LP utama (772px).
  Ketiga `.reveal-line` masing-masing tepat satu baris (55px) — tidak membungkus.
- Header diuji di titik tersempit: 1130px → nav satu baris, CTA 43px, sisa 39px;
  1100px → nav tersembunyi, CTA 43px; 375px → nav & CTA tersembunyi, galeri 2 kolom.
  Tidak ada elemen header yang diperas di lebar mana pun yang diuji.
- Katalog WWP diuji langsung: filter `ikan` → 23 item / 1 grup; cari `geprek` →
  1 item; cari `zzzz` → 0 item + pesan kosong tampil; reset → 172 item.

**Belum terverifikasi:** animasi tirai tidak pernah diamati berjalan. Panel
browser tidak ditampilkan selama sesi ini, sehingga `document.visibilityState`
tetap `hidden` dan `requestAnimationFrame` tidak pernah berjalan — di tab yang
tidak dirender, IntersectionObserver dan transisi CSS ikut beku. Yang terbukti
hanyalah bahwa kelima aturannya terurai benar di CSSOM, selektornya cocok, dan
dengan transisi dimatikan `clip-path` berpindah dari `inset(0px 0px 100%)` ke
`inset(0px)` saat `.is-in` ditambahkan. Waktu, kurva, dan stagger-nya masih perlu
dilihat langsung di browser biasa.

---

## Revisi lanjutan klien — logo, harga, dan isi katalog

### Logo brand ditempel di setiap foto studio

Semua 16 foto di `assets/wwp/` dan `assets/3s/` membawa logo brandnya di sudut
kanan bawah. Ditempel oleh `tools/make-brand-pages-photos.js`, bukan diedit
manual — jadi menjalankan ulang skrip itu selalu menghasilkan berkas yang sama.

Dua hal yang menentukan implementasinya:

**Ukuran tidak bisa disamakan begitu saja.** Setelah `trim()`, WWP menyisakan
177×312 (tegak, rasio 0.57 — bentuknya panggangan berkaki) sedangkan 3S
menyisakan 396×402 (nyaris bujur sangkar). Menyamakan LEBAR membuat WWP menjulang
dua kali lipat; menyamakan TINGGI membuatnya terlihat kurus. Keduanya dimuat ke
kotak berbeda lewat `fit: inside` — WWP 196, 3S 132 — dan angka itu didapat
dengan membandingkannya pada **ukuran render sebenarnya (391px)**, bukan pada
berkas 1000px. Kalau ukuran tile berubah, ukur ulang di sana.

**Dua lintasan sharp, bukan satu rantai.** Di sharp, `resize` selalu dijalankan
sebelum `composite` kalau keduanya ada di rantai yang sama — logonya akan ikut
diperkecil bersama fotonya dan posisinya meleset. Foto di-`toBuffer()` dulu,
baru logonya di-`composite` di lintasan kedua.

Tidak perlu chip terang di belakang logo: bagian putih pada kedua logo terkurung
di dalam bidang gelap (mangkuk hitam WWP) atau bidang merah (lingkaran 3S), jadi
tidak ada bagian yang lenyap di atas latar terang foto WWP. Ini berbeda dengan
logo di `.brand-mark` dan `.crosslink__logo`, yang duduk di atas `--ink` dan
tetap membutuhkan chip.

Sudut kanan bawah dipilih setelah memeriksa keenam belas foto: pada semua
jepretan itu area tersebut latar meja, bukan hidangan.

### Harga

Angka headline WWP turun dari Rp12.000 ke **Rp10.000** di judul, meta, og,
headline hero, baris janji, tanda tangan cerita, dan tautan silang di halaman 3S.
3S sudah Rp35.000 di semua tempat sejak awal dan tidak berubah.

**Header minimum tiap grup menu TIDAK ikut diturunkan.** Angka itu dihitung dari
harga item di dalam grupnya, bukan angka pemasaran. Grup "Nasi Box Wuenak Poll"
memang benar-benar mulai Rp12.000 (tiga item berharga: Rp12.000–Rp15.000);
menurunkannya ke Rp10.000 akan menjadikannya klaim yang salah.

Baris janji hero berubah dari "Nasi box mulai Rp12.000" menjadi "Menu mulai
Rp10.000" karena alasan yang sama: Rp10.000 benar untuk *menunya*, tidak benar
untuk *nasi box*-nya.

### Katalog: harga WWP disembunyikan, foto 3S dilepas

| | Sebelum | Sesudah |
|---|---|---|
| Katalog WWP | 6 header "Mulai Rp…" + 137 lencana harga per foto | tanpa harga sama sekali |
| Katalog 3S | 1 grup berfoto + 2 grup teks | 3 grup teks, tanpa foto |

Keduanya permintaan klien. Item tidak ada yang hilang: WWP tetap 172, 3S tetap 18
(grup premium yang tadinya "1 item berfoto + 6 item di sublist *Menu lain di
kategori ini*" digabung jadi satu daftar teks berisi 7, urut abjad; subjudulnya
ikut hilang karena tidak ada lagi daftar berfoto di atasnya).

Konsekuensi yang disengaja: satu-satunya harga yang tersisa di halaman WWP adalah
angka headline "mulai Rp10.000" di hero. Katalognya sendiri mengarahkan orang
bertanya lewat WhatsApp — itu memang tujuan halamannya.

`menu.html` sudah dihapus dari proyek. Setelah itu hanya **satu** aturan yang
benar-benar menjadi mati, dan sudah dibuang dari `style.css`:

```css
.mi__p { position: absolute; top: 8px; right: 8px; background: var(--ink); color: var(--paper); font-size: .7rem; font-weight: 700; padding: 4px 8px; border-radius: var(--r); font-variant-numeric: tabular-nums; }
```

Itu lencana harga di atas foto item. Kalau klien suatu saat ingin harga tampil
lagi di katalog, tempel kembali baris di atas tepat sebelum `.menu-sub`.

`.menu-list--photo` (6 pakai), `.mi__n` (137), `.menu-sub` (6), dan
`.menu-group__price` (3, di halaman 3S) **masih terpakai** — jangan ikut dibuang.

Satu aset menjadi yatim setelah foto katalog 3S dilepas:
`assets/menu/nasi-empal.webp`. Dibiarkan di tempatnya, bukan dihapus — keputusan
hapus ada di pemilik proyek.

---

## Analytics — satu-satunya permintaan ke luar

Google Analytics 4, properti **milik klien** (`G-411D6LYS5E`), dipasang atas
permintaan mereka. Snippet `gtag.js` ada di `<head>` ketiga halaman.

Sebelum ini situs punya **nol** permintaan eksternal. Itu bukan kebetulan — font
di-self-host justru supaya begitu. Menambah gtag.js adalah trade-off yang diambil
sadar, bukan kecolongan: klien butuh datanya. Kalau suatu hari halaman terasa
berat atau terblokir jaringan kantor, ini tersangka pertama.

### Kenapa gtag TIDAK diletakkan tepat di bawah `<head>`

Panduan Google bilang "immediately after the `<head>` element". Di sini ia
diletakkan **sesudah blok `<link rel="preload">`**, dan itu disengaja: baris
preload menentukan prioritas unduh font dan foto hero (LCP halaman ini). Skrip
gtag sendiri `async` dan berprioritas rendah, jadi menggesernya beberapa baris
tidak mengubah akurasi pengukuran — tapi menaruhnya di atas preload membuatnya
ikut mengantre di depan gambar hero.

### Klik WhatsApp harus dilacak manual — ini bagian yang penting

Konversi situs ini adalah klik WhatsApp, dan itu **membawa pengunjung keluar**
ke `wa.me`. Buat GA4 default, pengunjung yang berhasil menghubungi klien terlihat
persis sama dengan pengunjung yang membuka lalu langsung menutup tab: dua-duanya
bounce. Angka yang paling ingin diketahui klien justru satu-satunya yang tidak
terekam.

`app.js` menembakkan event `generate_lead` (nama yang direkomendasikan GA4, jadi
muncul di laporan tanpa konfigurasi tambahan) dengan parameter:

| Parameter | Isi |
|---|---|
| `method` | `whatsapp` atau `form` |
| `link_location` | header · hero · sticky-cta · footer · tautan-silang · blok-penawaran · katalog-kosong · `paket: <nama tier>` · id section |
| `page_brand` | `utama` · `wuenak-poll` · `3s` |
| `link_text` | teks tombolnya |

Lokasi **disimpulkan dari struktur halaman** (`el.closest(...)`), bukan dari
atribut `data-*` per tombol. Dengan ~18 tombol tersebar di tiga halaman, atribut
manual pasti ada yang terlewat saat markup diubah; resolver di `lokasiTombol()`
tidak bisa lupa.

### Dua penjaga yang wajib dipertahankan

1. **`typeof gtag !== 'function'` di `lacak()`.** Pemblokir iklan memblokir
   `googletagmanager.com` dengan sangat umum. Tanpa penjaga ini setiap klik
   tombol WhatsApp melempar `ReferenceError`. Sudah diuji: dengan `gtag` dihapus
   paksa, klik tetap bersih tanpa error dan `href` wa.me tetap benar.
2. **`try/catch` di dalamnya.** Analytics tidak boleh punya kemampuan merusak
   tombol yang menghasilkan uang.

### ID pernah salah satu karakter — begini cara memastikannya

ID pertama yang dipasang **salah**: `G-411D6LY55E`, dibaca dari screenshot —
padahal yang benar `G-411D6LYS5E`. Karakter ke-8 adalah huruf **S**, terbaca
sebagai angka **5**.

Konsekuensinya tidak terlihat sama sekali: GA **tidak melempar error apa pun**
untuk measurement ID yang tidak ada. Skripnya tetap memuat, `gtag()` tetap
terdefinisi, `window.google_tag_manager` tetap terbentuk, konsol tetap bersih.
Satu-satunya gejala adalah data yang tidak pernah muncul di dasbor klien —
dan itu baru ketahuan berhari-hari kemudian.

**Jangan pernah menyalin measurement ID dari screenshot.** Minta teks yang
di-copy langsung dari konsol GA. Pasangan karakter yang mudah tertukar di font
monospace: `S`/`5`, `O`/`0`, `I`/`l`/`1`, `B`/`8`, `G`/`6`, `Z`/`2`.

### Cara membuktikan gtag benar-benar termuat

Jangan memakai `typeof gtag === 'function'` sebagai bukti — fungsi itu
didefinisikan **inline** oleh snippet dan tetap ada meski skrip Google gagal
dimuat. Yang membuktikan skripnya benar-benar dieksekusi adalah:

```js
typeof window.google_tag_manager   // 'object'
typeof window.google_tag_data      // 'object'

// dan ini yang membuktikan ID-nya diterima, bukan sekadar skripnya jalan:
Object.keys(window.google_tag_manager).filter(k => /^G-/.test(k))
// -> ["G-411D6LYS5E"]
```

Keduanya hanya dibuat oleh `gtag.js` milik Google. Catatan: panel network di
pratinjau tidak mencatat permintaan lintas-origin, jadi tidak munculnya
`googletagmanager.com` di sana **bukan** tanda kegagalan.

### Belum dikerjakan

- **Kebijakan privasi.** GA menulis cookie dan mengirim data ke Google. Untuk
  Indonesia (UU PDP) biasanya cukup ditutup halaman kebijakan privasi; situs ini
  belum punya. Consent mode EEA tidak relevan untuk pasar Surabaya–Sidoarjo.
- **Pelacakan kata kunci pencarian katalog.** Kolom cari di halaman WWP tahu apa
  yang dicari orang dan tidak ketemu — sinyal permintaan nyata yang belum ada di
  menu. Perlu debounce supaya tidak menembak tiap ketikan.

---

## Hero index — dua tumpukan kartu

Foto tumpeng tunggal diganti dua tumpukan berkartu lima, satu per brand, dari
folder `Update 4`. Sepuluh foto itu berbeda kelas dari set sebelumnya: **latarnya
gelap hangat** (3S beludru merah, WWP kayu walnut) dan **logo brand sudah
tercetak di dalam frame** oleh fotografernya.

### Referensi klien: HUD Iron Man — yang dipinjam hanya separuhnya

Yang membuat HUD Iron Man terbaca tiga dimensi bukan warna cyan atau garis
wireframe-nya, melainkan **parallax**: lapisan di kedalaman berbeda bergeser
dengan laju berbeda saat sudut pandang berubah. Itu mekanik murni dan netral.

Tampilan holografiknya sengaja **tidak** diambil. Biru dingin dan glow di atas
makanan Indonesia hangat akan melawan dunia "Warm Table" — dan v1 halaman ini
sudah pernah ditolak klien justru karena terasa dingin ("kerasa AI"). Ambil
kedalamannya, buang sci-fi-nya.

Kedalamannya nyata, bukan tiruan: `.decks` memegang `perspective: 1100px`,
`.deck` memegang `transform-style: preserve-3d`, tiap kartu duduk di
`translateZ` sendiri (0 / −26 / −52 / −78 / −104 px). Memiringkan `.deck`
membuat kartu di kedalaman berbeda bergeser dengan laju berbeda — tidak ada
lapisan yang digeser manual.

### Kenapa tumpang tindih, bukan berdampingan

Klien meminta **dua tumpukan kecil berdampingan**. Itu dicoba lebih dulu dan
diukur — hasilnya tidak layak, dan angkanya yang memutuskan:

| | Berdampingan | Tumpang tindih |
|---|---|---|
| Lebar kartu depan @1440 | **219px** | **289px** |
| Dibanding thumbnail katalog (280px) | lebih kecil | lebih besar |
| Dibanding foto hero lama (460px) | 48% | 63% |

Kolom hero tidak bisa dilebarkan untuk memperbaikinya. Baris pertama headline
Rev 2 ("Konsumsi meeting, seminar") menuntut **621px** sedangkan kolom kirinya
**644px** — hanya **23px** kelonggaran. Melebarkan kolom kanan lebih dari itu
membuat headline pecah jadi empat baris dan merusak struktur tiga `.reveal-line`
yang sudah dikunci. Bahkan setelah memakan seluruh 68px pelipit kanan,
berdampingan hanya sampai 265px.

Jadi tumpang tindih bukan pilihan gaya; itu satu-satunya cara mendapat kartu
seukuran makanan di hero ini. `.deck` masing-masing 55% lebar wadah, bertindih
10% (~76px, sekitar 26% lebar kartu), tumpukan 3S di atas dan turun 54px.

Bonusnya kebetulan tepat sasaran: dua tumpukan yang saling menindih adalah
gambar harfiah dari posisi W3S — **dua brand, satu dapur**.

### Pembagian tugas CSS / JS — jangan ditukar

Seluruh geometri kipas ada di `style.css`. `motion.js` hanya memutar satu angka:
properti `--i` tiap kartu (0 = paling depan). Mau mengubah sudut, jarak, atau
arah kipas — ubah di CSS, jangan di JS.

Kipasnya naik **lurus ke atas**, bukan melebar ke samping: dua tumpukan berbagi
satu kolom, jadi kipas yang melebar menabrak kartu depan tetangganya.

Perilaku: kartu depan mundur ke belakang tiap 5,2 detik (kedua tumpukan digeser
2,6 detik supaya tidak berbalik serempak — kalau serempak gerakannya terbaca
mekanis), berhenti saat hero keluar layar, dan bisa dimajukan dengan menekan
tumpukannya. Menekan adalah satu-satunya cara maju di layar sentuh.

`prefers-reduced-motion`: kipasnya tetap ditampilkan — itu tata letak, bukan
animasi — tapi tilt dan pergantian otomatis dimatikan seluruhnya.

### Hasil ukur

| | Nilai |
|---|---|
| Kartu depan @1440 | 289×368 (butuh 578px di layar 2x; sumber 700px) |
| Kartu depan @375 | 184×246 — 49% lebar layar (di desktop hanya 20%) |
| Tinggi hero @1440 | **714px** — lebih pendek dari hero tumpeng lama (772px) |
| Baris headline | tetap 3 × 53px, tidak membungkus |
| Kontras / overflow / gambar rusak | 0 / tidak / 0 (574 elemen diperiksa) |

Siklus pergantian diuji: lima kali maju mengembalikan urutan ke awal.
Kedalaman terverifikasi merata lewat `DOMMatrix` pada keadaan akhir.

**Belum terverifikasi: tilt mengikuti kursor.** Handler-nya berjalan di dalam
`requestAnimationFrame`, dan panel pratinjau berkali-kali masuk keadaan
tersembunyi di mana rAF beku. Yang terbukti hanyalah prasyaratnya — `perspective`
di wadah, `preserve-3d` di deck, `translateZ` berbeda per kartu, dan handler
terpasang (`cursor: pointer` menyala). Sudut dan rasa gerakannya masih perlu
dilihat di browser biasa.

### Aset yang menjadi yatim

`assets/event/hero-tumpeng.webp` tidak lagi dirujuk halaman mana pun setelah
hero diganti. Dibiarkan di tempatnya, bukan dihapus — keputusan hapus ada di
pemilik proyek. (Yang lain: `assets/menu/nasi-empal.webp`, yatim sejak foto
katalog 3S dilepas.)

---

## Band tumpeng — layar kedua `index.html`

Permintaan klien (revisi #2): tumpeng ditampilkan di layar kedua dengan kalimat
**"Kami juga menyediakan tumpeng"**. Kalimat itu dipakai apa adanya sebagai
heading — naskah mereka, bukan karangan kami.

### Penempatan: sesudah band logo, bukan menyela hero

Urutan sekarang: `hero → logos → tumpeng → sajian → paket → cara-pesan → …`

Menyisipkannya antara hero dan logo akan memutus urutan `hero → logos` yang
dikunci Rev 2 (logo adalah bukti sosial yang menyusul headline). Tidak perlu:
diukur di 1440 dengan viewport 900, hero 714px + band logo 264px membuat band
tumpeng mulai di **y = 1055px**, yaitu **1,17 layar** dari atas. Ia sudah berada
di layar kedua tanpa memindahkan apa pun. Adjacency `#cara-pesan` tepat setelah
`#paket` juga tetap utuh.

### Fotonya adalah bekas hero, bukan aset baru

`assets/event/hero-tumpeng.webp` (1086×1448) menganggur sejak hero index diganti
dua tumpukan kartu. Band ini memberinya rumah baru — tidak ada foto baru yang
perlu diminta ke klien, dan folder deploy kembali nol berkas yatim.

Bingkainya `aspect-ratio: 3/4` = rasio asli fotonya, jadi tidak ada satu piksel
pun yang dibuang. Skala render **0.35** di 1440 (380px dari sumber 1086px) —
jauh di bawah ambang 0.62, dan 760px yang dituntut layar 2x masih di bawah
sumber.

### Foto di KIRI — ini aturan ritme, bukan selera

Hero, band ini, dan `#dapur` sama-sama memakai susunan terpisah teks/foto. Kalau
ketiganya menaruh foto di kanan, sisi kanan halaman menjadi satu pita gambar
vertikal dan halamannya terbaca miring. Band ini menaruh fotonya di kiri.
**Kalau nanti ada blok terpisah keempat, taruh fotonya di kanan** — teruskan
selang-selingnya.

Di bawah 760px foto turun ke bawah teks (`order`), bukan naik ke atas seperti
hero. Alasannya berbeda dari hero: di sana fotonya adalah daya tarik pertama, di
sini judulnya yang menjelaskan. Tumpeng tanpa kalimatnya mudah dikira hidangan
biasa, jadi kalimatnya harus lebih dulu.

`h2`-nya sengaja lebih kecil dari h2 global (`clamp(1.7rem, 3vw, 2.6rem)`): ini
pesan pendamping dan tidak boleh bersaing dengan "Sajian unggulan" tepat di
bawahnya.

### Hasil ukur

| | Nilai |
|---|---|
| Mulai di | y=1055px @1440/900 — 1,17 layar dari atas |
| Tinggi band | 763px |
| Foto render | 380×507 (skala 0.35) |
| Gagal kontras | 0 |
| Overflow / gambar rusak | tidak / 0 |

---

## Foto armada `#dapur` diganti — dan slotnya ditukar

Revisi klien #6: foto "kresek di dalam mobil" diganti foto armada bermerek.
Foto lama (`assets/event/armada-muat.webp`) memperlihatkan kantong oranye di jok
belakang mobil pribadi — terbaca seperti orang mengantar titipan, bukan operasi
katering. Foto baru memperlihatkan kendaraan berlogo Warung Wuenak Poll
bertuliskan "Kantong Aman — Puluhan Menu Beragam", lengkap dengan handle
Instagram dan nomor WhatsApp yang terbaca.

### Kenapa slot a dan b ditukar

Ini bukan kosmetik; ini aturan foto nomor satu yang dijalankan.

Foto armada baru berformat **potret 1200×1600**. Slot `.proof__a` berasio
**16/10**; memasang potret di sana membuang lebih dari separuh tingginya.
Sementara `produksi-box.webp` berukuran **1600×1000 = rasio 1.600**, yaitu 16/10
**persis** — selama ini ia duduk di slot `b` (1.19) dan terpotong sia-sia.

Menukar keduanya memperbaiki dua foto sekaligus:

| Slot | Rasio bingkai | Isi sekarang | Rasio foto | Terbuang |
|---|---|---|---|---|
| `.proof__a` | 1.60 | `produksi-box.webp` | 1.600 | **0%** |
| `.proof__b` | 1.19 | `pengiriman-armada.webp` | 1.19 | **0%** |

Kapsi ikut berpindah bersama fotonya — tidak ada naskah baru yang dikarang.

### Jendela potong foto armada

Sumber potret dipotong ke 1.19 di `tools/make-pengiriman.js`, dengan jendela
**0.22 dari atas**. Angka itu dipilih setelah membandingkan tiga posisi: hanya
jendela ini yang mempertahankan muatan penuh, **kedua** panel "Kantong Aman",
nomor WhatsApp, sekaligus menyisakan kanopi di bagian atas. Tanpa kanopi, fotonya
terbaca seperti rak penyimpanan, bukan kendaraan.

### Sisa pemotongan di slot lain — sudah ada sebelumnya

`.proof__c/d/e` berbingkai 4/3 (1.33) sedangkan isinya berasio 1.56–1.60, jadi
terbuang 14–17%. Itu keadaan lama, bukan akibat perubahan ini. Kalau suatu saat
dirapikan, ubah bingkainya ke 16/10 — jangan memotong fotonya lebih jauh.

`assets/event/armada-muat.webp` menjadi yatim setelah penggantian ini. Dibiarkan
di tempatnya, bukan dihapus.

### Baris bawah `#dapur` dirapikan — dan satu cacat mobile ikut ketahuan

Bingkai `.proof__c/d/e` diubah dari **4/3 ke 16/10**, menyamakannya dengan
`.proof__a`. Dua alasan, dan yang kedua lebih penting:

1. **Rasio.** Ketiga fotonya berasio 1.556–1.600; di bingkai 4/3 (1.333)
   terbuang 14–17%.
2. **Isi.** Ketiganya bukti **kapasitas** — barisan prasmanan, hamparan enam
   tumpeng, deretan box. Bingkai lebih lebar memperlihatkan lebih banyak
   barisannya, yang justru pesan section ini.

| Slot | Foto | Terbuang sebelum | Sesudah |
|---|---|---|---|
| `.proof__c` | prasmanan-lontong (1.600) | 17% | **0%** |
| `.proof__d` | tumpeng-grand-opening (1.556) | 14% | **2.8%** |
| `.proof__e` | box-siap-kirim (1.556) | 14% | **2.8%** |

Sisa 2.8% tidak bisa dihilangkan tanpa memberi ketiganya rasio berbeda — dan itu
tidak mungkin: ketiganya sebaris dengan span sama, jadi rasio berbeda membuat
tingginya berbeda dan `align-items: stretch` akan menimpa `aspect-ratio`-nya.
16/10 dipilih di atas 14/9 (yang totalnya 2.8% vs 5.6%) karena menyamakan baris
ini dengan `.proof__a` — empat dari lima slot kini satu rasio.

#### Cacat mobile yang ditemukan saat mengukurnya

**Ini lebih parah daripada soal potongan, dan sudah ada sejak lama.** Pada 375px,
`.proof__c/d/e` berbagi dua kolom sehingga tiap tile hanya **167×125px**,
sementara `figcaption`-nya setinggi **128–155px** — yaitu **103–124% tinggi
tile**. Kapsi menutupi seluruh foto dan meluber keluar bingkai; ketiga tile itu
praktis blok teks dengan foto tak terlihat di belakangnya.

Melebarkan bingkai ke 16/10 justru memperburuknya (149%), jadi rasio saja tidak
cukup. Perbaikannya: **di bawah 760px ketiganya selebar penuh**, sama seperti
`.proof__a/b`.

| | Tile @375 | Kapsi menutupi |
|---|---|---|
| Sebelum (2 kolom, 4/3) | 167×125 | **103–124%** |
| Sesudah (1 kolom, 16/10) | 335×209 | **42–51%** |

42–51% itu angka yang sama persis dengan `.proof__a/b` di lebar yang sama, jadi
seluruh mosaik kini seragam. Diuji juga di 800px (zona dua kolom): 352×220,
kapsi 40–49% — aman.

**Aturan turunannya:** kalau suatu saat ada tile `.proof` baru, jangan pernah
membiarkannya lebih sempit dari ~330px selama `figcaption` masih memuat judul
plus satu kalimat. Ukur kapsinya terhadap tinggi tile, bukan hanya rasio fotonya.

---

## SEO

Yang sudah ada sejak awal: `<title>` unik per halaman, meta description, canonical,
Open Graph, `robots` meta, `lang="id"`, satu `h1` per halaman, alt di semua gambar.
Yang ditambahkan di putaran ini:

| Berkas / elemen | Isi |
|---|---|
| `favicon.svg` + `favicon-32.png` + `apple-touch-icon.png` | wordmark W3S, dibuat `tools/make-favicon.js` |
| `robots.txt` | izinkan semua + penunjuk sitemap |
| `sitemap.xml` | 3 URL, dibuat `tools/make-seo.js` |
| JSON-LD `index.html` | `WebSite` + `Caterer`/`LocalBusiness` + `FAQPage` |
| JSON-LD halaman brand | `BreadcrumbList` + `WebPage` |
| `og:url`, `og:site_name`, `og:image:width/height`, `twitter:image` | ketiga halaman |

### `og:image` sebelumnya relatif — itu bug, bukan kekurangan

Nilainya `assets/img/og-image.jpg`. Perayap Facebook, WhatsApp, dan LinkedIn
menuntut **URL absolut**; dengan path relatif, thumbnail bisa gagal muncul sama
sekali padahal berkasnya ada. Sekarang absolut, plus `og:image:width/height`
supaya kartu pratinjau tidak berkedip saat gambar dimuat.

### FAQ schema dibuat skrip, bukan diketik — dan itu disengaja

Google mensyaratkan isi `FAQPage` **cocok persis** dengan teks yang tampil.
JSON-LD yang diketik ulang akan menyimpang diam-diam begitu ada yang menyunting
FAQ, dan penyimpangan itu **tidak memunculkan error apa pun** — persis pola
kegagalan yang sama dengan measurement ID yang salah karakter.

`tools/make-seo.js` membaca pertanyaan dan jawaban langsung dari `index.html`,
jadi keduanya tidak mungkin berbeda. Penyisipannya dibatasi penanda
`<!-- SEO:JSON-LD mulai/akhir -->` sehingga aman dijalankan berulang.

**Jalankan ulang `node make-seo.js` setiap kali FAQ, nomor telepon, atau domain
berubah.** Terverifikasi: kelima Q&A di JSON-LD cocok karakter-per-karakter
dengan DOM.

### Yang sengaja TIDAK ditulis di structured data

**Alamat jalan.** W3S adalah usaha berbasis area layanan dan alamat dapurnya
belum diberikan klien. Yang ditulis hanya `addressRegion: Jawa Timur` +
`addressCountry: ID` — keduanya benar — ditambah `areaServed` Surabaya dan
Sidoarjo. Menuliskan jalan yang tidak diketahui akan menjadi data palsu di
structured data, dan itu risiko penalti, bukan sekadar ketidakakuratan.

Konsekuensinya: rich result `LocalBusiness` kemungkinan tidak muncul penuh
sampai alamat dapur diberikan. Itu ditukar dengan kejujuran data, dan alamatnya
sudah masuk daftar permintaan aset di README.

**Sertifikat halal** ditulis sebagai `additionalProperty`/`PropertyValue`, bukan
`hasCertification` — properti itu masih baru dan belum seragam didukung.

### Domain: www adalah yang primer — dipastikan dari server, bukan diputuskan

Pertanyaan apex-vs-www **tidak jadi diputuskan lewat selera** — jawabannya sudah
ada di server, dan baru ketahuan saat menyelidiki peringatan GA:

```
https://w3scatering.com/       -> HTTP 308 Permanent Redirect -> www
https://www.w3scatering.com/   -> HTTP 200
```

Vercel sudah dikonfigurasi dengan **www sebagai primer**, dan data stream GA
klien juga terdaftar sebagai `https://www.w3scatering.com/`.

Sementara itu canonical yang kami tulis menunjuk `https://w3scatering.com/` —
yaitu URL yang justru dialihkan pergi. Halaman berkata "versi kanonik saya adalah
apex", server berkata "apex bukan alamat sebenarnya". Google biasanya
menyelesaikannya dengan mengikuti redirect, tetapi itu sinyal yang saling
bertentangan dan membuang kekuatan crawl.

**Sudah diselaraskan ke www di 43 tempat** — canonical, `og:url`, seluruh `@id`
JSON-LD, `sitemap.xml`, `robots.txt`, dan tautan di `email/`.

Kalau suatu saat primernya dibalik ke apex, ubah `DOMAIN` di `tools/make-seo.js`,
jalankan ulang, lalu perbarui `<link rel="canonical">` di ketiga halaman dan
tautan di `email/` — canonical masih ditulis manual di tiap halaman.

### Barcode: tiga tag tambahan, dimuat bersyarat

Klien memasang barcode di tiga tempat dan membuat tiga properti Google terpisah
untuk masing-masing:

| Penanda URL | Properti | Penempatan |
|---|---|---|
| `?qr=fasilitas` | `AW-18385370495` (Google **Ads**) | Fasilitas umum |
| `?qr=kendaraan` | `G-RHTL2KV3BG` (GA4) | Kendaraan umum |
| `?qr=kemasan` | `G-MMY22EZ455` (GA4) | Barcode kemasan |

**Ketiganya TIDAK dipasang di setiap halaman, dan itu bukan kelalaian.** Kalau
semuanya menyala di tiap kunjungan, ketiga properti akan menampilkan angka yang
persis sama — total seluruh pengunjung situs — dan tidak satu pun bisa menjawab
barcode mana yang dipindai. Halaman yang dibuka memang sama; yang berbeda hanya
dari mana orang datang.

Yang membedakan penempatan adalah **URL di dalam barcode**, bukan tag-nya. Jadi
tiap barcode membawa `?qr=`, dan tag lokasi itu hanya dimuat kalau penandanya
cocok. Kunjungan biasa tidak memuat satu pun tag tambahan.

Selain memuat tag lokasi, kode juga menembakkan event `qr_scan` ke properti
**utama** dengan parameter `qr_lokasi`. Itu yang membuat ketiga penempatan bisa
**dibandingkan berdampingan dalam satu laporan** — sesuatu yang tidak mungkin
dilakukan kalau datanya terpisah di tiga properti.

`AW-18385370495` adalah tag Google **Ads**, bukan Analytics. Ia tidak melaporkan
jumlah pemindaian; gunanya membangun audiens remarketing (mis. menayangkan iklan
kepada orang yang pernah memindai barcode di fasilitas umum) dan melacak
konversi iklan. Kalau yang diinginkan adalah *menghitung* pemindaian fasilitas
umum, itu datang dari event `qr_scan` di properti utama, bukan dari tag ini.

#### Dua penanda, bukan satu — dan kenapa

Klien ternyata sudah menyusun skema UTM sendiri dan memasukkannya ke Stream URL
di Google Analytics, mis. `?utm_source=fasilitas_umum&utm_medium=qr`. Kode versi
pertama hanya mengenali `?qr=`, jadi URL yang sudah mereka pakai tidak memuat
tag apa pun dan GA melaporkan *"Your Google tag wasn't detected"*.

Sekarang keduanya dikenali: penanda pendek `?qr=` **dan** `utm_source`.

Pencocokan `utm_source` memakai **kata kunci**, bukan string persis — ejaan
sumber bisa berbeda di tiap properti (`fasilitas_umum` / `Fasilitas-Umum` /
`fasilitasumum`) dan mencocokkan persis akan patah pada variasi pertama.
Terverifikasi: `Barcode-Kemasan` (huruf besar + tanda hubung) tetap cocok, dan
`utm_source=instagram` tidak memicu apa pun.

#### Peringatan "tag wasn't detected" di wizard GA itu WAJAR

Wizard GA menguji dengan memuat sebuah URL lalu memeriksa apakah tag propertinya
ada di sana. Kalau diuji dengan URL polos tanpa penanda, tag memang sengaja
tidak dimuat — itu justru inti rancangannya. Yang menentukan bukan centang di
wizard, melainkan apakah Realtime menerima data saat URL barcode yang
sesungguhnya dibuka.

Kodenya di `assets/js/app.js` bagian 3. Untuk memaksa ketiganya menyala di semua
kunjungan, hapus penjaga `if (titik)` — tapi baca dulu paragraf kedua di atas.

**Hasil uji:**

| Skenario | Container termuat | `qr_scan` |
|---|---|---|
| tanpa `?qr=` | hanya `G-411D6LYS5E` | 0 |
| `?qr=fasilitas` | + `AW-18385370495` | 1 |
| `?qr=kendaraan` | + `G-RHTL2KV3BG` | 1 |
| `?qr=kemasan` | + `G-MMY22EZ455` | 1 |
| `?qr=` nilai asing | hanya `G-411D6LYS5E` | 0 |

Nol error konsol, tombol WhatsApp tetap normal di semua skenario.

### Vercel memakai Clean URLs — semua tautan harus tanpa `.html`

Ketahuan saat memverifikasi deploy: Vercel mengalihkan **308** dari versi
ber-ekstensi ke versi tanpa ekstensi.

```
/index.html        -> 308 -> /
/wuenak-poll.html  -> 308 -> /wuenak-poll
/3s.html           -> 308 -> /3s
```

Akibatnya, canonical/`og:url`/JSON-LD yang menulis `.html` menunjuk URL yang
**dialihkan pergi** — kelas bug yang sama persis dengan apex-vs-www, hanya sumbu
berbeda. Halaman yang disajikan di `/wuenak-poll` menyatakan kanoniknya
`/wuenak-poll.html`, yang mengalihkan balik ke dirinya sendiri. Dua dari tiga URL
di `sitemap.xml` juga mengembalikan 308, bukan 200.

Seluruh rujukan sudah dipindahkan ke bentuk **root-relative tanpa ekstensi**
(`/`, `/wuenak-poll`, `/3s`): tautan nav, canonical, `og:url`, `@id` JSON-LD,
sitemap, dan tautan di `email/`. Efek sampingnya juga menghilangkan satu hop
redirect di setiap navigasi antar-halaman.

**`tools/serve.js` diberi fallback yang sama** — kalau path tanpa ekstensi tidak
ada, ia mencoba `<path>.html`. Tanpa itu, tautan tanpa ekstensi akan 404 di
lokal padahal benar di produksi, dan bug URL semacam ini tidak mungkin ketahuan
sebelum deploy.

**Aturan: jangan pernah menulis `.html` di tautan internal.**

Temuan sampingan: `email/email.txt` masih menaut `menu.html` yang sudah dihapus —
diarahkan ke `/wuenak-poll`.

---

## Foto WWP diganti set "Motif Piring Biru Kuning"

Set lama memakai piring campur-campur — floral putih, merah-kuning bergaris,
motif ayam jago, mangkuk. Klien meminta seragam. Set baru memakai **satu piring
floral biru-kuning** di atas kain putih yang sama untuk kesepuluh fotonya.

Sumbernya `Update 5/WWP_Motif_Piring_Biru_Kuning.pdf` — **12 halaman**, tiap
halaman 1086×1448 dengan tepat satu gambar. Dibuat oleh `tools/make-wwp-motif.mjs`.

Berkasnya dinamai **nama hidangan**, bukan nomor halaman, sehingga seluruh
caption dan alt di `wuenak-poll.html` tetap berlaku tanpa disunting.

### Pemetaan diverifikasi satu per satu, bukan ditebak

Tiap foto lama disandingkan dengan calon penggantinya sebelum ditukar.
Kesepuluhnya terbukti hidangan yang sama. Dua halaman tidak dipakai — halaman 4
(ayam cabe ijo + kangkung) dan 11 (capcay sosis) adalah hidangan yang belum ada
di galeri; menambahkannya butuh nama dan deskripsi dari klien.

### Halaman 10 teregang — dan cara membuktikannya

Klien melihat `nasi-campur-bakwan-goreng` tampak "gepeng". Benar, dan sumbernya
ada di dalam PDF, bukan di CSS.

Cara mengukurnya: **kotak batas piring** dideteksi dari piksel biru-dominan
(cincin floral biru di atas kain putih hangat), lalu rasionya dibandingkan
antar-foto. Hasilnya sembilan foto jatuh di **1.02–1.15** — praktis bulat —
sementara halaman 10 sendirian di **0.679**. Jadi bukan cacat sistematis
pipeline, melainkan satu halaman yang menyimpang.

Yang memastikan itu regangan, bukan piring lonjong: **gundukan nasi dan potongan
telur pindang**. Dua bentuk itu mestinya bulat apa pun bentuk piringnya, dan
keduanya terlihat memanjang tegak.

Faktor koreksinya jatuh persis di **0.75 = 1086/1448**: tinggi halaman diperas
kembali sampai sama dengan lebarnya, mengembalikan foto ke **1:1** asalnya.
Angka bulat itu sendiri bukti tambahan — foto aslinya persegi, lalu ditempatkan
ke halaman 3:4 tanpa menjaga proporsi.

Koreksi dilakukan **sebelum** pemotongan; memotong dulu akan mengunci
distorsinya ke dalam hasil. Sesudah koreksi rasio piring naik 0.679 → 0.845, dan
piringnya kini menyentuh tepi bingkai — sama seperti `chicken-coin`,
`nasi-ayam-balado`, dan `omurice` yang memang sudah begitu.

`KOREKSI_TINGGI` di skrip hanya berisi halaman 10. **Jangan diseragamkan ke
halaman lain** — sembilan sisanya tidak teregang, dan memerasnya akan merusak.

### Dua alt yang ikut menjadi salah

Penggantian foto diam-diam membatalkan dua alt: Chicken Coin menyebut "piring
merah kuning", Korean Rice Bowl menyebut "mangkuk". Keduanya kini piring floral.
Sudah diperbaiki. Yang menyebut "piring bermotif bunga" tetap benar.

### Jebakan yang ditutup

`make-brand-pages-photos.js` dulu ikut menghasilkan foto WWP dari
`Foto Final/WWP/`. Kalau dibiarkan, menjalankannya ulang akan **menimpa balik**
set motif dengan piring campur-campur yang lama — tanpa error dan tanpa gejala.
Skrip itu sekarang hanya mengurus 3S.

**Terverifikasi di browser:** kesepuluh berkas 1000×1333 (rasio 0.750), bingkai
tile juga 0.750, `object-fit: cover`, dan **tidak ada peregangan CSS sama
sekali** — rasio render sama dengan rasio berkas di semua gambar. 0 gagal
kontras, 0 gambar rusak, nol overflow.

---

## Aturan URL dikunci di `vercel.json`

Clean URLs sebelumnya aktif lewat **toggle di dasbor Vercel**. Perilakunya benar,
tapi letaknya salah: aturan yang menentukan validitas setiap canonical, `og:url`,
`@id` JSON-LD, dan entri sitemap di repo ini tersimpan di luar repo — bisa
tergeser oleh siapa pun yang membuka dasbor, tanpa jejak dan tanpa error.
Seluruh keputusan di bagian "Vercel memakai Clean URLs" di atas bergantung
padanya.

Sekarang aturannya berada di `w3s-landing/vercel.json`:

```json
{
  "cleanUrls": true,
  "trailingSlash": false
}
```

`vercel.json` **menang atas dasbor**, jadi togglenya tidak lagi bisa mengubah
apa pun.

`trailingSlash: false` ikut disertakan bukan sebagai bonus: tanpa itu `/3s/` dan
`/3s` sama-sama menyajikan halaman yang sama pada dua URL berbeda, dan
canonical hanya bisa menunjuk salah satunya. Itu sumbu ketiga dari kelas bug yang
sama dengan apex-vs-www dan `.html`-vs-tanpa-`.html`. Mengunci dua aturan
sekaligus menutup ketiganya.

**Tidak ada header cache di sini, dan itu disengaja.** Nama berkas aset di
proyek ini tidak ber-hash (`style.css`, `app.js`, `nasi-ayam-geprek.webp`), jadi
`max-age` panjang berarti deploy berikutnya tidak akan sampai ke pengunjung yang
pernah datang. Default Vercel — ETag plus revalidasi — justru yang benar untuk
struktur ini. Header cache baru masuk akal kalau nama berkas mulai ber-hash.

### `tools/serve.js` sekarang meniru redirect-nya, bukan hanya melayani

Fallback lama hanya menyajikan `wuenak-poll.html` di `/wuenak-poll`. Itu membuat
server lokal **lebih permisif** daripada produksi: `/3s.html` dan `/3s/` sama-sama
200 di lokal, padahal produksi mengalihkannya 308. Tautan usang jadi terlihat
sehat sampai ia sampai ke produksi — persis mekanisme yang membuat dua bug URL
sebelumnya lolos.

Server lokal kini mengembalikan 308 untuk ketiga bentuk non-kanonis, dengan
**query string dipertahankan**. Yang terakhir itu bukan detail: penanda barcode
klien (`?utm_source=fasilitas_umum`) hidup di query string, dan redirect yang
membuangnya akan menghapus data pelacakan tanpa gejala apa pun.

Terverifikasi di server lokal:

| Permintaan | Hasil |
|---|---|
| `/`, `/wuenak-poll`, `/3s` | 200 |
| `/index.html` | 308 → `/` |
| `/3s.html` | 308 → `/3s` |
| `/3s/` | 308 → `/3s` |
| `/wuenak-poll/?utm_source=fasilitas_umum` | 308 → `/wuenak-poll?utm_source=fasilitas_umum` |
| `/tidak-ada` | 404 |

`kanonis()` di `serve.js` dan `vercel.json` menyatakan aturan yang sama di dua
tempat. Kalau salah satunya diubah, ubah keduanya — catatan itu ada di kepala
`serve.js`.

---

## Foto 3S — set pembaruan klien (Update 5)

Empat pengambilan baru: dua mengganti foto lama (**Ayam Bakar Bumbu Rujak**,
**Sop Merah**), dua hidangan yang belum pernah tampil (**Lapis Daging**,
**Nasi Liwet**). Galeri 3S naik dari 5 ke 7 tile.

Keduanya yang baru menampilkan **kemasan** — tray saji putih dan besek bermerek —
yang persis diminta di revisi klien #3.

### Logonya sudah tercetak, jadi pipeline lama tidak boleh menyentuhnya

`make-brand-pages-photos.js` menempel logo 3S di sudut kanan-**bawah**. Di set
baru logonya sudah ada di dalam foto, di kanan-**atas**. Melewatkannya ke
pipeline lama akan menghasilkan dua logo dalam satu bingkai. Karena itu ada
skrip terpisah, `make-3s-update.js`, yang hanya memotong dan mengecilkan.

Efek sampingnya: posisi logo kini tidak seragam di galeri 3S — empat foto baru
di kanan-atas, tiga foto lama di kanan-bawah. Menyeragamkannya berarti mengedit
foto kiriman klien, jadi dibiarkan sampai klien memutuskan.

### Potongan tengah menghapus logo — jadi titik potongnya dihitung

Semua sumber lebih tinggi dari 3:4, jadi tingginya dipangkas. Potongan tengah —
yang dipakai pipeline lama — membuang bagian atas, dan di situlah logonya.

Pada `lapis daging kemasan.png` (941×1672) potongan tengah membuang **208 px**
sementara logonya mulai di **110 px**: logo hilang seluruhnya, tanpa error dan
tanpa gejala sampai fotonya dilihat. Pada `sop merah` dan `nasi liwet` logonya
terpotong sebagian di bagian atas — cacat yang lebih halus dan lebih mudah lolos.

`batasAtasLogo()` mendeteksi cincin **putih** di tepi logo pada kuadran
kanan-atas, lalu jendela potong digeser seminimal mungkin ke bawah supaya logo
menyisakan margin 3,5%. Ambangnya bukan kecerahan melainkan **netralitas** —
`min(R,G,B) > 215` dan `maks−min < 26` — karena latar satin merah dan ornamen
emas sama-sama terang: satin gagal di syarat kedua (R jauh di atas G/B), emas
juga (B jatuh).

| Foto | Potong atas | Kalau tengah | Margin logo |
|---|---|---|---|
| ayam-bakar-bumbu-rujak | 78 | 86 | 3,5% |
| sop-merah | 22 | 86 | 3,5% |
| lapis-daging | **49** | **209** | 3,5% |
| nasi-liwet | 0 | 86 | 3,4% |

Karena potongannya condong ke atas, bagian bawah foto justru lebih terjaga
daripada potongan tengah — piring dan besek utuh.

### Jebakan penimpaan, sumbu kedua

`Ayam Bakar Bumbu Rujak 2.png` dan `Sop Merah.png` masih ada di `Foto Final/3S`.
Tanpa penjaga, menjalankan ulang `make-brand-pages-photos.js` akan menimpa balik
foto baru dengan yang lama **dan** memindahkan logonya ke kanan-bawah. Ketiganya
sekarang masuk `LEWATI`, sejajar dengan pengecualian WWP yang sudah ada.
Terverifikasi dengan menjalankan ulang skripnya: ketiganya dilewati.

### Dua alt dan satu deskripsi ikut menjadi salah

- Ayam Bakar: alt lama menyebut "bumbu rujak **merah pekat**"; glasir di foto
  baru cokelat kemerahan mengilap.
- Sop Merah: alt dan `plate__d` lama menyebut "**perkedel kentang**"; foto baru
  memakai **crouton**.

Keduanya diperbaiki. Ini pola yang sama dengan penggantian foto WWP: mengganti
gambar diam-diam membatalkan teks yang mendeskripsikannya, dan tidak ada yang
melaporkan itu.

### Baris terakhir berisi satu tile

Tujuh tile di grid tiga kolom menghasilkan 3/3/1. Nasi Liwet ditaruh terakhir
karena itu foto terkuat di set ini — hidangan lengkap plus besek bermerek — jadi
posisi tunggal itu terbaca sebagai penutup, bukan sisa. Kalau klien mau 3×2
rapi, satu tile harus dilepas; itu keputusan isi, bukan tata letak.

**Terverifikasi di browser:** 7 tile, grid 3/3/1, 0 gambar rusak, 0 peregangan,
nol overflow. `motion.js` memilih lewat `[data-reveal]` dan menghitung jeda dari
saudara sekandung, jadi dua tile baru ikut animasi tirai tanpa perubahan kode.
285 rujukan, 0 rusak, 0 aset yatim di `assets/3s`.

### Galeri dipangkas ke enam, dan yang dilepas adalah Nasi Kuning

Tujuh tile menyisakan baris ganjil; klien memilih enam. Yang dilepas **Nasi
Kuning**, dengan dua alasan yang berdiri sendiri-sendiri:

1. **Kembar dengan Nasi Liwet.** Keduanya besek anyaman berisi nasi, ayam dan
   pelengkap, dengan gerabah di sampingnya. Dua tile yang nyaris sama membuang
   satu dari enam slot; Nasi Liwet menang karena membawa besek bermerek.
2. **Termasuk set lama** yang latarnya lembut — persis yang dikeluhkan klien di
   revisi #7.

Lontong Cap Go Meh tetap, walau juga dari set lama, karena komposisinya berbeda
sendiri (piring saji panjang dengan banyak mangkuk kecil) sehingga tidak
menduplikasi tile mana pun.

Urutan akhir: Ayam Bakar · Rawon · Lapis Daging / Lontong Cap Go Meh · Sop Merah
· Nasi Liwet. Desktop 3×2, mobile 2×3 — tanpa baris ganjil di keduanya.

`nasi-kuning.webp` sengaja **tetap dihasilkan** meski jadi yatim: mengembalikan
tile-nya cukup satu blok HTML, tanpa perlu menjalankan pipeline lagi.

### Posisi logo diseragamkan ke kanan-atas — dikalibrasi, bukan dikira-kira

Foto kiriman klien membawa logo tercetak di kanan-atas dan tidak bisa diubah,
jadi foto lama yang harus menyesuaikan. Geometrinya **diukur** dari tiga foto
kiriman yang konsisten pada keluaran 1000×1333:

| | diameter | margin atas | margin kanan |
|---|---|---|---|
| lapis-daging | 220 | 46 | 78 |
| nasi-liwet | 216 | 45 | 45 |
| sop-merah | 229 | 47 | 59 |
| **dipakai** | **222** | **46** | **68** |

Pengukurannya lewat **komponen terhubung**, bukan kotak batas global: piring
putih, selada mengkilap dan tray saji sama-sama lolos ambang netral, sehingga
kotak batas global selalu menelan separuh foto. Komponen yang dipilih adalah
yang nyaris bujur sangkar di sepertiga atas sisi kanan.

**Cincin putih harus digambar ulang.** Badge cetakan klien punya cincin putih
tipis di tepinya; berkas `assets/brand/kuliner-3s.webp` hanya cakram merah
polos. Tanpa cincin itu logo tempelan tetap terlihat berbeda meski ukuran dan
posisinya sama persis. Tebalnya diukur dari badge cetakan — 4 px pada diameter
229, yaitu ~1,8%. Cincin digambar sebagai lingkaran putih penuh lalu logo
ditempel di tengahnya, bukan sebagai stroke SVG, karena cakram logo tidak
persis bulat (396×402) dan stroke akan memperlihatkan celah di sisi pendeknya.

Ukuran logo naik dari kotak 132 ke 222 — foto lama dulu memakai badge yang jauh
lebih kecil daripada kiriman klien.

### Satu pengecualian: hero nasi-empal tetap kanan-bawah

Di foto itu sudut kanan-atas ditempati kemasan bermerek 3S, dan badge kanan-atas
mendarat **tepat di atas logo yang tercetak pada kemasannya** — merah di atas
merah, menutupi branding kemasan yang justru ingin ditampilkan (revisi #3).

Pengecualian ini tidak merusak keseragaman yang diminta: nasi-empal adalah foto
**hero**, tampil sendirian. Keseragaman posisi hanya terbaca kalau foto-fotonya
berdampingan, dan itu berlaku untuk galeri, bukan hero.

### Yang tersisa dan hanya klien yang bisa membereskan

Badge pada `ayam bakar rujak.png` hanya **157 px** — sekitar 30% lebih kecil
daripada tiga kiriman lainnya (216–229). Itu ketidakseragaman di berkas sumber
klien; menimpanya berarti menutup logo asli dan merekonstruksi latar satin di
bawahnya. Perlu ekspor ulang dari mereka.

**Terverifikasi:** desktop 1280 px → 3 kolom, 6 tile, 2 baris penuh, `scrollWidth
== clientWidth`; mobile 375 px → 2 kolom, 3 baris penuh, tanpa overflow. Semua
foto 1000×1333, 0 gambar rusak. 284 rujukan, 0 rusak.

### Latar merah diseragamkan — dan satu foto diambil dari halaman utama

Klien ingin galeri 3S seragam berlatar merah seperti foto di bagian Sajian
halaman utama. Empat kiriman Update 5 sudah berlatar satin merah; dua tile lama
tidak.

Untuk mengukur ini secara objektif, bukan lewat kesan: fraksi piksel
**merah-dominan pada bingkai luar 10%** tiap foto — bingkai luar, karena di
situlah latarnya, bukan hidangannya.

| Tile | sebelum | sesudah |
|---|---|---|
| nasi-liwet | 63% | 63% |
| lapis-daging | 58% | 58% |
| sop-merah | 54% | 54% |
| ayam-bakar | 49% | 49% |
| **lontong-cap-gomeh** | **18%** | **58%** |
| rawon | 32% | 32% |

**Lontong Cap Go Meh** punya pengambilan berlatar merah — dan pengambilan itu
justru yang **sudah dipakai di halaman utama**. Jadi kedua halaman kini
menampilkan berkas yang sama untuk hidangan yang sama, bukan sekadar senada.

Sumbernya di luar `Foto Final`, jadi pipeline diberi daftar `TAMBAHAN` untuk
sumber luar; versi `Foto Final` masuk `LEWATI` supaya tidak menimpa balik.
Aslinya lanskap 6000×4000 — pemotongan 3:4 membuang lebar, bukan tinggi, dan
piringnya di tengah sehingga potongan tengah aman.

Alt-nya ikut diperbaiki: foto lama piring panjang dengan banyak mangkuk kecil
di atas anyaman, foto baru satu piring bulat di talenan kayu berlatar kain
merah. Deskripsi hidangan (`plate__d`) tidak diubah — itu mendeskripsikan
hidangannya, bukan fotonya.

Catatan jujur: foto ini produksinya lebih sederhana daripada set studio Update 5
(bidikan atas, penataan lebih polos). Ia dipilih karena memenuhi syarat latar
merah dan menyatukan dua halaman, bukan karena kualitas fotonya setara.

### Rawon tidak punya versi berlatar merah — di mana pun

Seluruh folder sumber disisir: `Foto Final/3S`, `Update 2/3S`, `Update 4/3S`,
`Update 5`, dan ketujuh belas folder hidangan di `14 Agustus 2026`. Satu-satunya
Rawon yang ada berlatar kayu dan gerabah. Angka 32% pada tabel di atas berasal
dari warna tanah liat dan kayu, bukan dari latar merah.

Satu kandidat berlatar merah sempat muncul di luar galeri —
`AYAM GORENG LAOS/AG LAOS.jpeg` (63%) — tapi **ditolak**: latarnya penuh dekorasi
Imlek (angpao, koin emas, ornamen petasan, aksara Tionghoa). Alasannya sama
persis dengan penolakan foto berdekor Natal sebelumnya: halaman ini tayang
sepanjang tahun.

Jadi Rawon dibiarkan apa adanya, dan menyeragamkannya butuh pengambilan ulang
dari klien. Melepasnya akan memecah grid 3×2 yang baru saja diminta.

**Terverifikasi:** 6 tile, 3+3 di 1280 px, 0 overflow, 0 gambar rusak, semua
1000×1333. 284 rujukan, 0 rusak.

### Koreksi: Rawon berlatar merah memang ada — di `Update 4/3S/57.jpg`

Catatan sebelumnya menyatakan seluruh folder sumber sudah disisir dan tidak ada
Rawon berlatar merah. **Itu salah.** `Update 4/3S` tidak ikut diukur, padahal
folder itu berisi lima foto studio berlatar satin merah — termasuk Rawon.
Klien yang menunjukkannya.

`57.jpg` sekarang dipakai untuk `rawon.webp`. Enam dari enam tile kini berlatar
merah.

**Badge-nya di KIRI-atas**, bukan kanan seperti lima tile lain. Klien memakai
dua konvensi: set `Update 5/Tambahan 3S` menaruh badge di kanan, set
`Update 4/3S` di kiri.

Membuang badge kiri lewat potongan lalu menempel badge kanan kalibrasi **sudah
dicoba dan ditolak**: untuk menghilangkan badge kiri, potongan menyempit ke
684 px sehingga gagang pot terpotong dan mangkuk pelengkap di atas hampir habis.
Komposisi foto lebih berharga daripada sisi badge.

Margin atas badge di foto ini 1,7%, bukan 3,5% seperti yang lain — sumbernya
864×1184 (rasio 0,730), jadi hanya ada 32 px tinggi yang bisa dibuang dan
badge-nya memang lebih dekat ke tepi sejak dari berkas klien. Potongan diambil
dari bawah (`top = 0`) supaya margin yang tipis itu tidak makin tipis.

`Foto Final/3S/Rawon.png` masuk `LEWATI` agar pipeline lama tidak menimpa balik.

**Pendeteksi logo diberi parameter sisi.** Memindai selebar foto bukan pilihan:
di `57.jpg` bagian atas penuh mangkuk putih dan kerupuk, yang semuanya lolos
ambang netral dan akan tertangkap sebagai logo.

### Yang belum dipakai dari `Update 4/3S`

Empat berkas lain di folder yang sama juga berlatar merah dan bisa dipakai kalau
klien mau: `56` (daging berbumbu dengan telur di piring putih), `58` (Nasi Kuning
dalam besek), `59` (nasi lengkap dengan kemasan bermerek), `60` (hampers dalam
kotak mika).

`58` khususnya membatalkan sebagian alasan melepas Nasi Kuning — alasan "set lama
berlatar lembut" tidak lagi berlaku. Alasan kedua tetap berlaku: komposisinya
kembar dengan Nasi Liwet. Keputusan ada di klien.

**Terverifikasi:** 6 tile, 3+3 di 1280 px, 0 overflow, 0 gambar rusak, semua
1000×1333.

### Lontong Cap Go Meh diganti lagi — kali ini versi studionya

Klien menambahkan `Update 5/Tambahan 3S/Lontong Cap Go Meh.png`: komposisi yang
sama dengan foto galeri semula (piring bulat plus piring saji panjang berisi
mangkuk pelengkap) tapi difoto di satin merah, dengan badge di kanan-atas —
sederet dengan empat kiriman Update 5 lainnya.

Ini menggantikan pengambilan dari folder 14 Agustus yang dipakai sebelumnya.
Pemilihan itu memang kompromi dan sudah dicatat begitu: latarnya merah, tapi
produksinya lebih sederhana — bidikan atas, penataan polos. Versi studio ini
menang di semua sisi, jadi jalur sumber luar dilepas.

`TAMBAHAN` di `make-brand-pages-photos.js` **dikosongkan, bukan dihapus**.
Mekanismenya ditinggalkan karena kebutuhan sumber di luar `Foto Final` sudah
terbukti bisa muncul; yang hilang cuma isinya.

Alt diperbarui lagi mengikuti komposisi baru. Ini penggantian foto ketiga untuk
tile yang sama dalam satu sesi, dan ketiganya menuntut alt yang berbeda —
alasan kenapa alt tidak boleh ditulis sekali lalu dianggap selesai.

### Rawon kini satu-satunya yang menyimpang

Lima tile berlatar satin merah dengan ornamen emas dan badge kanan-atas. Rawon
berlatar merah juga, tapi lebih gelap, tanpa ornamen, dan badge-nya di kiri —
karena berasal dari sesi foto yang berbeda (`Update 4`, bukan `Update 5`).

Kalau klien memotret ulang Rawon dalam seri Update 5, galeri ini jadi seragam
sepenuhnya. Sampai itu terjadi, foto yang ada sudah memenuhi syarat latar merah.

### Halaman utama kini memakai foto Lontong yang berbeda

Bagian Sajian di `index.html` masih memakai `assets/dish/lontong-cap-go-meh.webp`
dari folder 14 Agustus. Kedua halaman sempat memakai berkas yang sama; sekarang
tidak lagi.

Itu dibiarkan karena `#sajian` punya set fotonya sendiri dengan gaya campuran
(kayu, marmer, latar gelap) dan menyelaraskan satu tile saja justru membuatnya
makin tidak konsisten. Menyeragamkan seluruh `#sajian` ke set studio merah adalah
pekerjaan tersendiri — bahannya kini tersedia untuk lima hidangan.

**Terverifikasi:** 6 tile, 3+3 di 1280 px, 0 overflow, 0 gambar rusak, semua
1000×1333.

### Rawon studio menyusul — galeri 3S akhirnya satu sesi foto

`Update 5/Tambahan 3S/Rawon.png` masuk beberapa menit setelah Lontong. Pot
gerabah yang sama, tapi di satin merah dengan ornamen emas dan badge di
kanan-atas.

Keenam tile kini berasal dari **satu sesi foto yang sama**, dan seluruh
pengecualian yang sempat ditulis di bagian sebelumnya batal:

- Sumber `Update 4/3S/57.jpg` tidak dipakai lagi.
- Badge kiri-atas hilang; keenamnya kanan-atas.
- Margin atas badge yang tadinya 1,7% kini 3,5%, sederet dengan yang lain.

Geometri badge terukur: diameter 216–229 px, margin atas 45–47 px di lima tile.

Field `sisi` pada `PETA` **dipertahankan meski nilainya kini seragam**. Ia ada
karena set `Update 4` benar-benar menaruh badge di kiri, dan kalau sumber dari
sesi lain muncul lagi, parameter itu yang membuatnya bisa dipakai tanpa
membongkar pendeteksi. Memindai selebar foto bukan pengganti yang sah: mangkuk
putih, kerupuk dan piring saji sama-sama lolos ambang netral.

### Satu-satunya sisa: badge Ayam Bakar lebih kecil

157 px, sementara lima lainnya 216–229. Itu ada di berkas kiriman klien, bukan
di pipeline — memperbesarnya berarti menutup logo asli dan merekonstruksi satin
di bawahnya. Perlu ekspor ulang dari klien.

### Alt hidangan yang sama ditulis ulang empat kali dalam satu sesi

Lontong Cap Go Meh berganti foto tiga kali, Rawon dua kali, dan setiap kali
alt-nya harus ikut berubah karena yang dideskripsikan memang berubah — piring
panjang jadi piring bulat lalu kembali, talenan jadi papan bundar, latar terang
jadi satin merah. Tidak ada satu pun yang akan melaporkan alt yang basi.

Aturannya: **ganti foto, baca ulang alt-nya di tempat.** Bukan nanti.

**Terverifikasi:** 6 tile, 3+3 di 1280 px, 0 overflow, 0 gambar rusak, semua
1000×1333, keenam badge kanan-atas.

---

## `#sajian` di halaman utama ikut set studio merah

Tujuh dari delapan tile diganti dengan foto dari `Update 4/3S` dan
`Update 5/Tambahan 3S`. Skrip barunya `tools/make-sajian-3s.js`.

| tile | sumber |
|---|---|
| Rawon | Update 5 / `Rawon.png` |
| Lontong Cap Go Meh | Update 5 / `Lontong Cap Go Meh.png` |
| Nasi Liwet | Update 5 / `nasi liwet.png` |
| Lapis Daging | Update 5 / `lapis daging kemasan.png` |
| Nasi Empal | Update 4 / `59.jpg` |
| Nasi Kuning | Update 4 / `58.jpg` |
| Galantine | Update 4 / `60.jpg` |
| **Tumpeng** | **tetap** — tile 2:1, tidak ada pengambilan studio merah |

### Tile-nya bujur sangkar, jadi berkas 3:4 tidak bisa dipakai ulang

`.plate img` di `#sajian` dikunci `aspect-ratio: 1/1`, sedangkan galeri 3S 3:4.
Memotong berkas 3:4 yang sudah jadi menjadi 1:1 memangkas sepertiga tinggi — dan
yang hilang justru bagian bawah tempat hidangannya. Jadi potongan bujur sangkar
dibuat ulang dari sumber aslinya.

### Logo bawaan dibuang utuh, lalu ditempel ulang

Sumbernya potret (2:3 dan 0,73), jadi potongan 1:1 memang membuang banyak
tinggi. Potongan tengah akan **memotong logo bawaan separuh** — cacat yang jauh
lebih mencolok daripada logo yang hilang sama sekali.

Jadi jendela potong ditempatkan tepat di bawah logo bawaan, lalu logo kalibrasi
ditempel di kanan-atas dengan geometri yang sama seperti seluruh foto 3S lain
(diameter 222, margin atas 46, kanan 68 pada lebar 1000). Konsekuensinya
diterima sadar: bagian atas komposisi ikut terbuang — di sumber Update 4 itu
sebagian kemasan bermerek di latar.

### Dua kegagalan deteksi yang nyata, dan perbaikannya

**1. Komponen terbesar salah pilih.** Di Lontong Cap Go Meh, mangkuk opor putih
lebih besar daripada cincin logo, jadi ia yang terpilih; jendela potong terdorong
ke batas bawah (512 dari 512) dan **piring utamanya terpotong hampir habis**.
Ketahuan dari melihat hasilnya, bukan dari angka — laporan skripnya terlihat
wajar.

Perbaikannya bukan menaikkan ambang, tapi mengganti kriterianya: yang dipilih
adalah komponen **paling atas** yang lolos saringan bentuk, bukan yang terbesar.
Logo selalu di atas isi piring. Jendela pindainya juga dipersempit ke 40%
terluar, karena mangkuk saji sering menjorok melewati garis tengah.

**2. Badge Update 4 tidak punya cincin putih sama sekali** — cakram merah
semi-transparan dengan teks putih, di atas latar yang juga merah. Pendeteksi
cincin tidak mungkin menemukannya, dan melonggarkan ambang hanya menangkap
huruf-huruf terpisah.

Batas bawahnya ditulis manual (`logoBawah: 158`), diukur dari berkasnya; ketiga
foto memakai tata letak yang sama dan cakramnya berhenti di 145–155 px.
Jalur cadangan lama (potong dari tengah, 160 px) kebetulan lolos hanya karena
selisih 5 px. Kebetulan seperti itu tidak boleh diandalkan, jadi **skripnya
sekarang berhenti dengan error** kalau logo tidak terdeteksi dan tidak ada
`logoBawah` — lebih baik gagal daripada diam-diam menyisakan separuh logo.

### Tujuh alt dan tujuh dimensi intrinsik ikut diperbaiki

Semua keluaran kini 1000×1000; atribut lama menulis 900/819/860. Rasionya sama
sehingga tidak ada pergeseran tata letak, tapi angkanya salah dan tidak ada yang
akan melaporkannya.

Alt-nya berubah karena fotonya berubah — Nasi Liwet tidak lagi memakai pete
melainkan edamame, Galantine kini dalam kotak hantaran mika, Rawon di papan kayu
bundar. Lima alt menyebut latar satin merah.

### Tumpeng jadi satu-satunya yang bergaya lama

Tile-nya `.plate--wide` dengan `aspect-ratio: 2/1`, dan tidak ada pengambilan
studio merah untuk tumpeng. Ia dibiarkan; posisinya sebagai tile lebar pembuka
membuatnya terbaca sebagai elemen tersendiri, bukan sebagai anggota grid yang
menyimpang.

**Terverifikasi di browser:** 8 tile, tujuh 1000×1000 dan satu 1792×896,
0 gambar rusak, 0 peregangan, nol overflow.

---

## Bug produksi: pesan WhatsApp menampilkan "%0A" apa adanya

Dilaporkan klien. Pesan yang seharusnya lima baris tiba di WhatsApp sebagai
satu baris panjang bertabur `%0A`.

### Penyebab: encode dua kali

Enam `data-msg` menulis baris baru sebagai `%0A` — bentuk yang **sudah
ter-encode**. Lalu `buildWaLink()` memanggil `encodeURIComponent()` pada
seluruh pesan, dan tanda `%` ikut di-encode menjadi `%25`. URL-nya membawa
`%250A`; WhatsApp men-decode tepat satu kali, menghasilkan teks `%0A` mentah.

Jalur form (`quoteForm`) tidak terkena karena ia menyusun pesan dengan karakter
baris baru sungguhan lalu meng-encode sekali. Jadi tombol-tombol yang justru
paling banyak ditekan — hero, penawaran, footer — rusak, sementara jalur yang
lebih jarang dipakai benar. Klien yang melihatnya duluan.

### Perbaikan: satu konvensi, diterjemahkan di satu tempat

`data-msg` kini menulis baris baru sebagai dua karakter `\n`. `app.js`
menerjemahkannya ke baris baru sungguhan **sebelum** `encodeURIComponent`,
sehingga encode hanya terjadi sekali dan hanya pada teks mentah.

`%0A` tidak dijadikan konvensi karena ia yang menjebak: terlihat seperti sudah
"siap URL", padahal justru itu yang membuatnya di-encode dua kali. `\n` adalah
penanda yang semua orang kenali sebagai baris baru, dan tidak menyerupai apa
pun yang siap dikirim.

Alternatif yang ditolak: men-`decodeURIComponent` `data-msg` dulu. Itu akan
melempar error pada pesan yang mengandung `%` biasa — "diskon 10%" — dan
mematikan tombol WhatsApp-nya diam-diam.

### Terverifikasi di tiga halaman

| halaman | tombol `.js-wa` | `%25` di href | baris baru sesudah decode |
|---|---|---|---|
| `/` | 8 | 0 | 4 pesan berbaris |
| `/wuenak-poll` | 5 | 0 | 1 |
| `/3s` | 4 | 0 | 1 |

Tidak ada sisa `\n` mentah setelah decode, tidak ada `%0A` di seluruh markup,
nol error konsol.

### Catatan alat: heredoc memakan garis miring terbalik

Tambalan pertama untuk ini ditulis lewat heredoc bash dan menghasilkan berkas
yang rusak: `\n` di sumber tiba sebagai `\n`, lalu sebagai baris baru
sungguhan, di tengah literal regex. Sintaks JS-nya patah. Perbaikannya ditulis
ulang lewat Edit tool untuk `app.js`, dan untuk markup lewat skrip yang
membangun garis miring terbalik dari `String.fromCharCode(92)` — tanpa satu pun
literal `\` di sumbernya. **Isi yang mengandung `\` atau backtick jangan lewat
heredoc.**
