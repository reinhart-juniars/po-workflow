# Website www.w3scatering.com: pindah dari Vercel ke Cloudflare Pages

Untuk: yang memegang akun domain dan server (Reinhart). Bagian C, v3.2.

## Kondisi sekarang (dicek 21 Okt 2026)

| Apa | Di mana |
|---|---|
| Pendaftaran domain + DNS | **Rumahweb** (nameserver `nsid1.rumahweb.com`, `nsid2.rumahweb.net`, `nsid3.rumahweb.biz`, `nsid4.rumahweb.org`) |
| `w3scatering.com` | A `216.198.79.1` → Vercel |
| `www.w3scatering.com` | CNAME → `…vercel-dns-017.com` → Vercel |
| `3sone.w3scatering.com` | A `159.65.11.190` → **server 3S ONE (DigitalOcean)** |
| Email (MX), TXT | tidak ada |
| Kode website | folder `w3s-landing/` di repo `reinhart-juniars/po-workflow` |

Jadi yang "di Vercel" hanya hosting website-nya. DNS ada di Rumahweb.

## Kenapa pindah

- Vercel Hobby (gratis) tidak boleh untuk situs komersial. Cloudflare Pages gratis dan boleh.
- Tombol **Terbitkan ke Website** di 3S ONE memanggil *Deploy Hook* Cloudflare Pages: website dibangun ulang dari data 3S ONE dalam ±1–2 menit.
- Website tetap tampil walau 3S ONE sedang mati, karena yang tayang adalah hasil build terakhir.

## Urutan dan jadwal

| Tahap | Kapan | Risiko ke 3S ONE |
|---|---|---|
| A. Akun Cloudflare + proyek Pages di alamat `*.pages.dev` | sekarang | tidak ada |
| B. Token API + Deploy Hook, uji Pratinjau & Terbitkan | sekarang, setelah 3S ONE v3.2 ter-deploy | tidak ada |
| C. Pindah nameserver Rumahweb → Cloudflare | **sesudah cutover 1 Nov stabil** (mis. 3–5 Nov) | ada: `3sone` ikut pindah DNS |
| D. Domain `www` dan apex ke Pages, lepas dari Vercel | sesudah C aktif | tidak ada bila C benar |

Demo 27 Okt memakai alamat `*.pages.dev`. Domain asli dipindah sesudah 1 Nov, supaya pindah DNS tidak bertabrakan dengan cutover.

---

## A. Akun Cloudflare dan proyek Pages

1. Buka https://dash.cloudflare.com/sign-up, daftar dengan **email W3S** (bukan email pribadi), aktifkan verifikasi dua langkah (*My Profile › Authentication*).
2. Menu kiri **Workers & Pages › Create › Pages › Connect to Git**.
3. Pilih **GitHub**, masuk dengan akun GitHub `reinhart-juniars`. Saat GitHub bertanya repo mana: **Only select repositories › po-workflow**.
4. Pilih repo `po-workflow`, lalu isi:

   | Kolom | Isi |
   |---|---|
   | Project name | `w3scatering` (alamatnya jadi `w3scatering.pages.dev`) |
   | Production branch | `main` |
   | Framework preset | None |
   | Build command | `node tools/sync-menu.mjs` |
   | Build output directory | `dist` |
   | Root directory (Advanced) | `w3s-landing` |

   Lalu buka **Environment variables (advanced)** › tambah `NODE_VERSION` = `22`.

   Token belum diisi di tahap ini, jadi build pertama memakai menu yang tertulis di HTML (sama dengan website sekarang). Log build menulis *WEBSITE_API_URL / WEBSITE_API_TOKEN belum diatur* — itu wajar.

5. **Save and Deploy**. Tunggu ±1 menit, buka `https://w3scatering.pages.dev`. Cek halaman `/`, `/wuenak-poll`, `/3s`, dan foto menu tampil. (`/3s.html` otomatis dialihkan ke `/3s`, sama seperti di Vercel.)
6. **Settings › Builds › Build watch paths**: Include `w3s-landing/*`. Tanpa ini setiap commit aplikasi 3S ONE ikut membangun ulang website.
7. **Settings › Builds › Branch control**: matikan *preview deployments* untuk branch lain (cukup `main`), supaya branch fitur tidak ikut dibangun.

Tanda selesai: `w3scatering.pages.dev` sama persis dengan `www.w3scatering.com`.

## B. Token API dan Deploy Hook

