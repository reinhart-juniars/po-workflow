<?php

namespace App\Imports;

use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\StockOpname;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Import Stock Opname / Saldo Awal dari format hasil export (id, item_id,
 * nama_item, tanggal, nilai, catatan).
 *
 * Baris ber-id memperbarui catatan yang ada; tanpa id dicocokkan berdasarkan
 * item + tanggal, dan bila belum ada dibuat baru. Item dicari lewat item_id,
 * lalu nama_item. Nilai disimpan dengan konvensi modul: qty 1, unit_cost =
 * nilai. Berkas dengan satu saja baris bermasalah ditolak seluruhnya --
 * opname yang tersimpan separuh lebih sulit dibereskan daripada yang tidak
 * tersimpan sama sekali.
 */
class ValueEntriesImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    protected int $created = 0;

    protected int $updated = 0;

    /** @var array<int, string> */
    protected array $errors = [];

    /**
     * @param  class-string<StockOpname|InventoryOpening>  $model
     */
    public function __construct(
        protected string $model,
        protected string $dateColumn,
        protected ?int $userId = null,
    ) {}

    public static function opname(?int $userId = null): self
    {
        return new self(StockOpname::class, 'opname_date', $userId);
    }

    public static function opening(?int $userId = null): self
    {
        return new self(InventoryOpening::class, 'balance_date', $userId);
    }

    public function collection(Collection $rows): void
    {
        $parsed = [];

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2;

            $item = $this->resolveItem($row['item_id'] ?? null, $row['nama_item'] ?? null);

            // Baris ber-id boleh mengosongkan kolom item: itemnya sudah tercatat.
            if (! $item && filled($row['id'] ?? null)) {
                $item = $this->model::query()->find((int) $row['id'])?->item;
            }

            if (! $item) {
                $this->errors[] = "Baris {$lineNumber}: item '".trim((string) ($row['nama_item'] ?? $row['item_id'] ?? ''))."' tidak ditemukan.";

                continue;
            }

            $date = $this->parseDate($row['tanggal'] ?? null);

            if ($date === null) {
                $this->errors[] = "Baris {$lineNumber}: tanggal '".($row['tanggal'] ?? '')."' tidak valid (pakai YYYY-MM-DD).";

                continue;
            }

            if (blank($row['nilai'] ?? null) || ! is_numeric($this->cleanNumber($row['nilai']))) {
                $this->errors[] = "Baris {$lineNumber}: nilai '".($row['nilai'] ?? '')."' bukan angka.";

                continue;
            }

            $value = (float) $this->cleanNumber($row['nilai']);

            if ($value < 0) {
                $this->errors[] = "Baris {$lineNumber}: nilai tidak boleh negatif.";

                continue;
            }

            $parsed[] = [
                'id' => filled($row['id'] ?? null) ? (int) $row['id'] : null,
                'inventory_item_id' => $item->id,
                'date' => $date,
                'value' => $value,
                'notes' => blank($row['catatan'] ?? null) ? null : trim((string) $row['catatan']),
            ];
        }

        if ($this->errors !== []) {
            return;
        }

        DB::transaction(function () use ($parsed) {
            foreach ($parsed as $data) {
                $entry = $data['id'] !== null
                    ? $this->model::query()->find($data['id'])
                    : $this->model::query()
                        ->where('inventory_item_id', $data['inventory_item_id'])
                        ->whereDate($this->dateColumn, $data['date'])
                        ->first();

                $attributes = [
                    'inventory_item_id' => $data['inventory_item_id'],
                    $this->dateColumn => $data['date'],
                    'qty' => 1,
                    'unit_cost' => $data['value'],
                    'total_value' => $data['value'],
                    'notes' => $data['notes'],
                    'updated_by' => $this->userId,
                ];

                if ($entry) {
                    $entry->update($attributes);
                    $this->updated++;

                    continue;
                }

                $this->model::query()->create($attributes + ['created_by' => $this->userId]);
                $this->created++;
            }
        });
    }

    protected function resolveItem(mixed $id, mixed $name): ?InventoryItem
    {
        if (filled($id)) {
            return InventoryItem::query()->find((int) $id);
        }

        $name = trim((string) $name);

        return $name === '' ? null : InventoryItem::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    protected function parseDate(mixed $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        try {
            // Excel menyimpan tanggal sebagai angka serial bila selnya bertipe tanggal.
            if (is_numeric($raw) && (float) $raw > 10000) {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            }

            return Carbon::parse((string) $raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Sel angka Excel datang sebagai int/float dan dipakai apa adanya; teks
     * berformat rupiah ("Rp 1.250.000,50") dibersihkan dari pemisah ribuan.
     */
    protected function cleanNumber(mixed $raw): string
    {
        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }

        $text = trim((string) $raw);

        if (preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return $text;
        }

        return str_replace(['Rp', ' ', '.', ','], ['', '', '', '.'], $text) ?: '0';
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
