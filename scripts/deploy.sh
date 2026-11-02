#!/usr/bin/env bash
# Rilis 3S ONE ke server produksi (DigitalOcean, lihat docs/DEPLOY-DIGITALOCEAN.md §13.4).
#
# Pakai (sebagai user deploy, tanpa sudo):
#   ~/bin/deploy.sh            -> rilis terbaru di main
#   ~/bin/deploy.sh v3.2.1     -> pindah ke tag tertentu (rollback: lihat §15 soal database)
#
# Pasang / perbarui salinan di server (sekali, dan setiap kali berkas ini berubah):
#   install -m 700 /var/www/po-workflow/scripts/deploy.sh ~/bin/deploy.sh
#
# Sengaja dijalankan dari ~/bin, bukan dari repo: git pull di tengah jalan menimpa
# berkas ini, sedangkan bash membaca skrip sedikit demi sedikit saat berjalan.
set -euo pipefail
umask 002

REF="${1:-main}"
APP=/var/www/po-workflow
PHP_FPM=php8.3-fpm

cd "$APP"

# ---------- Pemeriksaan awal: gagal di sini, aplikasi tetap melayani ----------
git fetch --tags --prune origin

if [ "$REF" = "main" ]; then
  TARGET=origin/main
else
  TARGET="$REF"
fi

if ! git rev-parse --verify --quiet "$TARGET^{commit}" >/dev/null; then
  echo "Ref '$REF' tidak ditemukan di origin. Tidak ada yang diubah."
  exit 1
fi

# Perubahan lokal di server akan ikut tertimpa atau membuat pull gagal di tengah
# mode perawatan. Kode server harus sama dengan git (tidak boleh disunting di server).
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Ada perubahan lokal di $APP:"
  git status --short --untracked-files=no
  echo "Bereskan dulu (git stash / git checkout -- <berkas>). Tidak ada yang diubah."
  exit 1
fi

OLD=$(git rev-parse --short HEAD)
NEW=$(git rev-parse --short "$TARGET")

if [ "$OLD" = "$NEW" ]; then
  echo "Server sudah di $NEW. Tidak ada yang perlu dirilis."
  exit 0
fi

echo "Rilis $OLD -> $NEW:"
git log --oneline "$OLD..$NEW" | head -20
echo

# ---------- Rilis: dari sini aplikasi dalam mode perawatan ----------
/home/deploy/bin/backup-db.sh

trap 'echo; echo "GAGAL. Aplikasi masih mode perawatan. Perbaiki, lalu: cd $APP && php artisan up"' ERR
php artisan down --retry=30

if [ "$REF" = "main" ]; then
  git checkout main
  git pull --ff-only origin main
else
  git checkout --detach "$REF"
fi

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan access:sync
php artisan optimize
sudo systemctl reload "$PHP_FPM"
php artisan up
trap - ERR

echo
echo "Selesai: $(git describe --tags --always) $(tail -1 version.txt | cut -c1-40)"

# Salinan di ~/bin ketinggalan dari versi di repo?
if [ -f "$APP/scripts/deploy.sh" ] && ! cmp -s "$APP/scripts/deploy.sh" "$0"; then
  echo "Catatan: scripts/deploy.sh di repo berubah. Perbarui salinan ini:"
  echo "  install -m 700 $APP/scripts/deploy.sh ~/bin/deploy.sh"
fi
