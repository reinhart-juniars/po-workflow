<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Kompresi foto menu saat unggah (Bagian B.4) -- memakai GD bawaan PHP,
 * tanpa paket tambahan.
 *
 * Foto dari HP biasanya 2-5 MB dengan resolusi 12-48 MP; untuk katalog cukup
 * sisi terpanjang 1600 px dalam JPEG kualitas 82 (150-300 KB). Dengan 492 menu
 * seluruh katalog tetap di bawah ~150 MB, sehingga tidak perlu menambah disk
 * server. Hasil selalu JPEG: PNG/WEBP diratakan ke latar putih, dan orientasi
 * EXIF (foto potret dari HP) diterapkan dulu karena penyandian ulang membuang
 * metadata EXIF-nya.
 */
class MenuPhotoProcessor
{
    /** Sisi terpanjang hasil, piksel. */
    public const MAX_SIDE = 1600;

    /** Kualitas JPEG hasil (0-100). */
    public const QUALITY = 82;

    /** Batas resolusi sumber (piksel), untuk melindungi memori server saat decode. */
    public const MAX_PIXELS = 40_000_000;

    /**
     * @return array{data: string, width: int, height: int}
     */
    public function process(string $binary): array
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            throw new InvalidArgumentException('Berkas bukan gambar yang bisa dibaca.');
        }

        [$srcW, $srcH] = $info;

        if ($srcW * $srcH > self::MAX_PIXELS) {
            throw new InvalidArgumentException(sprintf('Resolusi foto terlalu besar (%d MP, maksimal %d MP).', (int) round($srcW * $srcH / 1_000_000), self::MAX_PIXELS / 1_000_000));
        }

        // Decode foto besar butuh ~4 byte/piksel (20 MP = 80 MB) + salinan hasil;
        // naikkan batas memori bila masih di bawah 512M. Tidak dikembalikan:
        // menurunkan batas saat buffer belum dilepas justru memicu error, dan
        // request ini selesai begitu foto tersimpan.
        $this->raiseMemoryLimit();

        $src = @imagecreatefromstring($binary);

        if ($src === false) {
            throw new InvalidArgumentException('Berkas bukan gambar yang bisa dibaca.');
        }

        $src = $this->applyExifOrientation($src, $binary, $info['mime'] ?? '');
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Skala turun saja, tidak pernah memperbesar foto kecil.
        $scale = min(1, self::MAX_SIDE / max($srcW, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white); // latar untuk PNG/WEBP transparan
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, self::QUALITY);
        $data = (string) ob_get_clean();
        imagedestroy($dst);

        return ['data' => $data, 'width' => $dstW, 'height' => $dstH];

    }

    protected function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return; // tanpa batas
        }

        $bytes = (int) $current * match (strtolower(substr($current, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        if ($bytes < 512 * 1024 ** 2) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Putar/cerminkan sesuai tag EXIF Orientation (hanya JPEG yang membawanya).
     *
     * @param  \GdImage  $img
     * @return \GdImage
     */
    protected function applyExifOrientation($img, string $binary, string $mime)
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary));
        $orientation = (int) ($exif['Orientation'] ?? 1);

        // Nilai EXIF: 3 = terbalik 180, 6 = perlu putar 90 searah jarum jam,
        // 8 = perlu putar 90 berlawanan; 2/4/5/7 = varian cermin.
        $rotated = match ($orientation) {
            2 => $this->flip($img, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($img, 180, 0),
            4 => $this->flip($img, IMG_FLIP_VERTICAL),
            5 => imagerotate($this->flip($img, IMG_FLIP_VERTICAL), -90, 0),
            6 => imagerotate($img, -90, 0),
            7 => imagerotate($this->flip($img, IMG_FLIP_HORIZONTAL), -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        if ($rotated !== $img && $rotated !== false) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }

    /**
     * @param  \GdImage  $img
     * @return \GdImage
     */
    protected function flip($img, int $mode)
    {
        imageflip($img, $mode);

        return $img;
    }
}