Syarat: 3S ONE v3.2 sudah di-deploy di server (`~/bin/deploy.sh v3.2.0`): halaman Pratinjau Website dan API yang menyajikan snapshot terbitan.

Dua rahasia. Keduanya **tidak pernah** ditulis di chat, di repo, atau di screenshot.

### B1. Token API website

1. Di server (PuTTY, user `deploy`): `openssl rand -hex 32`. Salin hasilnya.
2. `nano /var/www/po-workflow/.env`, tambahkan:
   ```
   WEBSITE_API_TOKEN=<hasil openssl>
   WEBSITE_URL=https://www.w3scatering.com
   ```
3. Di Cloudflare: proyek `w3scatering` › **Settings › Variables and Secrets › Add**:
   - `WEBSITE_API_TOKEN` = token yang sama, tipe **Secret** (Encrypt).
   - `WEBSITE_API_URL` = `https://3sone.w3scatering.com/api/website/menu`, tipe Plaintext.
4. **Deployments › (deploy terakhir) › ⋯ › Retry deployment.** Log build sekarang menulis *Menu belum pernah diterbitkan dari 3S ONE: menu cadangan di HTML dipakai* — artinya token sudah cocok. Bila tertulis *menolak token (401)*, token di Cloudflare dan di `.env` berbeda.

### B2. Deploy Hook

1. Cloudflare: proyek `w3scatering` › **Settings › Builds › Deploy hooks › Add deploy hook**. Nama `3S ONE Terbitkan`, branch `main`. Salin URL-nya.
2. Di server, tambahkan ke `.env`:
   ```
   WEBSITE_DEPLOY_HOOK_URL=<URL deploy hook>
   ```
3. `cd /var/www/po-workflow && php artisan config:cache && sudo systemctl reload php8.3-fpm`

   (Lakukan setelah B1 juga: `.env` hanya terbaca ulang setelah `config:cache`.)

### B3. Uji

1. Login 3S ONE sebagai marketing › **Marketing › Katalog Foto Menu**. Kotak **Website** harus berlabel *Ada perubahan belum diterbitkan* atau *Sudah terbaru*, bukan *Belum tersambung*.
2. Tekan **Pratinjau & Terbitkan**. Periksa jumlah menu, foto, dan daftar *Dicentang tetapi tidak ikut terbit*.
3. Tekan **Terbitkan ke Website** › OK.
4. Cloudflare › proyek › **Deployments**: build baru muncul, status *Success* dalam ±1–2 menit. Log build menulis *Warung Wuenak Poll: … menu ditulis* dan *Selesai: … menu, … foto*.
5. Buka `w3scatering.pages.dev/wuenak-poll` dan `/3s`: menu dan fotonya sama dengan pratinjau.

> Selama pemetaan 190 menu belum selesai, terbitan pertama hanya memuat menu yang sudah dipetakan. Halaman brand yang belum punya satu pun menu terbit tetap menampilkan menu lama. Untuk demo, menu yang tampil di `pages.dev` = menu yang sudah siap di 3S ONE — domain asli belum terpengaruh.

## C. Pindah nameserver ke Cloudflare (sesudah 1 Nov)

> Bagian ini memindahkan DNS **seluruh** domain, termasuk `3sone.w3scatering.com`. Kalau catatan `3sone` hilang, 3S ONE tidak bisa dibuka. Kerjakan di jam sepi, bukan di hari cutover.

1. Cloudflare: **Websites › Add a site** › `w3scatering.com` › paket **Free**.
2. Cloudflare memindai catatan DNS yang ada. **Periksa daftarnya** sebelum lanjut. Harus ada, persis:

   | Tipe | Nama | Isi | Proxy |
   |---|---|---|---|
   | A | `3sone` | `159.65.11.190` | **DNS only (awan abu-abu)** |
   | A | `@` | `216.198.79.1` | DNS only (sementara, masih Vercel) |
   | CNAME | `www` | `…vercel-dns-017.com` (sesuai hasil pindai) | DNS only (sementara) |

   - `3sone` **wajib DNS only**. Server 3S ONE tidak disiapkan di belakang proxy Cloudflare, dan sertifikat HTTPS-nya (certbot) diperpanjang langsung ke server.
   - Bila ada catatan lain di Rumahweb yang tidak terpindai, tambahkan manual. Bandingkan dengan *Rumahweb › Domain › Kelola DNS*.
