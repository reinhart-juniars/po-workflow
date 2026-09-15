<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

/**
 * Membangun Panduan Pengguna (PDF) dari docs/panduan/panduan-3s-one.html.
 *
 * Panduannya ditulis sebagai HTML sederhana supaya bisa disunting siapa pun
 * dan dirender dengan dompdf yang sudah dipakai laporan; tanpa alat tambahan.
 */
class BuildManualCommand extends Command
{
    protected $signature = 'panduan:pdf {--output= : Lokasi PDF (bawaan docs/PANDUAN-3S-ONE.pdf)}';

    protected $description = 'Bangun Panduan Pengguna 3S ONE (PDF) dari docs/panduan/panduan-3s-one.html';

    public function handle(): int
    {
        $source = base_path('docs/panduan/panduan-3s-one.html');
        $output = $this->option('output') ?: base_path('docs/PANDUAN-3S-ONE.pdf');

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
}
