<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

/**
 * Membangun panduan pengguna (PDF) dari docs/panduan/panduan-{nama}.html.
 *
 * Panduannya ditulis sebagai HTML sederhana supaya bisa disunting siapa pun
 * dan dirender dengan dompdf yang sudah dipakai laporan; tanpa alat tambahan.
 * Bawaannya Panduan 3S ONE; panduan lain (mis. inventory-master-menu) dipilih
 * lewat namanya dan ditulis ke docs/PANDUAN-{NAMA}.pdf.
 */
class BuildManualCommand extends Command
{
    protected $signature = 'panduan:pdf
        {panduan=3s-one : Nama panduan, yaitu berkas docs/panduan/panduan-{nama}.html}
        {--output= : Lokasi PDF (bawaan docs/PANDUAN-{NAMA}.pdf)}';

    protected $description = 'Bangun panduan pengguna (PDF) dari docs/panduan/panduan-{nama}.html';

    public function handle(): int
    {
        // Panduan 3S ONE (120+ halaman bergambar, disematkan sebagai base64)
        // melewati batas memori CLI bawaan 128 MB. Dinaikkan di awal, sebelum
        // gambar dibaca; tidak pernah diturunkan bila php.ini memberi lebih.
        $this->raiseMemoryLimit(1024);

        $name = (string) $this->argument('panduan');

        // Nama dipakai sebagai bagian path: huruf kecil, angka, dan tanda hubung saja.
        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            $this->error('Nama panduan tidak valid: '.$name.' (pakai huruf kecil, angka, dan tanda hubung).');

            return self::FAILURE;
        }

        $source = base_path('docs/panduan/panduan-'.$name.'.html');
        $output = $this->option('output') ?: base_path('docs/PANDUAN-'.strtoupper($name).'.pdf');

        if (! is_file($source)) {
            $this->error('Sumber panduan tidak ditemukan: '.$source);

            return self::FAILURE;
        }

        // Gambar (img/*.jpg) disematkan sebagai data URI supaya PDF-nya
        // berdiri sendiri dan dompdf tidak perlu izin membaca berkas lokal.
        $html = preg_replace_callback(
            '/src="(img\/[^"]+)"/',
            function (array $m) {
                $file = base_path('docs/panduan/'.$m[1]);

                if (! is_file($file)) {
                    $this->warn('Gambar tidak ditemukan: '.$m[1]);

                    return $m[0];
                }

                $mime = str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg';

                return 'src="data:'.$mime.';base64,'.base64_encode(file_get_contents($file)).'"';
            },
            file_get_contents($source),
        );

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        file_put_contents($output, $pdf->output());

        $this->info('Panduan ditulis ke '.$output.' ('.number_format(filesize($output) / 1024).' KB)');

        return self::SUCCESS;
    }

    private function raiseMemoryLimit(int $megabytes): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        // "128M" / "1G" / "134217728" -> byte.
        $bytes = (int) $current * match (strtoupper(substr($current, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < $megabytes * 1024 ** 2) {
            ini_set('memory_limit', $megabytes.'M');
        }
    }
}