3. Cloudflare menampilkan **dua nameserver** (mis. `xxx.ns.cloudflare.com`). Salin.
4. Rumahweb: login client area › **Domain** › `w3scatering.com` › **Nameserver** › ganti keempat nameserver Rumahweb dengan dua nameserver Cloudflare › Simpan.
5. Tunggu email *"w3scatering.com is now active on Cloudflare"* (biasanya 1–24 jam). Selama menunggu tidak ada yang putus, karena isi catatannya sama di kedua tempat.
6. Cek dari komputer: `nslookup -type=NS w3scatering.com 1.1.1.1` → harus nameserver Cloudflare. `nslookup 3sone.w3scatering.com 1.1.1.1` → harus `159.65.11.190`. Buka https://3sone.w3scatering.com: login normal, gembok HTTPS valid.

## D. Domain ke Pages, lepas dari Vercel

1. Cloudflare › proyek `w3scatering` › **Custom domains › Set up a custom domain** › `www.w3scatering.com` › Activate. Cloudflare mengganti sendiri CNAME `www` ke Pages.
2. Ulangi untuk `w3scatering.com` (apex).
3. Arahkan apex ke www supaya satu alamat saja: **Websites › w3scatering.com › Rules › Redirect Rules › Create rule**: bila *Hostname equals* `w3scatering.com` → *Dynamic redirect* ke `concat("https://www.w3scatering.com", http.request.uri.path)`, kode **301**, *Preserve query string* aktif.
4. Cek: `https://www.w3scatering.com`, `https://w3scatering.com` (dialihkan ke www), `/wuenak-poll`, `/3s`, gembok HTTPS valid. Cek sekali lagi https://3sone.w3scatering.com.
5. Vercel: proyek website › **Settings › Domains** › hapus `w3scatering.com` dan `www.w3scatering.com`. Proyek Vercel boleh dihapus seminggu kemudian bila tidak ada masalah.
6. Hapus `vercel.json` dari `w3s-landing/` (tidak dipakai lagi), commit.

---

## Kalau ada masalah

| Gejala | Tindakan |
|---|---|
| 3S ONE tidak bisa dibuka setelah tahap C | Cloudflare › DNS: pastikan A `3sone` = `159.65.11.190`, **DNS only**. Kalau mendesak: kembalikan nameserver di Rumahweb ke keempat `nsid*.rumahweb.*` (catatan lama di Rumahweb masih ada). |
| Website kosong / 404 di Pages | Proyek › Deployments › build terakhir › log. Biasanya *Root directory* bukan `w3s-landing` atau *output directory* bukan `.`. |
| Tombol Terbitkan: *Belum tersambung* | `WEBSITE_DEPLOY_HOOK_URL` belum ada di `.env` server atau `config:cache` belum dijalankan. |
| Build gagal "401" saat mengambil menu | Token di Cloudflare tidak sama dengan `WEBSITE_API_TOKEN` di server. |
| Build gagal karena 3S ONE mati | Website tetap menampilkan build terakhir. Tekan Terbitkan lagi setelah 3S ONE normal. |
| Website berubah padahal belum diterbitkan | Tidak mungkin: website hanya membaca snapshot terbitan terakhir. Cek **Deployments**: build terpicu commit kode website memakai snapshot yang sama. |
| Foto satu menu tidak tampil | Log build: *foto "…" gagal diunduh*. Menu tetap tampil tanpa foto; terbitkan ulang. |

## Rotasi rahasia

| Rahasia | Kapan diganti | Cara | Akibat saat diganti |
|---|---|---|---|
| `WEBSITE_API_TOKEN` | bila pernah terlihat orang lain / terkirim di chat, atau orang yang memegangnya keluar | buat token baru (B1), ganti di `.env` server **dan** di Cloudflare, `config:cache` | build yang sedang berjalan bisa gagal sekali; tekan Terbitkan lagi |
| Deploy Hook URL | bila bocor (siapa pun yang punya URL bisa memicu build) | Cloudflare › Deploy hooks › hapus yang lama, buat baru (B2), ganti di `.env`, `config:cache` | tidak ada; website tetap tampil |
| Akun Cloudflare | ganti pemegang akun | ganti password + verifikasi dua langkah, cabut sesi lama | tidak ada |

## Serah terima ke klien

Cukup catat di dokumen serah terima, tanpa adendum: hosting website di Cloudflare Pages (akun email W3S), domain di Rumahweb (akun W3S), DNS dikelola Cloudflare, dan siapa yang memegang aksesnya.
