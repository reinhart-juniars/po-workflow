<?php

namespace App\Exports;

use App\Models\Customer;
use App\Services\CustomerInsightService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Daftar customer untuk follow up tim marketing: kontak, link WhatsApp,
 * status CRM, kapan terakhir order, dan omzetnya. Isi mengikuti tab status
 * & pencarian yang sedang dibuka di Admin › Customer.
 *
 * Angka dari PO completed, sama dengan halaman (CustomerInsightService).
 */
class CustomersExport extends DefaultValueBinder implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStyles
{
    /** Kolom Telepon: selalu teks supaya 0 di depan / nomor 62xxx tidak jadi angka 6,28E+11. */
    private const PHONE_COLUMN = 'B';

    /**
     * @param  Collection<int, Customer>  $customers  sudah membawa agregat withListAggregates + withLifetimeAggregates
     */
    public function __construct(
        protected Collection $customers,
        protected CustomerInsightService $insights,
        protected ?CarbonImmutable $today = null,
    ) {
        $this->today ??= CarbonImmutable::today();
    }

    public function collection(): Collection
    {
        return $this->customers;
    }

    public function headings(): array
    {
        $recent = CustomerInsightService::RECENT_DAYS;

        return [
            'Nama', 'Telepon', 'Link WhatsApp', 'Alamat', 'Area', 'Lapak', 'Aktif', 'Status',
            'Order Pertama', 'Order Terakhir', 'Hari Sejak Order Terakhir',
            'Total PO', 'Total Omzet', "PO {$recent} Hari", "Omzet {$recent} Hari",
        ];
    }

    /** @param  Customer  $customer */
    public function map($customer): array
    {
        $first = $customer->first_order_date ? CarbonImmutable::parse($customer->first_order_date) : null;
        $last = $customer->last_order_date ? CarbonImmutable::parse($customer->last_order_date) : null;
        $status = $this->insights->status($customer->first_order_date, $customer->last_order_date, $this->today);

        return [
            $customer->name,
            $customer->phone ?: null,
            self::whatsappLink($customer->phone),
            $customer->address ?: null,
            $customer->area?->name,
            $customer->is_lapak ? 'Ya' : 'Tidak',
            $customer->active ? 'Ya' : 'Tidak',
            CustomerInsightService::statusOptions()[$status],
            $first ? Date::dateTimeToExcel($first) : null,
            $last ? Date::dateTimeToExcel($last) : null,
            $last ? (int) $last->diffInDays($this->today) : null,
            (int) $customer->total_po_count,
            (float) $customer->total_revenue,
            (int) $customer->recent_po_count,
            (float) $customer->recent_revenue,
        ];
    }

    /**
     * Link wa.me dari nomor Indonesia: 0812… / 812… / +62 812… → 62812….
     * Kolom telepon kadang berisi dua nomor ("0812… / 0813…"): pakai yang pertama.
     */
    public static function whatsappLink(?string $phone): ?string
    {
        $first = preg_split('#[/,;]|\bdan\b|\batau\b#i', (string) $phone)[0] ?? '';
        $digits = preg_replace('/\D+/', '', $first);

        $number = match (true) {
            str_starts_with($digits, '62') => $digits,
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            str_starts_with($digits, '8') => '62'.$digits,
            default => null,
        };

        // Nomor HP Indonesia: 62 + 9..13 digit. Telepon kantor/isian asal tidak dibuatkan link.
        if ($number === null || strlen($number) < 11 || strlen($number) > 15) {
            return null;
        }

        return 'https://wa.me/'.$number;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getColumn() === self::PHONE_COLUMN && $cell->getRow() > 1 && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return [
            'I' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'J' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'K' => NumberFormat::FORMAT_NUMBER,
            'L' => NumberFormat::FORMAT_NUMBER,
            'M' => '#,##0',
            'N' => NumberFormat::FORMAT_NUMBER,
            'O' => '#,##0',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    public function registerEvents(): array
    {
        return [
            // Filter & kepala kolom beku: tim marketing bisa langsung urutkan
            // "Hari Sejak Order Terakhir" atau saring per Status / Area.
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                $sheet->freezePane('B2');
            },
        ];
    }
}
