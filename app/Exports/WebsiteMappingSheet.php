<?php

namespace App\Exports;

use App\Services\WebsiteMenuMapper;
use App\Support\WebsiteCatalog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar "Pemetaan": satu baris per hidangan di website. Kolom berwarna kuning
 * (PILIH ID PRODUK, SKU / NAMA DI WEBSITE) yang diisi marketing; kolom lain
 * hanya bacaan. Urutan kolom dipakai importnya, jadi jangan disisipi kolom.
 */
class WebsiteMappingSheet implements FromArray, WithColumnWidths, WithEvents, WithStyles, WithTitle
{
    public const TITLE = 'Pemetaan';

    /** Judul kolom; WebsiteMappingImport membaca kolom berdasarkan judul ini. */
    public const HEADINGS = [
        'no' => 'NO',
        'page' => 'HALAMAN',
        'group' => 'KELOMPOK DI WEBSITE',
        'name' => 'NAMA DI WEBSITE',
        'photo' => 'FOTO DI WEBSITE',
        'status' => 'STATUS USULAN',
        'suggested' => 'USULAN PRODUK',
        'others' => 'KANDIDAT LAIN (ID · NAMA · PESANAN)',
        'product_id' => 'PILIH ID PRODUK',
        'sku' => 'SKU / NAMA DI WEBSITE',
        'warning' => 'PERHATIAN',
        'notes' => 'CATATAN',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, array<string, mixed>>  $products
     */
    public function __construct(
        private readonly array $rows,
        private readonly Collection $products,
    ) {}

    public function title(): string
    {
        return self::TITLE;
    }

    public function array(): array
    {
        $skuOwner = $this->products->filter(fn ($p) => filled($p['sku']))
            ->mapWithKeys(fn ($p) => [mb_strtolower($p['sku']) => $p]);
        $brands = WebsiteCatalog::brands();

        $sheet = [array_values(self::HEADINGS)];
        // Website lama memuat beberapa hidangan dua kali dengan ejaan lain
        // ("Ricebowl ..." / "Rice Bowl ..."). Satu produk hanya bisa satu baris,
        // jadi usulan hanya diisikan di kemunculan pertama.
        $firstRowOf = [];

        foreach ($this->rows as $index => $row) {
            $suggested = $row['suggested'];
            $duplicateOf = null;

            if ($suggested) {
                $duplicateOf = $firstRowOf[$suggested['id']] ?? null;
                $firstRowOf[$suggested['id']] ??= $index + 1;
            }
            $others = collect($row['candidates'])
                ->reject(fn ($c) => $suggested && $c['id'] === $suggested['id'])
                ->map(fn ($c) => "{$c['id']} · {$c['name']} · {$c['orders']}×")
                ->implode("\n");

            $warnings = [];
            if ($suggested && $suggested['brand'] !== $row['brand']) {
                $warnings[] = 'Produk bertanda '.$brands[$suggested['brand']]['label'].', halaman '.$brands[$row['brand']]['label']
                    .': belum tampil sampai kategori atau tanda 3S-nya dibetulkan (kotak peringatan Marketing).';
            }
            $owner = $skuOwner->get(mb_strtolower($row['name']));
            if ($owner && (! $suggested || $owner['id'] !== $suggested['id'])) {
                $warnings[] = "SKU \"{$row['name']}\" sudah dipakai produk {$owner['id']} ({$owner['name']}).";
            }
            if ($duplicateOf !== null) {
                $warnings[] = "Hidangan yang sama dengan baris NO {$duplicateOf} (ejaan lain). Satu produk hanya untuk satu baris; ID sengaja dikosongkan.";
            }
            if ($row['status'] === 'belum') {
                $warnings[] = 'Tambahkan dulu di Admin › Master Menu, lalu isi ID-nya, atau kosongkan bila menu ini tidak dijual lagi.';
            }

            $sheet[] = [
                $index + 1,
                $brands[$row['brand']]['label'],
                $row['group'],
                $row['name'],
                $row['photo'],
                WebsiteMenuMapper::STATUS_LABELS[$row['status']],
                $suggested ? "{$suggested['id']} · {$suggested['name']} · {$suggested['orders']}×" : null,
                $others ?: null,
                $duplicateOf === null ? ($suggested['id'] ?? null) : null,
                $row['name'],
                $warnings ? implode("\n", $warnings) : null,
                null,
            ];
        }

        return $sheet;
    }

    public function columnWidths(): array
    {
        return ['A' => 5, 'B' => 20, 'C' => 24, 'D' => 34, 'E' => 26, 'F' => 22, 'G' => 40, 'H' => 48, 'I' => 14, 'J' => 34, 'K' => 44, 'L' => 30];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E2E8F0']]]];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = count($this->rows) + 1;

                $sheet->freezePane('E2');
                $sheet->setAutoFilter("A1:L{$last}");
                $sheet->getStyle("A1:L{$last}")->getAlignment()->setVertical('top')->setWrapText(true);
                // Kolom isian berwarna kuning supaya jelas mana yang diisi.
                $sheet->getStyle("I1:J{$last}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FEF3C7');
                $sheet->getStyle('I1:J1')->getFill()->getStartColor()->setRGB('FCD34D');

                $colors = ['Cocok' => 'DCFCE7', 'Pilih varian' => 'E0F2FE', 'Perlu dipilih' => 'FFEDD5', 'Belum ada di Master Menu' => 'FEE2E2'];
                for ($row = 2; $row <= $last; $row++) {
                    $color = $colors[$sheet->getCell("F{$row}")->getValue()] ?? null;
                    if ($color) {
                        $sheet->getStyle("F{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB($color);
                    }
                }
            },
        ];
    }
}
