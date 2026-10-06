# Deploy 3S ONE v3.1 ke DigitalOcean (latihan Oktober, resmi November)

Panduan langkah demi langkah memasang 3S ONE v3.1 di server baru DigitalOcean dengan alamat
**https://3sone.w3scatering.com**. Selama Oktober 2026 v2 tetap jadi sistem kerja di server lama,
sementara tim memakai v3.1 untuk latihan dan memetakan menu serta bahan baku. Pada 1 November
seluruh data v2 sampai 31 Oktober dipindah ke v3.1 bersama hasil pemetaan itu, dan v3.1 mulai
dipakai sungguhan. Ditulis untuk satu orang yang melakukan deploy; setiap langkah punya perintah
dan cara memastikannya berhasil.

Dokumen pendamping:

- [CUTOVER.md](CUTOVER.md) — rincian migrasi Master Menu Revamp (§5–7) dan antrean keputusan klien.
- [ACCESS.md](ACCESS.md) — peran dan izin; dipakai saat membagi akun (§12).
- [UAT.md](UAT.md) — uji terima bersama Owner.

---

## 0. Gambaran rencana

```
OKTOBER                                          1 NOVEMBER
SERVER LAMA                DROPLET BARU          DROPLET BARU
┌──────────────┐  dump    ┌───────────────────┐  ┌────────────────────────────────┐
│ 3S v2        │ ───────► │ po_workflow_      │  │ po_workflow  (RESMI)           │
│ semua input  │  awal    │ latihan           │  │ = dump v2 s.d. 31 Okt          │
│ harian       │  Okt     │ latihan alur +    │─►│ + data master hasil pemetaan   │
└──────┬───────┘          │ pemetaan menu &   │  │   dari po_workflow_latihan     │
       │                  │ bahan baku        │  │ transaksi latihan TIDAK ikut   │
       │ dump 31 Okt      └───────────────────┘  └────────────────────────────────┘
       └──────────────────────────────────────────────►  ▲
                                                         v2 dibekukan
```

| Kapan | Apa | Bagian |
|---|---|---|
| Minggu 1 Okt | Siapkan kode, buat Droplet, amankan server, pasang software | §1–§7 |
| Minggu 1 Okt | **Gladi**: salin data v2 + Master Menu ke database latihan, DNS + HTTPS, smoke test | §8–§11 |
| Hari-0 (setelah presentasi 7 Okt) | Isi ulang database latihan dengan data terbaru, buka untuk tim | §12 |
| Oktober | Latihan alur + pemetaan; progres dipantau tiap Senin | §13 |
| ±27 Okt | Gladi pindah: uji §14 di staging | §14.2 |
| 31 Okt malam / 1 Nov pagi | **Pindah**: database resmi dibuat, v3.1 resmi, v2 dibekukan | §14 |

**Prinsip Oktober (perlu disetujui Owner sebelum Hari-0):**

- v2 tetap sistem kerja untuk semua bagian. Tidak ada input ganda.
- Di v3.1, **transaksi** (PO, SPK Produksi, Form Kebutuhan, pembelian, kas, opname) adalah
  latihan dan **dibuang** pada 1 November. Semua tombol boleh dicoba.
- **Data master** di v3.1 (bahan, harga, satuan, konversi, resep, pencocokan menu, supplier,
  pekerjaan menu, pengaturan, akun dan peran, foto katalog) adalah pekerjaan sungguhan dan
  **dibawa** ke November. Rinciannya di §13.2.

### Yang harus ada di tangan sebelum mulai

- [ ] Akun DigitalOcean dengan metode bayar aktif.
- [ ] Akses ke panel DNS tempat `w3scatering.com` dikelola (registrar atau Cloudflare).
- [ ] Akses SSH ke server lama v2 (untuk `mysqldump`).
- [ ] Akses repo GitHub `reinhart-juniars/po-workflow` (untuk menambah deploy key).
- [ ] `app.db` Master Menu Revamp terbaru dari laptop klien.
- [ ] Daftar orang yang akan memakai v3.1 beserta bagiannya (dapur, gudang, supervisor gudang).
- [ ] Password manager untuk menyimpan password DB dan server. **Jangan** simpan di chat,
      catatan HP, atau screenshot.

### Tentang alamat `3sone.w3scatering.com`

Cek dulu ke mana alamat itu menunjuk sekarang: `nslookup 3sone.w3scatering.com`.

- **Kasus A — belum dipakai** (tidak menjawab IP, atau menjawab IP yang bukan server v2):
  ikuti panduan apa adanya. v2 tetap di alamat lamanya.
- **Kasus B — sudah menunjuk ke server v2** (langkah §0a CUTOVER.md sudah dijalankan): v2 butuh
  alamat lain selama Oktober, mis. `3sone-v2.w3scatering.com`. Lihat kotak "Kasus B" di §10.

---

## Sebelum mulai: root, deploy, dan PuTTY

Bagian ini untuk yang belum pernah mengurus server. Baca sekali sampai habis sebelum §1.

### Apa itu root dan deploy

Server Ubuntu punya beberapa akun (user). Kita hanya memakai dua:

| User | Siapa dia | Dipakai untuk |
|---|---|---|
| `root` | "super admin" server: boleh melakukan apa saja, termasuk menghapus seluruh sistem tanpa ditanya | **Hanya di §3**, sekali: memperbarui sistem dan membuat user `deploy`. Setelah §3, login sebagai root **dimatikan** |
| `deploy` | user biasa yang kita buat sendiri, pemilik folder aplikasi | **Semua pekerjaan lain**: memasang software, mengambil kode, menjalankan `php artisan`, deploy, backup |

Kenapa tidak root saja untuk semuanya? Kalau ada yang berhasil masuk sebagai root, seluruh server
jatuh. User `deploy` hanya bisa menjadi "super admin" sementara lewat `sudo` (lihat di bawah), dan
setiap kali itu terjadi server meminta password `deploy` — satu lapis pengaman tambahan.

### Cara tahu sedang login sebagai siapa

Lihat tulisan di depan kursor (disebut *prompt*) **sebelum** menempel perintah apa pun:

| Prompt | Artinya |
|---|---|
| `root@3sone-prod:~#` | login sebagai **root** (tanda akhirnya `#`) |
| `deploy@3sone-prod:~$` | login sebagai **deploy** (tanda akhirnya `$`) |
| `deploy@3sone-prod:/var/www/po-workflow$` | deploy, sedang berada di folder aplikasi |

Ragu? Ketik `whoami` lalu Enter: jawabannya `root` atau `deploy`.

Di setiap bagian dokumen ini ada baris **Login sebagai:** yang menyebut user dan sesi PuTTY-nya.

### `sudo`: menjadi admin untuk satu perintah

`deploy` menjalankan perintah sistem dengan menulis `sudo` di depannya, mis. `sudo apt install nginx`.
Pertama kali (dan setelah ±15 menit tidak dipakai) server menanyakan **password user deploy**.
Saat mengetik password, **tidak ada huruf atau bintang yang muncul** — itu normal, ketik saja lalu
Enter.

**Aturan emas — siapa menjalankan apa:**

| Pekerjaan | Login sebagai | Contoh |
|---|---|---|
| Memperbarui Ubuntu, membuat user deploy, swap, mematikan login root | `root` (hanya §3) | `apt -y upgrade`, `adduser deploy` |
| Memasang software, mengubah konfigurasi sistem, menyalakan ulang layanan | `deploy` **dengan** `sudo` | `sudo apt install ...`, `sudo nano /etc/nginx/...`, `sudo systemctl reload nginx` |
| Membuat database dan user MySQL | `deploy` dengan `sudo mysql` | §5 |
| **Semua perintah aplikasi**: `git`, `composer`, `npm`, `php artisan`, `mysql`/`mysqldump` harian, `~/bin/deploy.sh`, `~/bin/backup-db.sh` | `deploy` **tanpa** `sudo` | `php artisan migrate --force` |

> **Jangan pernah** menjalankan `sudo php artisan ...`, `sudo composer ...`, `sudo npm ...`, atau
> `sudo git ...` di folder aplikasi. Berkas yang dibuat jadi milik root, lalu web dan user deploy
> tidak bisa menulisnya (gejalanya: *Permission denied* atau halaman 500). Kalau terlanjur:
> `sudo chown -R deploy:www-data /var/www/po-workflow`, lalu ulangi perintah izin di §6.

### Memasang PuTTY

1. Unduh dari situs resminya: **https://www.chiark.greenend.org.uk/~sgtatham/putty/latest.html**
   → *MSI ('Windows Installer')* **64-bit x86**. Jangan unduh PuTTY dari situs lain.
