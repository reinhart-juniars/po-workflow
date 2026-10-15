<?php

namespace App\Imports;

use App\Models\InventoryItem;
use App\Support\NumberParser;
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
 * bahan begitu diunggah -- dan HPP seluruh menu ikut menjadi nol. Sel kategori
 * yang kosong juga tidak mengubah kategori item yang sudah ada; item baru
 * tanpa kategori menjadi Bahan Baku (dan dilaporkan).
 *
 * Baris tanpa nama DAN tanpa id tidak menunjuk item mana pun -- biasanya sisa
 * isian Excel (kategori/aktif yang ikut ditarik ke bawah, baris berformat).
 * Baris itu dilewati dan nomornya dilaporkan, bukan membatalkan seluruh berkas.
 * Baris ber-id tanpa nama tetap ditolak: id-nya menunjuk item, isinya rusak.
 */
class InventoryItemsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    /**
     * Judul kolom lain yang dipakai berkas buatan tim (mis. laporan stok
     * gudang: "NAMA BARANG", "JENIS", "HARGA") -> judul kolom hasil export.
     */
    protected const HEADING_ALIASES = [
        'nama_item' => ['nama_barang', 'nama'],
        'kategori' => ['jenis', 'jenis_item', 'jenis_barang'],
        'harga_satuan' => ['harga'],
    ];

    /** Sebutan kategori di laporan gudang yang tidak sama dengan label sistem. */
    protected const CATEGORY_ALIASES = [
        'peralatan' => InventoryItem::CATEGORY_FIXED_ASSET,
        'aset' => InventoryItem::CATEGORY_FIXED_ASSET,
    ];

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

    /** @var list<int> nomor baris tanpa nama & tanpa id yang dilewati */
    protected array $skipped = [];

    /** @var list<string> item baru yang kategorinya kosong, dibuat sebagai Bahan Baku */
    protected array $defaultedCategory = [];

    public function collection(Collection $rows): void
    {
        $rows = $rows->map(fn (Collection $row) => $this->applyHeadingAliases($row));

        // Tanpa kolom nama sama sekali, setiap baris akan dilaporkan "nama
        // kosong"; satu pesan tentang judul kolom jauh lebih jelas.
        if ($rows->isNotEmpty() && ! $rows->contains(fn (Collection $row) => $row->has('nama_item'))) {
            $this->errors[] = 'Kolom nama_item (atau "Nama Barang") tidak ditemukan di baris judul. Pakai berkas dari Export Data Master, judul kolom di baris pertama.';

            return;
        }

        $parsed = [];

        foreach ($rows as $index => $row) {
            // +2: baris pertama adalah heading, dan penomoran spreadsheet mulai dari 1.
            $lineNumber = $index + 2;
            $name = trim((string) ($row['nama_item'] ?? ''));

            if ($name === '') {
                if (blank($row['id'] ?? null)) {
                    $this->skipped[] = $lineNumber;
                } else {
                    $this->errors[] = "Baris {$lineNumber}: nama item kosong (id {$row['id']}).";
                }

                continue;
            }

            $category = $this->normalizeCategory($row['kategori'] ?? null);

            if ($category === false) {
                $this->errors[] = "Baris {$lineNumber}: kategori '".($row['kategori'] ?? '')."' tidak dikenal.";

                continue;
            }

            $data = [
                'name' => $name,
                'unit' => trim((string) ($row['satuan'] ?? '')) ?: 'unit',
            ];

            // Kategori kosong: item lama tetap kategorinya, item baru Bahan Baku (persist()).
            if ($category !== null) {
                $data['category'] = $category;
            }

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

        $previousSource = InventoryItem::$priceChangeSource;
        InventoryItem::$priceChangeSource = 'import';

        try {
            $this->persist($parsed);
        } finally {
            InventoryItem::$priceChangeSource = $previousSource;
        }
    }

    /** @param  array<int, array<string, mixed>>  $parsed */
    protected function persist(array $parsed): void
    {
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

                if (! isset($data['category'])) {
                    $data['category'] = InventoryItem::CATEGORY_RAW_MATERIAL;
                    $this->defaultedCategory[] = $data['name'];
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

    /**
     * Menerima kunci kategori maupun labelnya, supaya file hasil edit manusia
     * tetap masuk. Null = sel kosong (kategori tidak diubah); false = tidak dikenal.
     */
    protected function normalizeCategory(mixed $value): string|false|null
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        foreach (InventoryItem::categoryOptions() as $key => $label) {
            if ($value === mb_strtolower($key) || $value === mb_strtolower($label)) {
                return $key;
            }
        }

        return self::CATEGORY_ALIASES[$value] ?? false;
    }

    /** Salin kolom berjudul lain ke judul hasil export, bila judul aslinya tidak ada. */
    protected function applyHeadingAliases(Collection $row): Collection
    {
        foreach (self::HEADING_ALIASES as $target => $aliases) {
            if ($row->has($target)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if ($row->has($alias)) {
                    $row[$target] = $row[$alias];

                    break;
                }
            }
        }

        return $row;
    }

    protected function normalizeMoney(mixed $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        // Sel angka dari ekspor dipakai apa adanya; teks berformat Indonesia
        // ("1.500.000,50") dari file yang diedit manusia dibaca NumberParser.
        $number = NumberParser::parse($value);

        return $number === null ? null : round($number, 4);
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

    /** @return list<int> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<string> */
    public function defaultedCategory(): array
    {
        return $this->defaultedCategory;
    }

    /**
     * Catatan untuk pengguna setelah impor berhasil: baris yang dilewati dan
     * item baru yang kategorinya diisi otomatis. Null bila tidak ada.
     */
    public function notes(): ?string
    {
        $notes = [];

        if ($this->skipped !== []) {
            $lines = array_slice($this->skipped, 0, 10);
            $notes[] = count($this->skipped).' baris tanpa nama item dilewati (baris '.implode(', ', $lines)
                .(count($this->skipped) > count($lines) ? ', …' : '').').';
        }

        if ($this->defaultedCategory !== []) {
            $names = array_slice($this->defaultedCategory, 0, 5);
            $notes[] = count($this->defaultedCategory).' item baru tanpa kategori dibuat sebagai Bahan Baku ('.implode(', ', $names)
                .(count($this->defaultedCategory) > count($names) ? ', …' : '').'). Ubah kategorinya bila itu kemasan atau inventaris.';
        }

        return $notes === [] ? null : implode("\n", $notes);
    }
}
