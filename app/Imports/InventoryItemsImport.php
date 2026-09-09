<?php

namespace App\Imports;

use App\Models\InventoryItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import item inventaris dari format yang sama dengan hasil export.
 *
 * Baris ber-id memperbarui item yang ada, baris tanpa id dicocokkan berdasarkan
 * nama; yang tetap tidak ketemu dibuat sebagai item baru. Kategori tidak dikenal
 * dan baris tanpa nama ditolak dan dilaporkan, bukan diam-diam dilewati --
 * import yang menelan kesalahan meninggalkan master data yang salah tanpa jejak.
 *
 * Kolom yang tidak ada di berkas tidak disentuh sama sekali. Tanpa aturan itu,
 * berkas lama yang belum punya kolom harga akan mengosongkan harga seluruh
 * bahan begitu diunggah -- dan HPP seluruh menu ikut menjadi nol.
 */
class InventoryItemsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    /** Kolom berkas -> kolom item, untuk kolom yang boleh diisi sebagian. */
    protected const OPTIONAL_COLUMNS = [
        'kelompok_bahan' => 'ingredient_group',
        'isi_kemasan' => 'pack_qty',
        'harga_kemasan' => 'pack_price',
        'harga_satuan' => 'unit_price',
        'nilai_stok_minimum' => 'minimum_stock_value',
        'keterangan' => 'description',
        'induk_id' => 'parent_id',
    ];

    protected int $created = 0;

    protected int $updated = 0;

    /** @var array<int, string> */
    protected array $errors = [];

    public function collection(Collection $rows): void
    {
        $parsed = [];

        foreach ($rows as $index => $row) {
            // +2: baris pertama adalah heading, dan penomoran spreadsheet mulai dari 1.
            $lineNumber = $index + 2;
            $name = trim((string) ($row['nama_item'] ?? ''));

            if ($name === '') {
                $this->errors[] = "Baris {$lineNumber}: nama item kosong.";

                continue;
            }

            $category = $this->normalizeCategory($row['kategori'] ?? null);

            if ($category === null) {
                $this->errors[] = "Baris {$lineNumber}: kategori '".($row['kategori'] ?? '')."' tidak dikenal.";

                continue;
            }

            $data = [
                'name' => $name,
                'unit' => trim((string) ($row['satuan'] ?? '')) ?: 'unit',
                'category' => $category,
            ];

            if ($row->has('aktif')) {
                $data['is_active'] = $this->normalizeBoolean($row['aktif']);
            }

            foreach (self::OPTIONAL_COLUMNS as $column => $attribute) {
                if (! $row->has($column)) {
                    continue;
                }

                $data[$attribute] = match ($attribute) {
                    'parent_id' => blank($row[$column]) ? null : (int) $row[$column],
                    'description', 'ingredient_group' => blank($row[$column]) ? null : trim((string) $row[$column]),
                    default => $this->normalizeMoney($row[$column]),
                };
            }

            $parsed[] = ['id' => $row['id'] ?? null] + $data;
        }

        if ($this->errors !== []) {
            // Seluruh berkas ditolak: sebagian tersimpan lebih sulit dibereskan
            // daripada tidak tersimpan sama sekali.
            return;
        }

        DB::transaction(function () use ($parsed) {
            foreach ($parsed as $data) {
                $id = $data['id'];
                unset($data['id']);

                $item = $this->resolveItem($id, $data['name']);

                if ($item) {
                    $item->update($data);
                    $this->updated++;

                    continue;
                }

                // Item baru bergabung ke bucket kategorinya, bukan berdiri
                // sendiri: item tanpa induk diperlakukan sebagai bucket, dan
                // bucket bayangan akan mengacaukan pengelompokan Laba Rugi.
                $data['parent_id'] ??= $this->bucketIdFor($data['category']);

                InventoryItem::query()->create($data);
                $this->created++;
            }
        });
    }

    protected function resolveItem(mixed $id, string $name): ?InventoryItem
    {
        if (filled($id)) {
            return InventoryItem::query()->find($id);
        }

        return InventoryItem::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    /** Bucket induk untuk sebuah kategori. */
    protected function bucketIdFor(string $category): ?int
    {
        return InventoryItem::query()
            ->buckets()
            ->where('category', $category)
            ->orderBy('id')
            ->value('id');
    }

    /** Menerima kunci kategori maupun labelnya, supaya file hasil edit manusia tetap masuk. */
    protected function normalizeCategory(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return InventoryItem::CATEGORY_RAW_MATERIAL;
        }

        foreach (InventoryItem::categoryOptions() as $key => $label) {
            if ($value === mb_strtolower($key) || $value === mb_strtolower($label)) {
                return $key;
            }
        }

        return null;
    }

    protected function normalizeMoney(mixed $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        // Angka bisa datang berformat Indonesia ("1.500.000,50") dari file yang
        // diedit manusia di Excel.
        $clean = str_replace(['.', ' '], '', (string) $value);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? round((float) $clean, 4) : null;
    }

    protected function normalizeBoolean(mixed $value): bool
    {
        $value = mb_strtolower(trim((string) $value));

        return ! in_array($value, ['tidak', 'no', '0', 'false', 'nonaktif'], true);
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