2. Pasang dengan pilihan bawaan. Yang ikut terpasang dan kita pakai:
   - **PuTTY** — jendela terminal untuk masuk ke server;
   - **PuTTYgen** — pembuat kunci SSH (§2);
   - **pscp** — mengirim berkas dari laptop ke server lewat PowerShell (§8.1).
3. Opsional tapi sangat membantu untuk yang belum terbiasa: **WinSCP**
   (https://winscp.net/eng/download.php) — memindahkan berkas dengan seret-lepas seperti
   Windows Explorer. Ia memakai kunci PuTTY yang sama.

### Kebiasaan di jendela PuTTY

| Ingin | Caranya |
|---|---|
| Menyalin teks dari PuTTY | cukup **blok dengan mouse** — otomatis tersalin |
| Menempel ke PuTTY | **klik kanan** di jendela PuTTY (Ctrl+V tidak berfungsi) |
| Menjalankan blok perintah dari dokumen ini | salin satu blok utuh, klik kanan di PuTTY, tekan Enter bila baris terakhir belum jalan. Tunggu prompt muncul lagi sebelum blok berikutnya |
| Membatalkan perintah yang macet / salah | **Ctrl+C** |
| Melihat perintah sebelumnya | panah **↑** |
| Keluar dari server | ketik `exit`, atau tutup jendelanya |

Blok yang diawali `cat > ... <<'EOF'` atau `sudo tee ... <<'EOF'` menulis berkas dan **harus
disalin sampai baris `EOF`**. Kalau prompt berubah menjadi `>` dan diam, berarti baris `EOF`
tertinggal: ketik `EOF` lalu Enter, atau Ctrl+C lalu tempel ulang bloknya.

Perintah yang berisi `TEMPEL_PASSWORD_DI_SINI`, `IP_BARU`, `EMAIL_KAMU`, dan sejenisnya: tempel
dulu ke Notepad, ganti tulisannya dengan nilai sebenarnya, baru salin ke PuTTY.

**nano** adalah penyunting teks di server (dipakai di §7 dan §10.2). Gerakkan kursor dengan panah,
tempel dengan klik kanan, lalu **Ctrl+O** + Enter untuk menyimpan dan **Ctrl+X** untuk keluar.
**Ctrl+W** untuk mencari tulisan.

---

## 1. Siapkan kode (di laptop)

Server hanya menarik dari `main`. Cabang `feature/inventory-terpadu` harus sudah digabung.

```powershell
cd C:\Herd\po-workflow
git checkout feature/inventory-terpadu
php artisan test --compact          # harus hijau semua
vendor/bin/pint --dirty
git push origin feature/inventory-terpadu
# buat Pull Request ke main di GitHub, review, merge
git checkout main
git pull
git tag -a v3.1.0 -m "3S ONE v3.1 - rilis server DigitalOcean"
git push origin v3.1.0
```

Tag `v3.1.0` adalah titik kembali kalau rilis berikutnya bermasalah (§15).

---

## 2. Buat Droplet

Di DigitalOcean: **Create → Droplets**.

| Pilihan | Isi | Alasan |
|---|---|---|
| Region | **Singapore (SGP1)** | paling dekat ke Indonesia |
| Image | **Ubuntu 24.04 (LTS) x64** | PHP 8.3 bawaan; didukung sampai 2029 |
| Size | Basic · **Premium AMD · 1 vCPU / 2 GB RAM / 50 GB NVMe** (sama dengan server v2) | cukup untuk mulai, asal swap §3 dipasang; NVMe mempercepat MySQL. Bila grafik RAM sering > 85% atau CPU mentok saat latihan, naikkan ke 2 vCPU / 4 GB lewat **Resize → CPU and RAM only** (mati beberapa menit, data aman, bisa diturunkan lagi) |
| Authentication | **SSH Key** (bukan password) | lihat di bawah |
| Backups | **Aktifkan** (mingguan atau harian) | salinan seluruh disk; berbayar tambahan ±20–30% harga Droplet, cek di halaman |
| Monitoring | centang *Improved metrics monitoring* | gratis; grafik CPU/RAM/disk |
| Hostname | `3sone-prod` | |

### 2.1 Membuat kunci SSH dengan PuTTYgen (sekali saja, sebelum membuat Droplet)

Kunci SSH adalah pengganti password untuk masuk ke server: satu berkas rahasia di laptop
(*private key*, berakhiran `.ppk`) dan satu teks yang boleh dibagikan (*public key*) yang dipasang
di server. Server hanya membuka pintu untuk laptop yang memegang private key-nya.

1. Buka **PuTTYgen** (menu Start → ketik *PuTTYgen*).
2. Di bawah, pada *Type of key to generate*, pilih **EdDSA**, dan di sebelahnya pastikan
   **Ed25519 (255 bits)**.
3. Klik **Generate**, lalu gerak-gerakkan mouse di area kosong sampai bar hijau penuh.
4. Isi **Key comment**: `laptop-reinhart`.
5. Isi **Key passphrase** dan **Confirm passphrase** dengan kalimat sandi yang kuat (simpan di
   password manager). Kalau laptop hilang, kunci ini tidak bisa dipakai orang lain tanpa passphrase.
6. Klik **Save private key** → simpan sebagai `C:\Users\NAMA_KAMU\.ssh\3sone-laptop.ppk`
   (buat folder `.ssh` bila belum ada). **Berkas ini rahasia**: jangan dikirim ke siapa pun,
   jangan diunggah ke Drive.
7. Blok seluruh teks di kotak atas *Public key for pasting into OpenSSH authorized_keys file*
   (diawali `ssh-ed25519 ...`), salin.
8. Di DigitalOcean: **Settings → Security → Add SSH Key** (atau di halaman Create Droplet bagian
   *Authentication → SSH Key → New SSH Key*), tempel teks tadi, beri nama `laptop-reinhart`.

> Sudah punya kunci OpenSSH (`id_ed25519`) dari `ssh-keygen`? PuTTY tidak bisa memakainya langsung:
> di PuTTYgen pilih **Conversions → Import key**, pilih berkas `id_ed25519`, lalu **Save private
> key** sebagai `.ppk`. Public key-nya tetap sama, tidak perlu ditambahkan lagi ke DigitalOcean.

### 2.2 Setelah Droplet jadi

1. **Networking → Reserved IPs → Assign** ke Droplet ini. Pakai Reserved IP ini di DNS, supaya
   alamatnya tetap sama walau Droplet dibangun ulang. Selanjutnya disebut `IP_BARU`.
2. **Monitoring → Uptime → Create check** untuk `https://3sone.w3scatering.com/up` (isi setelah
   §10 selesai), kirim peringatan ke email kamu. `/up` adalah halaman cek kesehatan bawaan Laravel.

> **Droplet sudah terlanjur dibuat dengan *Password*, bukan SSH Key?** Tidak perlu dibuat ulang.
> 1. Buat kunci seperti §2.1 (langkah 1–7).
> 2. Buka PuTTY, isi *Host Name* `IP_BARU`, klik **Open**, login sebagai `root` dengan password
>    Droplet (password tidak terlihat saat diketik).
> 3. Jalankan `mkdir -p ~/.ssh && chmod 700 ~/.ssh && nano ~/.ssh/authorized_keys`, klik kanan
>    untuk menempel public key (`ssh-ed25519 ...`) di baris baru, Ctrl+O, Enter, Ctrl+X, lalu
>    `chmod 600 ~/.ssh/authorized_keys` dan `exit`.
> 4. Lanjut ke §2.3. Login password root dimatikan di §3, jadi password itu tidak dipakai lagi.

### 2.3 Simpan dua sesi PuTTY: `3sone-root` dan `3sone-deploy`

Supaya tidak mengetik alamat dan memilih kunci setiap kali, simpan pengaturannya sebagai *sesi*.

1. Buka **PuTTY**. Halaman pertama (*Session*): **Host Name** = `IP_BARU`, **Port** = `22`,
   **Connection type** = `SSH`.
2. Menu kiri **Connection → Data**: **Auto-login username** = `root`.
3. Menu kiri **Connection**: **Seconds between keepalives** = `30` (supaya jendela tidak putus
   sendiri saat ditinggal).
4. Menu kiri **Connection → SSH → Auth → Credentials**: pada *Private key file for
   authentication*, klik **Browse** dan pilih `3sone-laptop.ppk`.
5. Menu kiri **Window**: **Lines of scrollback** = `5000` (supaya keluaran panjang bisa digulir).
6. Kembali ke **Session** paling atas: di *Saved Sessions* ketik `3sone-root`, klik **Save**.
7. Buat sesi kedua: klik `3sone-root` di daftar, **Load**, ubah **Connection → Data → Auto-login
   username** menjadi `deploy`, kembali ke **Session**, ketik `3sone-deploy` di *Saved Sessions*,
   klik **Save**.

Masuk ke server: buka PuTTY, klik dua kali nama sesinya. Saat pertama kali, muncul **PuTTY
Security Alert** tentang *host key* — itu wajar untuk server baru: klik **Accept**. Lalu isi
passphrase kunci (dari langkah 2.1). Kalau peringatan itu muncul lagi di kemudian hari padahal
servernya sama dan tidak dibangun ulang, **jangan** klik Accept — tanyakan dulu.

> Malas mengetik passphrase berulang-ulang? Jalankan **Pageant** (ikut terpasang bersama PuTTY),
> klik kanan ikonnya di pojok kanan bawah → *Add Key* → pilih `3sone-laptop.ppk`, isi passphrase
> sekali. Selama Pageant hidup, PuTTY, pscp, dan WinSCP tidak menanyakannya lagi.

✅ Cek: sesi **3sone-root** masuk tanpa ditanya password server dan menampilkan prompt
`root@3sone-prod:~#`. (Sesi `3sone-deploy` baru bisa dipakai setelah §3.)

---

## 3. Amankan server (login pertama sebagai root)

> **Login sebagai:** `root` · PuTTY sesi **3sone-root** · prompt `root@3sone-prod:~#`
> Ini **satu-satunya** bagian yang memakai root.

**Langkah 1 — perbarui sistem.** Buka sesi `3sone-root`, lalu jalankan:

```bash
apt update && apt -y upgrade
timedatectl set-timezone Asia/Jakarta
```

Kalau di tengah jalan muncul layar ungu bertanya soal *configuration file* atau *services to
restart*, tekan **Enter** (pilihan bawaan sudah benar).

**Langkah 2 — buat user deploy:**

```bash
adduser deploy
```

Server bertanya beberapa hal:

- *New password* dan *Retype new password* → isi password kuat untuk `deploy`, simpan di password
  manager. Huruf tidak muncul saat diketik; itu normal. **Password ini nanti diminta setiap kali
  memakai `sudo`.**
- *Full Name, Room Number, Work Phone, Home Phone, Other* → tekan **Enter** saja (kosong).
- *Is the information correct?* → ketik `Y`, Enter.

Lalu beri hak `sudo` dan salin kunci SSH root ke deploy (supaya kunci PuTTY yang sama bisa
dipakai untuk masuk sebagai deploy):

```bash
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
```

**Langkah 3 — swap 2 GB** (penyangga saat composer/npm butuh memori lebih; wajib untuk Droplet
2 GB):

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

**Langkah 4 — uji login deploy di jendela kedua. Jendela root jangan ditutup dulu.**

1. Buka PuTTY **baru** (jendela kedua), klik dua kali sesi **3sone-deploy**.
2. Harus masuk tanpa password server, dengan prompt `deploy@3sone-prod:~$`.
3. Di jendela kedua itu ketik `sudo whoami` → isi password deploy → jawabannya harus `root`.

Kalau langkah ini gagal, **jangan lanjut**. Kembali ke jendela root dan periksa langkah 2
(biasanya `rsync` belum dijalankan). Jendela root yang masih terbuka adalah jalan keluar kita.

**Langkah 5 — matikan login root dan login password.** Kembali ke **jendela root**:

```bash
cat > /etc/ssh/sshd_config.d/00-hardening.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
EOF
sshd -t && systemctl restart ssh
sshd -T | grep -E '^(permitrootlogin|passwordauthentication) '
```

Baris terakhir harus menjawab `permitrootlogin no` dan `passwordauthentication no`. Nama berkas
diawali `00-` dengan sengaja: sshd memakai nilai yang dibaca **pertama**, dan image DigitalOcean
kadang sudah punya `50-cloud-init.conf` yang menyalakan login password. `sshd -t` memeriksa
penulisan dulu, sehingga salah ketik tidak mengunci kita di luar.

Jendela root yang sedang terbuka **tidak** terputus oleh perintah ini. Lanjutkan di jendela yang
sama:

```bash
# Firewall: hanya SSH (web dibuka di §4)
ufw allow OpenSSH
ufw --force enable

# Blokir otomatis IP yang menebak-nebak password SSH
apt install -y fail2ban

# Pembaruan keamanan otomatis (biasanya sudah aktif di Ubuntu; pilih "Yes")
dpkg-reconfigure -plow unattended-upgrades
exit
```

✅ Cek:

- Buka lagi sesi **3sone-root** → harus **ditolak** (*Server refused our key* atau
  *Access denied*). Itu tanda berhasil. Sesi `3sone-root` boleh dihapus dari PuTTY (pilih → *Delete*);
  tidak akan dipakai lagi.
- Sesi **3sone-deploy** tetap masuk.

Mulai sini **semua** perintah dijalankan di sesi **3sone-deploy**, dengan `sudo` hanya bila
perintahnya mengubah sistem (aturan emas di bagian *Sebelum mulai*).

> **Terkunci di luar?** (kunci `.ppk` hilang, laptop rusak, atau salah konfigurasi SSH.)
> Di DigitalOcean buka Droplet → **Access → Launch Droplet Console**, isi user `deploy`. Konsol web
> ini tidak memakai kunci laptop. Dari sana kunci baru bisa ditambahkan ke
> `/home/deploy/.ssh/authorized_keys`.

---

## 4. Pasang software

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

Perintah di bagian ini memakai `sudo` karena memasang software sistem. Saat pertama kali, isi
password deploy.

```bash
sudo apt install -y nginx mysql-server git unzip curl composer certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache

# Node.js 22 untuk build aset (Vite 7 butuh Node >= 20.19; Node bawaan Ubuntu terlalu tua)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

sudo ufw allow 'Nginx Full'
```

**Kenapa PHP 8.3, bukan 8.2:** beberapa paket di `composer.lock` (openspout untuk Excel, PHPUnit)
membutuhkan PHP ≥ 8.3, dan php-css-parser (dompdf) paling tinggi 8.4. PHP 8.3 bawaan Ubuntu 24.04
memenuhi keduanya. `php8.3-sqlite3` wajib: dipakai untuk membaca `app.db` Master Menu.

Pengaturan PHP untuk aplikasi ini:

```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-3sone.ini > /dev/null <<'EOF'
memory_limit = 512M          ; laporan PDF/Excel besar
upload_max_filesize = 10M    ; foto Katalog Menu (aplikasi membatasi 5 MB)
post_max_size = 12M
max_execution_time = 120
date.timezone = Asia/Jakarta
expose_php = Off
EOF
```

**Izin berkas bersama** antara `deploy` (yang menjalankan artisan) dan `www-data` (yang menjalankan
web): keduanya menulis ke `storage/`. Supaya tidak saling mengunci berkas log:

```bash
sudo usermod -aG www-data deploy
sudo mkdir -p /etc/systemd/system/php8.3-fpm.service.d
printf '[Service]\nUMask=0002\n' | sudo tee /etc/systemd/system/php8.3-fpm.service.d/umask.conf
sudo systemctl daemon-reload && sudo systemctl restart php8.3-fpm
echo 'umask 002' >> ~/.bashrc
# deploy boleh me-reload php-fpm tanpa password (dipakai skrip deploy §13.4)
echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/deploy-fpm
sudo chmod 440 /etc/sudoers.d/deploy-fpm
exit   # keluar dulu supaya grup www-data dan umask berlaku
```

Tutup jendela PuTTY itu, lalu buka lagi sesi **3sone-deploy**. Pengaturan grup baru hanya berlaku
untuk login berikutnya.

✅ Cek: `php -v` → 8.3.x · `php -m | grep -E 'intl|pdo_mysql|pdo_sqlite|zip|gd'` → lima baris ·
`node -v` → v22.x · `composer -V` · `id` menyebut grup `www-data`.

---

## 5. Database MySQL

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

`sudo mysql` di bawah membuka MySQL sebagai admin database (perlu `sudo`); setelah database
dan user dibuat, aplikasi memakai user MySQL `po_workflow`, bukan admin.

Buat password acak dan simpan di password manager (jangan ditempel ke chat):

```bash
openssl rand -base64 24
```

```bash
sudo mysql
```

```sql
CREATE DATABASE po_workflow_latihan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE po_workflow         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE po_workflow_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'po_workflow'@'localhost' IDENTIFIED BY 'TEMPEL_PASSWORD_DI_SINI';
GRANT ALL PRIVILEGES ON po_workflow_latihan.* TO 'po_workflow'@'localhost';
GRANT ALL PRIVILEGES ON po_workflow.*         TO 'po_workflow'@'localhost';
GRANT ALL PRIVILEGES ON po_workflow_staging.* TO 'po_workflow'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

| Database | Dipakai | Isi |
|---|---|---|
| `po_workflow_latihan` | Oktober | data v2 awal Oktober + Master Menu; tempat tim latihan dan memetakan |
| `po_workflow` | mulai 1 November | dibuat di §14 dari dump v2 akhir Oktober + data master dari database latihan |
| `po_workflow_staging` | kapan saja | gladi migrasi; isinya boleh dibuang |

Simpan kredensial di berkas yang hanya bisa dibaca `deploy`, supaya `mysql`/`mysqldump` (termasuk
backup otomatis) tidak perlu password di baris perintah:

```bash
cat > ~/.my.cnf <<'EOF'
[client]
user=po_workflow
password=TEMPEL_PASSWORD_DI_SINI
EOF
chmod 600 ~/.my.cnf
```

MySQL Ubuntu hanya mendengar di `127.0.0.1`, jadi tidak terbuka ke internet.

✅ Cek: `mysql -e "SHOW DATABASES;"` (tanpa password) menampilkan ketiga database.

---

## 6. Ambil kode

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

Bagian ini dijalankan **tanpa** `sudo`, kecuali tiga perintah yang tertulis `sudo` (membuat
folder di `/var/www` dan mengatur izin). `git clone`, `composer`, dan `npm` harus dijalankan sebagai
deploy biasa supaya berkasnya milik deploy.

**Deploy key** — kunci khusus server yang hanya bisa *membaca* repo:

```bash
ssh-keygen -t ed25519 -C "3sone-prod-deploy" -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_deploy
  IdentitiesOnly yes
EOF
```

Tempel isi `.pub` ke GitHub: repo **po-workflow → Settings → Deploy keys → Add deploy key**,
judul `3sone-prod`, **jangan** centang *Allow write access*.

```bash
ssh -T git@github.com        # jawab "yes"; harus menyapa "...successfully authenticated"
sudo mkdir -p /var/www/po-workflow
sudo chown deploy:www-data /var/www/po-workflow
git clone git@github.com:reinhart-juniars/po-workflow.git /var/www/po-workflow
cd /var/www/po-workflow
git checkout main

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

# storage & cache bisa ditulis web dan deploy; berkas baru mewarisi grup www-data
sudo chown -R deploy:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

✅ Cek: `ls public/build/manifest.json` ada.

---

## 7. Berkas `.env` produksi

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

```bash
cd /var/www/po-workflow
cp .env.example .env
chmod 600 .env                 # hanya deploy yang bisa membaca
php artisan key:generate
nano .env
```

Ubah/isi baris berikut (yang tidak disebut biarkan bawaan):

```dotenv
APP_NAME="3S ONE"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://3sone.w3scatering.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=po_workflow_latihan      # Oktober; diganti ke po_workflow di §14
DB_USERNAME=po_workflow
DB_PASSWORD=TEMPEL_PASSWORD_DI_SINI
DB_DATABASE_STAGING=po_workflow_staging

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync

MASTER_MENU_DB_PATH=/home/deploy/cutover/app.db
```

Catatan:

- **`APP_KEY` dibuat baru**, tidak disalin dari server v2. Aplikasi tidak menyimpan kolom
  terenkripsi, jadi data v2 tetap terbaca; yang terdampak hanya sesi login (semua orang memang
  login baru di alamat baru). Password pengguna tidak terpengaruh.
- `APP_DEBUG=false` wajib: kalau `true`, halaman error menampilkan isi `.env` ke siapa pun.
- `QUEUE_CONNECTION=sync`: server tidak menjalankan queue worker, dan notifikasi lonceng memang
  dikirim langsung (`Notify::sendNow`).
- `SESSION_SECURE_COOKIE=true` berarti login **hanya** jalan lewat HTTPS. Sebelum §10 selesai,
  login lewat `http://IP_BARU` akan gagal — itu wajar.
- `MASTER_MENU_DB_PATH` hanya dibutuhkan saat migrasi; dihapus lagi di §14.
- `DB_DATABASE` menentukan database mana yang dilayani. Peralihan 1 November hanya mengganti baris
  ini, sehingga kembali ke database latihan juga cukup mengganti satu baris (§15).

✅ Cek: `ls -l .env` → `-rw------- deploy deploy`. `grep APP_DEBUG .env` → `false`.

---

## 8. Isi database latihan: data v2 + Master Menu (gladi, diulang di Hari-0)

> **Login sebagai:** §8.1 memakai server **lama** dan laptop (lihat di dalamnya); §8.2–§8.4 memakai
> `deploy` · sesi **3sone-deploy**.

Langkah ini mengisi `po_workflow_latihan` dan dijalankan **dua kali**: sekali saat gladi supaya
semua masalah ketahuan lebih awal, lalu sekali lagi di Hari-0 dengan data terbaru (§12). Data
gladi akan ditimpa, jadi bebas mencoba alur yang menulis data saat gladi.

### 8.1 Ambil dump dari server v2

Di **server lama** (buka PuTTY dengan sesi/akun yang selama ini dipakai untuk server v2):

```bash
mysql --version     # catat: MySQL 5.7 / 8.0 / MariaDB
mysqldump --single-transaction --routines --no-tablespaces -u USER_V2 -p po_workflow \
  | gzip > ~/v2-$(date +%F-%H%M).sql.gz
chmod 600 ~/v2-*.sql.gz
```

Pindahkan lewat laptop. Berkas ini berisi data pelanggan dan keuangan: jangan diunggah ke Drive,
WhatsApp, atau chat; hapus dari laptop setelah selesai.

**1. Siapkan folder tujuan di Droplet** (sesi **3sone-deploy**):

```bash
mkdir -p ~/cutover && chmod 700 ~/cutover
```

**2a. Cara paling mudah: WinSCP** (seret-lepas)

1. Buka WinSCP → **New Site**. *File protocol* `SFTP`, *Host name* `IP_LAMA`, user dan password
   (atau kunci) server v2 → **Login**. Seret `v2-....sql.gz` dari folder home server lama ke folder
   laptop, mis. `C:\cutover`.
2. **New Site** lagi: `SFTP`, *Host name* `IP_BARU`, *User name* `deploy`, kosongkan password →
   klik **Advanced → SSH → Authentication → Private key file** → pilih `3sone-laptop.ppk` → OK →
   **Save** (beri nama `3sone-deploy`) → **Login**.
3. Di panel kanan (server) buka folder `/home/deploy/cutover`. Seret `v2-....sql.gz` dan `app.db`
   (dari `Master Menu Revamp\app\data\app.db`) ke sana.

**2b. Atau lewat PowerShell dengan pscp** (ikut terpasang bersama PuTTY):

```powershell
cd C:\cutover
pscp USER_V2@IP_LAMA:v2-2026-10-12-0600.sql.gz .
pscp -i $HOME\.ssh\3sone-laptop.ppk .\v2-2026-10-12-0600.sql.gz deploy@IP_BARU:/home/deploy/cutover/
pscp -i $HOME\.ssh\3sone-laptop.ppk "C:\PATH\Master Menu Revamp\app\data\app.db" deploy@IP_BARU:/home/deploy/cutover/app.db
```

Kalau PowerShell menjawab `pscp` tidak dikenali, ganti `pscp` dengan
`& 'C:\Program Files\PuTTY\pscp.exe'`.

**3. Hapus dump dari laptop** setelah berkasnya sampai di Droplet (`Remove-Item C:\cutover\v2-*.sql.gz`,
lalu kosongkan Recycle Bin).

Di Droplet (sesi **3sone-deploy**):

```bash
chmod 600 ~/cutover/*
sha256sum ~/cutover/app.db | tee ~/cutover/app.db.sha256   # jejak: data Master Menu mana yang dipakai
```

### 8.2 Impor dan naikkan skema ke v3.1

```bash
cd /var/www/po-workflow
# Gladi kedua / Hari-0: kosongkan dulu database latihan (data gladi dibuang)
mysql -e "DROP DATABASE po_workflow_latihan; CREATE DATABASE po_workflow_latihan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

zcat ~/cutover/v2-*.sql.gz | mysql po_workflow_latihan
php artisan migrate --force          # menambah tabel & kolom v3.0 dan v3.1 di atas data v2
php artisan access:sync              # peran & izin modul (production, inventory, inventory-supervisor, marketing)
php artisan storage:link             # foto Katalog Menu
```

Kalau ada beberapa dump di `~/cutover`, ganti `v2-*.sql.gz` dengan nama berkas yang tepat.
Kalau impor gagal dengan pesan *Unknown collation* atau *DEFINER*, catat versi MySQL server lama
dan pesan errornya; jangan lanjut ke langkah berikutnya sebelum impor bersih.

✅ Cek: `php artisan migrate:status | grep -c Pending` → `0`. `php artisan access:sync` menampilkan
tabel peran yang sama dengan [ACCESS.md](ACCESS.md).

### 8.3 Pindahkan data Master Menu Revamp

Sama persis dengan [CUTOVER.md §5–6](CUTOVER.md): uji di staging dulu, baru produksi.

```bash
# Gladi di staging
php artisan db:clone-to-staging --force
php artisan inventory:migrate-master-menu --database=mysql_staging --dry-run
php artisan inventory:migrate-master-menu --database=mysql_staging --force
php artisan inventory:map-recipes-to-products --database=mysql_staging
php artisan inventory:validate-migration --database=mysql_staging --fix
```

Lanjut hanya bila baris terakhir `validate-migration` menyebut **0 error** (peringatan boleh,
dicatat untuk Owner). Lalu ke database latihan (koneksi bawaan, karena `DB_DATABASE` menunjuknya):

```bash
php artisan inventory:migrate-master-menu --dry-run
php artisan inventory:migrate-master-menu --force
php artisan inventory:map-recipes-to-products
php artisan inventory:validate-migration --fix
```

Catat angka antrean yang muncul (Bahan Belum Cocok, Konversi Satuan, Pencocokan Menu). Itu titik
awal pekerjaan pemetaan Oktober (§13.3); targetnya 0 sebelum 1 November.

`migrate-master-menu` hanya dijalankan di database latihan. Database resmi November **tidak**
menjalankannya lagi: data master dibawa dari database latihan (§14), lengkap dengan perbaikan tim
selama Oktober.

### 8.4 Cache konfigurasi

```bash
php artisan optimize            # config + route + view + event cache
sudo systemctl reload php8.3-fpm
```

Setiap kali `.env` diubah, jalankan lagi `php artisan optimize`. Setelah konfigurasi di-cache,
aplikasi web tidak membaca `.env` lagi.

---

## 9. nginx

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

```bash
sudo tee /etc/nginx/sites-available/3sone > /dev/null <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name 3sone.w3scatering.com;
    root /var/www/po-workflow/public;
    index index.php;
    charset utf-8;
    server_tokens off;
    client_max_body_size 10M;

    # Header keamanan
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-Robots-Tag "noindex, nofollow" always;      # aplikasi internal, jangan diindeks Google

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Aset hasil build punya nama ber-hash, aman disimpan lama di browser
    location /build/ {
        expires 1y;
        access_log off;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120;          # laporan tahunan
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

sudo ln -s /etc/nginx/sites-available/3sone /etc/nginx/sites-enabled/3sone
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

`location /build/` sengaja memakai `expires`, bukan `add_header`: di nginx, satu `add_header` di
dalam `location` membuang semua header keamanan dari blok `server`.

✅ Cek: `curl -sI http://IP_BARU -H "Host: 3sone.w3scatering.com" | head -1` → `HTTP/1.1 200` atau
`302` (dialihkan ke halaman masuk).

---

## 10. DNS + HTTPS

### 10.1 Record DNS

Di panel DNS `w3scatering.com`, tambahkan/ubah **satu** record:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `3sone` | `IP_BARU` (Reserved IP) | 300 |

Jangan sentuh record lain (`@`, `www`, `MX` — milik situs dan email W3S).
**Kalau DNS-nya di Cloudflare:** set record ini **DNS only** (awan abu-abu). Mode proxy (awan
oranye) membuat tautan jadi `http://` dan form gagal, karena aplikasi belum dikonfigurasi
mempercayai proxy.

Tunggu sampai `nslookup 3sone.w3scatering.com` di laptop menjawab `IP_BARU` (biasanya beberapa
menit, paling lama 24 jam).

> **Kasus B — `3sone` sedang dipakai v2.** Lakukan ini dulu, *sebelum* mengubah record di atas:
> 1. Tambahkan record `A · 3sone-v2 · IP_LAMA`.
> 2. Di server lama: tambahkan `3sone-v2.w3scatering.com` ke `server_name` nginx v2,
>    `sudo certbot --nginx -d 3sone-v2.w3scatering.com`, ubah `APP_URL` v2 ke alamat itu,
>    `php artisan config:cache`, dan hapus pengalihan 301 ke `3sone` bila ada.
> 3. Umumkan alamat v2 yang baru ke tim. Baru setelah itu ubah record `3sone` ke `IP_BARU` —
>    idealnya di malam sebelum Hari-0, bukan saat gladi.
> 4. Untuk gladi H-3, pakai alamat sementara `3sone-baru.w3scatering.com` → `IP_BARU`
>    (tambahkan juga ke `server_name` dan `APP_URL` Droplet selama gladi).

### 10.2 Sertifikat Let's Encrypt

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

```bash
sudo certbot --nginx -d 3sone.w3scatering.com --redirect -m EMAIL_KAMU --agree-tos --no-eff-email
sudo certbot renew --dry-run       # pastikan perpanjangan otomatis jalan
```

Setelah gembok terkunci, tambahkan HSTS (browser selalu memakai HTTPS untuk alamat ini). Buka
berkasnya dengan `sudo nano /etc/nginx/sites-available/3sone` (berkas sistem, jadi pakai `sudo`),
cari blok `server` yang kini berisi `listen 443 ssl` (Ctrl+W, ketik `443`), dan tambahkan di
bawah baris `server_name`:

```nginx
    add_header Strict-Transport-Security "max-age=31536000" always;
```

Sengaja **tanpa** `includeSubDomains` — subdomain lain milik W3S tidak boleh ikut dipaksa HTTPS.
`sudo nginx -t && sudo systemctl reload nginx`.

✅ Cek dari laptop:

```powershell
curl.exe -sI https://3sone.w3scatering.com/login
```

harus menampilkan `HTTP/1.1 200`, `strict-transport-security`, `x-frame-options`,
`x-content-type-options`, dan **tidak** ada `X-Powered-By`. `http://3sone...` harus pindah ke
`https://`.

---

## 11. Jadwal otomatis, backup, dan smoke test

### 11.1 Cron

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

Crontab dibuat untuk user **deploy** (bukan `sudo crontab`), supaya jadwal Laravel dan backup
berjalan sebagai pemilik aplikasi.

```bash
mkdir -p ~/bin ~/backups && chmod 700 ~/backups

cat > ~/bin/backup-db.sh <<'EOF'
#!/usr/bin/env bash
# Backup harian database resmi dan database latihan (hasil pemetaan Oktober), disimpan 14 hari.
set -euo pipefail
umask 077
DIR=/home/deploy/backups
for DB in po_workflow po_workflow_latihan; do
  # Lewati database yang belum ada atau masih kosong
  [ "$(mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'")" -gt 0 ] || continue
  FILE="$DIR/$DB-$(date +%F-%H%M).sql.gz"
  mysqldump --single-transaction --routines --no-tablespaces "$DB" | gzip > "$FILE"
  gzip -t "$FILE"                                 # berkas rusak = skrip gagal
  echo "$(date '+%F %T') OK $FILE $(du -h "$FILE" | cut -f1)"
done
find "$DIR" -name 'po_workflow*.sql.gz' -mtime +14 -delete
EOF
chmod 700 ~/bin/backup-db.sh
~/bin/backup-db.sh                                # uji sekali

crontab -e
```

`crontab -e` pertama kali bertanya penyunting mana yang dipakai: ketik `1` (nano), Enter. Pindah
ke baris paling bawah, tempel (klik kanan) isi berikut, lalu Ctrl+O, Enter, Ctrl+X.

Isi crontab:

```cron
# Jadwal Laravel (cek profit menu harian 06:30, dll.)
* * * * * cd /var/www/po-workflow && umask 002 && php artisan schedule:run >> /dev/null 2>&1
# Backup database tiap malam 02:15
15 2 * * * /home/deploy/bin/backup-db.sh >> /home/deploy/backups/backup.log 2>&1
```

Backup di Droplet yang sama tidak menolong kalau Droplet-nya hilang. Lapisan kedua sudah ada dari
*Backups* DigitalOcean (§2); tambahan yang disarankan: seminggu sekali tarik backup terbaru ke
laptop atau ke DigitalOcean Spaces, dan sebulan sekali **uji pulihkan** ke `po_workflow_staging`:

```bash
zcat ~/backups/po_workflow_latihan-TERBARU.sql.gz | mysql po_workflow_staging
```

✅ Cek: `php artisan schedule:list` menampilkan `profit:check` 06:30. Besok pagi,
`cat ~/backups/backup.log` berisi baris `OK`.

### 11.2 Smoke test (gladi dan Hari-0)

Login sebagai admin di `https://3sone.w3scatering.com` dan centang:

| # | Uji | Hasil yang benar |
|---|---|---|
| 1 | Halaman masuk | versi **v3.1** di kiri bawah, gembok terkunci |
| 2 | Login dengan akun v2 | berhasil (password sama dengan v2) |
| 3 | Buka tiap tab aplikasi: Owner, Admin, Accounting, Inventory, Sales, Marketing, Production, Delivery | tidak ada halaman error |
| 4 | Admin › daftar PO | PO terakhir sama dengan di v2 |
| 5 | Accounting › Laba Rugi bulan lalu | angka sama dengan v2 |
| 6 | Inventory › Item Inventaris, Resep & Menu | bahan & resep Master Menu tampil |
| 7 | Inventory › SPK Produksi › Buat dari Slot SPK → Form Kebutuhan → Cetak PDF | PDF terunduh |
| 8 | Marketing › Katalog Foto Menu › unggah satu foto | foto tampil (menguji `APP_URL` + `storage:link`) |
| 9 | Ctrl+K, ketik nama menu | hasil muncul |
| 10 | Buka `https://3sone.w3scatering.com/halaman-ngawur` | halaman 404 biasa, **tanpa** detail kode |
| 11 | `https://3sone.w3scatering.com/up` | halaman hijau "Application up" |

Bisa diotomatkan dari laptop saat gladi dengan `scripts/qa/smoke.mjs`
(`QA_BASE=https://3sone.w3scatering.com`, kredensial lewat variabel lingkungan; lihat komentar di
berkasnya). Alur Phase 3-nya menulis transaksi — aman di database latihan, **jangan** dijalankan
terhadap database resmi setelah 1 November.

Masalah yang biasa muncul:

| Gejala | Penyebab | Perbaikan |
|---|---|---|
| Login selalu kembali ke halaman masuk | akses lewat `http://` atau IP, sedangkan cookie wajib HTTPS | buka lewat `https://3sone...` |
| *500 Server Error* | lihat log | `tail -50 storage/logs/laravel-$(date +%F).log` |
| *Permission denied* di `storage/logs` | izin grup | ulangi perintah `chown`/`chmod` di §6 |
| *Vite manifest not found* | aset belum di-build | `npm ci && npm run build` |
| Perubahan `.env` tidak berpengaruh | konfigurasi ter-cache | `php artisan optimize` |
| *502 Bad Gateway* | php-fpm mati | `sudo systemctl status php8.3-fpm` |
| PuTTY: *Server refused our key* / *No supported authentication methods available* | user salah (root sudah dimatikan), atau sesi tidak menunjuk ke `.ppk` | pakai sesi **3sone-deploy**; cek **Connection → SSH → Auth → Credentials** |
| PuTTY: *Network error: Connection timed out* | IP salah, Droplet mati, atau firewall | cek `IP_BARU` di halaman Droplet; pastikan `ufw allow OpenSSH` sudah dijalankan |
| PuTTY: jendela tertutup sendiri saat ditinggal | koneksi idle diputus | set **Connection → Seconds between keepalives** = 30, simpan sesi |
| `deploy is not in the sudoers file` | `usermod -aG sudo deploy` terlewat di §3 | jalankan perintah itu di **jendela root yang masih terbuka** (§3 langkah 4), lalu keluar-masuk lagi sesi deploy. Karena itulah login root baru dimatikan setelah `sudo whoami` berhasil |
| *Permission denied* setelah menjalankan perintah aplikasi dengan `sudo` | berkas jadi milik root | `sudo chown -R deploy:www-data /var/www/po-workflow`, ulangi izin §6, jangan pakai `sudo` untuk artisan/composer/npm/git |

---

## 12. Hari-0: buka untuk latihan dan pemetaan

> **Login sebagai:** `deploy` · sesi **3sone-deploy** untuk perintah server; langkah 2–3 dan 5
> lewat browser.

Setelah presentasi pengguna (Rabu, 7 Oktober), di luar jam sibuk.

1. Ambil **dump v2 terbaru** dan **`app.db` terakhir**, ulangi **§8.1 sampai §8.4** (database
   latihan hasil gladi dikosongkan di §8.2).
2. **Pengaturan Inventory** (login sebagai Owner):
   - *Harga beli memperbarui harga master bahan* → **matikan** selama Oktober. Kalau menyala,
     harga beli yang diketik saat latihan menimpa harga bahan, dan harga keliru itu ikut terbawa
     ke November.
   - Angka lain (% OHC, % profit, batas profit, batas susut) diisi sungguhan; pengaturan dibawa.
3. **Akun pengguna** (Owner › Master User), sesuai [ACCESS.md](ACCESS.md):
   - pengguna v2 sudah ikut terbawa dengan password lamanya;
   - tim dapur → tambahkan peran **Production**; staf gudang → **Inventory**; yang menyetujui Form
     Kebutuhan → **Supervisor Gudang**;
   - pengguna Master Menu yang belum punya akun → buat baru dengan password sementara (wajib ganti
     saat masuk pertama); sampaikan langsung ke orangnya, bukan di grup.
4. **Lepas `app.db`**: hapus baris `MASTER_MENU_DB_PATH` dari `.env`, lalu `php artisan optimize`.
   Mulai sekarang bahan dan resep berubah di 3S ONE, sehingga jumlahnya sengaja berbeda dari
   `app.db`. Kalau baris ini dibiarkan, `validate-migration` melaporkan selisih itu sebagai
   error dan gladi pindah gagal palsu. Simpan `app.db` di `~/cutover` sebagai arsip.
5. Smoke test §11.2.
6. **Umumkan ke tim**: alamat latihan, aturan Oktober (§13.1), dan apa yang dibawa ke November
   (§13.2).

Catat jam dan hasil tiap langkah di checklist §17.

---

## 13. Oktober: latihan dan pemetaan

### 13.1 Aturan main

| Bagian | Pekerjaan sungguhan | Di 3S ONE v3.1 (latihan) |
|---|---|---|
| Admin | PO, SPK, dan **menu jual** (nama, harga, menu baru) tetap di v2 | coba alur PO → SPK → SPK Produksi; bantu Pencocokan Menu. Jangan mengubah menu jual di v3.1: perubahan itu tidak dibawa |
| Dapur | produksi Oktober tetap memakai Master Menu Revamp dan form kertas | latihan Form Kebutuhan, Lembar Kerja, Tutup SPK dengan SPK yang ada; memperbaiki resep dan takaran |
| Gudang / Supervisor | belanja seperti biasa | latihan Setujui / Periksa; merapikan Item Inventaris, Master Supplier, Konversi Satuan, Bahan Belum Cocok |
| Accounting, Sales | semua di v2 | boleh mencoba; tidak ada yang dibawa |
| Owner | laporan dan tutup buku di v2 | Pengaturan Inventory, memeriksa HPP resep, Pencocokan Menu |
| Marketing | — | foto Katalog Menu, centang tampil di website, SKU (dibawa) |

**Resep diubah di 3S ONE.** Master Menu Revamp masih dipakai dapur untuk produksi Oktober, tetapi
setiap perbaikan resep atau bahan dicatat di 3S ONE (kalau perlu untuk produksi Oktober, ubah juga
di Master Menu). Master Menu Revamp dibekukan pada 31 Oktober.

**Data percobaan:** bahan, resep, atau supplier yang dibuat hanya untuk mencoba diberi awalan
`[LATIHAN]` di namanya dan dihapus sebelum gladi pindah (§14.2), karena data master dibawa apa
adanya.

### 13.2 Yang dibawa ke November dan yang dibuang

| Dibawa dari database latihan | Dibuang (latihan) | Diambil dari v2 (versi v3.1 diabaikan) |
|---|---|---|
| Item Inventaris: bahan, satuan, harga, isi kemasan, stok minimum, kelompok | PO, SPK, DO yang dibuat di v3.1 | semua transaksi v2 sampai 31 Okt |
| Histori Harga bahan (kecuali yang berasal dari Form Kebutuhan latihan) | SPK Produksi, Form Kebutuhan, Lembar Kerja | pelanggan |
| Konversi Satuan | Kartu Stok, Opname Bahan, Stock Opname, Saldo Awal | menu jual: nama, harga, aktif/nonaktif |
| Resep & Menu (takaran, sub-resep, harga manual) | Pembelian Bahan Baku, kas, hutang dari Periksa | password pengguna lama |
| Keputusan Bahan Belum Cocok | Sales Actual, Barang Sisa | akun kas, kategori, data keuangan |
| Tautan Pencocokan Menu (menu ↔ resep, Tanpa Resep) | lonceng, audit log latihan | |
| Pekerjaan Menu, Pelaksana | | |
| Riwayat produksi dari Master Menu Revamp (arsip) | | |
| Master Supplier dan bahan yang dipasoknya | | |
| Pengaturan Inventory | | |
| Foto Katalog, centang website, SKU | | |
| Akun yang dibuat di v3.1 dan peran baru (Production, Inventory, Supervisor Gudang, Marketing) | | |

### 13.3 Progres pemetaan tiap Senin (15 menit, Owner + admin dapur)

Ambil angkanya dari badge menu kiri dan Dashboard Inventory:

| Angka | Hari-0 | 12 Okt | 19 Okt | 26 Okt | Target 1 Nov |
|---|---|---|---|---|---|
| Konversi Satuan › Belum Diatur | | | | | 0 |
| Bahan Belum Cocok | | | | | 0 |
| Pencocokan Menu: dari 60 menu terlaris, yang belum ditautkan | | | | | 0 |
| Resep berstatus Lengkap (dari resep yang tertaut ke menu) | | | | | semua |
| Kartu Profit menu keseluruhan di Dashboard | – | | | | tampil angka |

Konversi Satuan dikerjakan lebih dulu: pengaruhnya ke jumlah resep yang HPP-nya lengkap paling
besar. Kalau pada 26 Oktober angkanya masih jauh dari target, Owner memutuskan: pindah tetap
1 November (sisa pemetaan dilanjutkan di sistem resmi, menu terlaris didahulukan) atau mundur
satu minggu.

### 13.4 Memperbarui kode selama Oktober

> **Login sebagai:** `deploy` · PuTTY sesi **3sone-deploy** · prompt `deploy@3sone-prod:~$`

Perbaikan selama masa latihan hampir pasti ada. Buat skrip deploy sekali:

```bash
cat > ~/bin/deploy.sh <<'EOF'
#!/usr/bin/env bash
# Pakai: deploy.sh            -> rilis terbaru di main
#        deploy.sh v3.1.0     -> kembali ke tag tertentu (lihat §15 soal database)
set -euo pipefail
umask 002
REF="${1:-main}"
APP=/var/www/po-workflow
trap 'echo; echo "GAGAL. Aplikasi masih mode perawatan. Perbaiki, lalu: cd $APP && php artisan up"' ERR

/home/deploy/bin/backup-db.sh
cd "$APP"
php artisan down --retry=30
git fetch --tags --prune origin
if [ "$REF" = "main" ]; then
  git checkout main && git pull --ff-only origin main
else
  git checkout --detach "$REF"
fi
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --force
php artisan access:sync
php artisan optimize
sudo systemctl reload php8.3-fpm
php artisan up
echo "Selesai: $(git describe --tags --always) $(tail -1 version.txt | cut -c1-40)"
EOF
chmod 700 ~/bin/deploy.sh
```

Alur rilis: di laptop jalankan tes + pint → merge ke `main` → buat tag (`v3.1.1`, …) → buka sesi
**3sone-deploy** dan jalankan `~/bin/deploy.sh` (tanpa `sudo`; skrip sendiri yang memanggil
`sudo` untuk me-reload php-fpm, dan itu tidak menanyakan password karena sudah diizinkan di §4). Pilih jam sepi; aplikasi menampilkan halaman perawatan sekitar 1–2 menit.
Skrip ini memigrasi database yang sedang dilayani (`DB_DATABASE`). Selama Oktober itu database
latihan; database resmi baru dibuat di §14 dan langsung memakai skema terbaru.

### 13.5 Memantau

```bash
tail -f /var/www/po-workflow/storage/logs/laravel-$(date +%F).log   # error aplikasi
sudo tail -f /var/log/nginx/error.log                                # error web server
df -h /            # disk; waspada bila > 80%
```

Grafik CPU/RAM ada di halaman Droplet; peringatan Uptime (§2) masuk ke email kalau `/up` mati.

---

## 14. Pindah ke database resmi (1 November)

> **Jangan** menjalankan ulang `inventory:migrate-master-menu` ke database resmi: perintah itu
> mengambil resep dan bahan dari `app.db` awal Oktober dan membuang pemetaan sebulan. Data master
> dibawa dengan `inventory:carry-master-data` (§14.4), yang memetakan ulang ID bahan karena tabel
> `inventory_items` dipakai bersama oleh v2 dan v3.1.

### 14.1 Minggu terakhir Oktober

- [ ] Angka §13.3 mencapai target, atau sisanya disetujui Owner.
- [ ] Data `[LATIHAN]` dihapus dari Item Inventaris, Resep & Menu, Master Supplier.
- [ ] Daftar akun dan peran final, dan **tidak ada dua akun bernama sama** di Master User (v2
      maupun latihan). Sebagian besar akun tidak punya email, jadi akun latihan dicocokkan lewat
      nama; nama kembar membuat perintah berhenti (§14.4).
- [ ] Gudang menjadwalkan hitung fisik bahan utama pada 1 November pagi (§14.3 langkah 10).
- [ ] Accounting tahu: transaksi bertanggal Oktober yang baru diinput setelah pindah (Sales
      Actual susulan, pengeluaran susulan) diinput di v3.1, dan tutup buku Oktober dilakukan di v3.1.

### 14.2 Gladi pindah (±27 Oktober)

Urutan §14.3 langkah 5–6 dijalankan terhadap `po_workflow_staging`, dengan dump v2 hari itu:

```bash
mysql -e "DROP DATABASE po_workflow_staging; CREATE DATABASE po_workflow_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
zcat ~/cutover/v2-GLADI.sql.gz | mysql po_workflow_staging
php artisan migrate --database=mysql_staging --force
php artisan inventory:carry-master-data --from-database=po_workflow_latihan --database=mysql_staging --dry-run
php artisan inventory:carry-master-data --from-database=po_workflow_latihan --database=mysql_staging
php artisan inventory:validate-migration --database=mysql_staging
```

Lolos bila kolom *Dibawa* sama dengan *Di latihan* untuk setiap kelompok (kecuali Pengguna,
Peran, Kategori, dan Menu jual, yang hanya menghitung yang baru atau berubah), peringatan di
bawah tabel sudah dibaca dan diterima Owner, dan `validate-migration` berakhir dengan 0 error.
Catat lama prosesnya. Di uji data lokal (332 bahan, 507 resep, 11.933 baris resep) prosesnya
3 detik.

Gladi ini boleh diulang lebih awal (mis. 19 Okt) dengan dump v2 hari itu. Peringatan seperti
kategori induk yang tidak ada di v2 atau nama akun kembar lebih murah dibereskan di tengah bulan
daripada di malam pindah.

### 14.3 Hari pindah (31 Oktober malam atau 1 November pagi)

> **Login sebagai:** `deploy` · sesi **3sone-deploy**, kecuali langkah 3 dan 11 (server **lama**)
> serta langkah 4 (laptop klien).

1. **Umumkan**: input terakhir di v2 jam X. Setelah itu semua input di v3.1.
2. **Tutup v3.1 latihan**: `cd /var/www/po-workflow && php artisan down --retry=60`.
3. **Dump akhir v2** (§8.1, server lama) dan bawa ke Droplet. Simpan juga salinannya di laptop
   terenkripsi atau Spaces: ini arsip v2 terakhir.
4. **Bekukan Master Menu Revamp**: ganti nama foldernya di laptop klien jadi
   `Master Menu Revamp.FROZEN` (jangan dihapus).
5. **Buat database resmi** dan arahkan aplikasi ke sana:

   ```bash
   ~/bin/backup-db.sh                                # backup terakhir database latihan
   mysql -e "DROP DATABASE po_workflow; CREATE DATABASE po_workflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   zcat ~/cutover/v2-AKHIR-OKTOBER.sql.gz | mysql po_workflow
   sed -i 's/^DB_DATABASE=.*/DB_DATABASE=po_workflow/' .env
   php artisan config:clear
   php artisan migrate --force
   php artisan access:sync
   ```

6. **Bawa data master** dari database latihan:

   ```bash
   php artisan inventory:carry-master-data --from-database=po_workflow_latihan --dry-run
   php artisan inventory:carry-master-data --from-database=po_workflow_latihan
   php artisan inventory:validate-migration
   ```

7. `php artisan optimize && sudo systemctl reload php8.3-fpm && php artisan up`
8. **Pengaturan Inventory** (Owner):
   - *Barang Sisa dihitung sebagai persediaan mulai* → **1 November 2026**;
   - *Harga beli memperbarui harga master bahan* → **nyalakan** lagi.
9. **Smoke test** §11.2 baris 1–6 dan 8–11, ditambah: Pencocokan Menu dan Konversi Satuan
   menunjukkan angka yang sama dengan database latihan sebelum pindah; satu resep yang dikenal
   menampilkan HPP yang sama.
10. **Saldo awal stok**: gudang menghitung fisik bahan utama dan mengisinya di Inventory ›
    Opname Bahan. Hitungan pertama tiap bahan menjadi saldo awal Kartu Stok. Bahan lain mendapat
    saldo awal dari kolom Stok Awal Form Kebutuhan pertamanya.
11. **Bekukan v2** di server lama: `php artisan down --secret=TOKEN_ACAK`. Semua orang melihat
    halaman perawatan; Owner masih bisa melihat lewat `https://ALAMAT_V2/TOKEN_ACAK`. Biarkan
    server lama hidup 3 bulan, lalu matikan setelah dump akhir terbukti bisa dipulihkan.
12. **Umumkan**: v3.1 resmi; password = password v2 masing-masing (akun yang dibuat di v3.1
    memakai password v3.1-nya).
13. Database `po_workflow_latihan` disimpan 3 bulan sebagai arsip, lalu dihapus. Hapus dump lama
    dan `app.db` di `~/cutover`.

### 14.4 Cara kerja `inventory:carry-master-data`

```bash
php artisan inventory:carry-master-data --from-database=po_workflow_latihan [--database=mysql_staging] [--dry-run] [--force]
```

Membaca database latihan (server dan akun MySQL sama, nama database lain; hanya dibaca) dan
menulis ke database tujuan yang baru dimigrasi dari dump v2. Kodenya di
`app/Services/MasterMenu/MasterDataCarryOver.php`, tesnya `tests/Feature/MasterDataCarryOverTest.php`.

| Data | Cara membawa |
|---|---|
| Bahan (`inventory_items` berinduk kategori) | disisipkan dengan **ID baru**; semua rujukan ke bahan dipetakan ulang. Induk kategori harus ada di v2, kalau tidak perintah berhenti dan menyebut namanya |
| Kategori v2 (item tanpa induk) | dicocokkan dengan baris v2; batas stok minimum dari latihan dipakai. Item tanpa induk yang hanya ada di latihan dibawa bila dipakai resep/konversi/supplier, dilewati bila tidak |
| Histori harga | ikut bahan, kecuali yang bersumber dari Form Kebutuhan latihan |
| Resep, baris resep, Pekerjaan Menu, Bahan Belum Cocok, Konversi Satuan (termasuk aturan umum), Pelaksana | disalin; ID resep tetap, tautan bahan dipetakan ulang |
| Supplier | dicocokkan dengan nama (migrasi sudah membuat supplier dari pembelian v2) lalu dilengkapi dari latihan; sisanya ditambah |
| Riwayat produksi Master Menu (arsip) | dibawa; SPK Produksi yang dibuat saat latihan tidak |
| Pengaturan Inventory | menimpa nilai bawaan |
| Menu jual | hanya tautan resep, *tanpa resep*, foto, tampil di website, dan SKU. Nama, harga, dan status aktif tetap dari v2. SKU yang di v2 sudah dipakai menu lain tidak dipasang (dilaporkan). Menu yang hanya ada di latihan dilaporkan, tidak dibuat |
| Pengguna | dicocokkan: ID + nama sama → email bila terisi → nama bila hanya satu akun cocok. Akun v2 tidak diubah (password v2 berlaku); akun yang hanya ada di latihan dibuat dengan password-nya; peran hanya ditambah. Nama kembar = perintah berhenti |
| Transaksi | tidak dibawa |

Baris yang sama di kedua database dikenali dari ID + (waktu dibuat atau nama), karena keduanya
berasal dari dump v2 yang sama.

Pengaman: menolak bila tujuan sudah berisi bahan atau resep (tidak bisa jalan dua kali), menolak
bila skema kedua database berbeda atau sumber = tujuan, seluruh penulisan dalam satu transaksi
(gagal = tidak ada yang tersimpan), dan `--dry-run` menampilkan ringkasan tanpa menulis.

Sudah diuji terhadap salinan data lokal sungguhan di MySQL, dengan item v2 yang sengaja diberi ID
bentrok: HPP ke-507 resep identik di sumber dan tujuan (total biaya bahan Rp 40.767.391,11 di
keduanya), dan tidak ada baris resep yang menunjuk item v2.

---

## 15. Kalau ada masalah

| Situasi | Tindakan |
|---|---|
| Rilis baru (§13.4) error, **belum** ada migrasi database | `~/bin/deploy.sh v3.1.0` (tag terakhir yang sehat) |
| Rilis baru error **dan** sudah menjalankan migrasi | `php artisan down`, pulihkan backup yang dibuat skrip deploy sebelum rilis (`zcat ~/backups/NAMA-DB-JAM-RILIS.sql.gz \| mysql NAMA-DB`), lalu `~/bin/deploy.sh v3.1.0` |
| Hari pindah gagal di langkah 5–9 (sebelum ada transaksi November) | kembalikan `DB_DATABASE=po_workflow_latihan`, `php artisan optimize`, `php artisan up`; buka lagi v2 (`php artisan up` di server lama). Tim kembali bekerja di v2, perbaiki masalahnya, jadwalkan ulang |
| Masalah ditemukan setelah hari pertama November | jangan kembali ke v2 (transaksi November akan hilang); perbaiki di v3.1 |
| Droplet rusak / terhapus | buat Droplet dari *Backups* DigitalOcean, pasang Reserved IP yang sama, pulihkan dump terbaru |

Mengembalikan kode ke tag lama **tidak** mengembalikan struktur database — karena itu skrip deploy
selalu membuat backup sebelum migrasi.

---

## 16. Rahasia dan rotasinya

| Rahasia | Disimpan di | Kalau bocor / ada orang yang keluar dari tim |
|---|---|---|
| Password DB `po_workflow` | `.env` (600) + `~/.my.cnf` (600) + password manager | `ALTER USER 'po_workflow'@'localhost' IDENTIFIED BY '...'`, perbarui kedua berkas, `php artisan optimize`. Dampak: tidak ada, asal dilakukan berurutan |
| `APP_KEY` | `.env` | `php artisan key:generate` + `php artisan optimize`. Dampak: **semua orang ter-logout** sekali; data tidak terpengaruh |
| Deploy key GitHub | `~/.ssh/github_deploy` | hapus di GitHub › Deploy keys, buat baru (§6). Dampak: tidak ada |
| SSH key laptop | laptop | hapus barisnya dari `/home/deploy/.ssh/authorized_keys` |
| Password user `deploy` | password manager | `passwd` |
| Akun DigitalOcean | password manager + **aktifkan 2FA** | ganti password, cabut sesi |

Aturan: rahasia tidak pernah masuk git, chat, screenshot, atau grup WhatsApp. Kalau sempat
tertempel di tempat yang salah, anggap sudah bocor dan rotasi — jangan ditimbang-timbang.

Yang belum tercakup dan masuk daftar kerja berikutnya: *Content-Security-Policy* dengan nonce
(perlu diuji di browser terhadap Livewire/Alpine sebelum dinyalakan) dan uji beban di Droplet
(lihat [STRESS-TEST.md](STRESS-TEST.md)) sebelum pindah 1 November.

---

## 17. Checklist

| # | Langkah | Cara memastikan | Jejak (tanggal, jam, oleh) |
|---|---|---|---|
| 1 | Kode di `main`, tag `v3.1.0`, tes hijau | tag di GitHub | |
| 2 | Droplet SGP1, Reserved IP, Backups, Monitoring | halaman Droplet | |
| 3 | User deploy (sudo), root & password SSH mati, ufw, fail2ban | sesi `3sone-root` ditolak, `3sone-deploy` masuk, `sudo whoami` → root | |
| 4 | PHP 8.3 + ekstensi, Node 22, nginx, MySQL | §4 ✅ | |
| 5 | Tiga database + `~/.my.cnf` | `mysql -e "SHOW DATABASES"` | |
| 6 | Kode ter-clone, aset ter-build | `public/build/manifest.json` | |
| 7 | `.env` (600, DEBUG false, `DB_DATABASE=po_workflow_latihan`) | §7 ✅ | |
| 8 | Gladi: dump v2 + Master Menu ke latihan, 0 error | keluaran `validate-migration` | |
| 9 | nginx + DNS + HTTPS + HSTS | `curl -sI` §10 ✅ | |
| 10 | Cron jadwal + backup harian (dua database) | `schedule:list`, `backup.log` | |
| 11 | Smoke test gladi | §11.2 semua ✅ | |
| 12 | Owner setuju aturan Oktober | §13.1–13.2 dibacakan & disetujui | |
| 13 | Hari-0: data terbaru, harga-beli→master **mati**, akun & peran | §12 | |
| 14 | Progres pemetaan 12 / 19 / 26 Okt | tabel §13.3 | |
| 15 | Nama akun unik di Master User (v2 dan latihan) | tidak ada nama kembar | |
| 16 | Gladi pindah di staging | §14.2 lolos, lama proses dicatat | |
| 17 | Hari pindah: database resmi, data master terbawa, pengaturan November | §14.3 langkah 5–9 | |
| 18 | Saldo awal stok lewat Opname Bahan | Kartu Stok bahan utama berisi saldo awal | |
| 19 | v2 dan Master Menu Revamp dibekukan | halaman perawatan v2; folder `.FROZEN` | |
