<?php

namespace App\Exports;

use App\Services\WebsiteMenuMapper;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/** Lembar "Cara Isi": petunjuk untuk marketing, plus ringkasan status. */
class WebsiteMappingGuideSheet implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    /** @param  list<array<string, mixed>>  $rows */
    public function __construct(private readonly array $rows) {}

    public function title(): string
    {
        return 'Cara Isi';
    }

    public function array(): array
    {
        $counts = collect($this->rows)->countBy('status');

        return [
            ['PEMETAAN MENU WEBSITE → MASTER MENU 3S ONE'],
            ['Dibuat '.now()->translatedFormat('d F Y H:i').' dari halaman website yang sekarang ('.count($this->rows).' hidangan).'],
            [],
            ['Untuk apa'],
            ['Sesudah ini menu website dibangun dari 3S ONE (SKU, foto, kategori), tidak diketik manual lagi. Setiap hidangan di website harus diwakili SATU produk Master Menu.'],
            [],
            ['Cara mengisi lembar "Pemetaan"'],
            ['1. Kolom kuning PILIH ID PRODUK: ID produk yang mewakili hidangan itu. Untuk status Cocok / Pilih varian sudah terisi usulan — cukup diperiksa.'],
            ['2. Status Perlu dipilih: lihat USULAN / KANDIDAT LAIN, atau cari di lembar "Daftar Produk", lalu ketik ID-nya. Kandidat bisa saja keliru — pilih yang memang hidangan yang sama.'],
            ['3. Kolom kuning SKU / NAMA DI WEBSITE: nama yang dibaca pelanggan. Terisi nama yang sekarang di website; ubah bila perlu. Tidak boleh sama dengan SKU produk lain.'],
            ['4. Hidangan yang tidak dijual lagi: kosongkan PILIH ID PRODUK. Hidangan itu tidak akan muncul di website baru.'],
            ['5. Status Belum ada di Master Menu: tambahkan dulu produknya di Admin › Master Menu, lalu tulis ID-nya.'],
            ['6. Baca kolom PERHATIAN: misalnya produk bertanda Warung Wuenak Poll yang dipasang di halaman 3S. Baris itu tetap disimpan, tetapi belum tampil di website sampai dibetulkan lewat kotak peringatan di aplikasi Marketing (pilih kategori baru, atau minta tim menu mengubah tanda 3S).'],
            ['7. Jangan menambah, menghapus, atau memindah kolom. Baris boleh disaring dan diurutkan.'],
            [],
            ['Sesudah diisi'],
            ['Kirim berkas ini ke tim IT. Saat diimpor, produk yang dipilih diberi SKU, dicentang Tampil di website, diberi kategori sesuai kelompoknya, dan — bila belum punya foto — memakai foto dari website lama.'],
            [],
            ['Ringkasan status'],
            ...collect(WebsiteMenuMapper::STATUS_LABELS)->map(fn ($label, $key) => [$label, $counts[$key] ?? 0])->values()->all(),
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 120, 'B' => 10];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                foreach (['A4', 'A7', 'A16', 'A19'] as $cell) {
                    $sheet->getStyle($cell)->getFont()->setBold(true);
                }
                $sheet->getStyle('A1:A30')->getAlignment()->setWrapText(true);
            },
        ];
    }
}
