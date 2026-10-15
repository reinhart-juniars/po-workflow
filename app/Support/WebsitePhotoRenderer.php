<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Bagian C: foto menu ukuran website -- 560x420 WebP, sama dengan thumbnail
 * katalog yang sudah ada di www.w3scatering.com.
 *
 * Sumbernya foto katalog (JPEG hasil MenuPhotoProcessor, orientasi EXIF sudah
 * diterapkan). Foto dipotong tengah ke rasio 4:3 lalu diperkecil (cover), jadi
 * foto potret atau persegi tidak tampil gepeng di kartu menu.
 */
class WebsitePhotoRenderer
{
    public const WIDTH = 560;

    public const HEIGHT = 420;

    public const QUALITY = 80;

    public function render(string $binary): string
    {
        $src = @imagecreatefromstring($binary);

        if ($src === false) {
            throw new InvalidArgumentException('Foto menu tidak bisa dibaca.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Ambil potongan 4:3 terbesar di tengah foto.
        $targetRatio = self::WIDTH / self::HEIGHT;
        if ($srcW / $srcH > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
        }
        $cropX = intdiv($srcW - $cropW, 2);
        $cropY = intdiv($srcH - $cropH, 2);

        $dst = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, self::WIDTH, self::HEIGHT, $cropW, $cropH);
        imagedestroy($src);

        ob_start();
        imagewebp($dst, null, self::QUALITY);
        $data = (string) ob_get_clean();
        imagedestroy($dst);

        return $data;
    }
}
