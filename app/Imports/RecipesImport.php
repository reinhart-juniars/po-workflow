<?php

namespace App\Imports;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\RecipeMismatchResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import resep beserta rincian bahannya dari format hasil export.
 *
 * Baris dikelompokkan per resep, lalu rincian bahan resep itu DIGANTI seluruhnya
 * oleh baris yang ada di berkas. Sengaja mengganti, bukan menggabung: rincian
 * resep adalah satu kesatuan, dan menggabung membuat bahan yang dihapus di
 * Excel tetap tertinggal di sistem tanpa ada yang menyadarinya.
 *
 * Konsekuensinya berkas harus berasal dari Export, karena di situlah bahan_id
 * dan sub_resep_id ikut terbawa. Berkas yang mengosongkan kedua kolom itu akan
 * melepas seluruh tautan bahan -- karena itu tautan hasil rekonsiliasi dipasang
 * kembali setelah import, persis seperti pada perpindahan data Master Menu.
 *
 * Satu kesalahan membatalkan seluruh berkas: resep yang tersimpan separuh lebih
 * sulit dibereskan daripada yang tidak tersimpan sama sekali.
 */
class RecipesImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    protected int $created = 0;

    protected int $updated = 0;

    protected int $lines = 0;

    /** @var array<int, string> */
    protected array $errors = [];

    public function collection(Collection $rows): void
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            // +2: baris pertama adalah heading, dan penomoran spreadsheet mulai dari 1.
            $lineNumber = $index + 2;
            $name = trim((string) ($row['nama_resep'] ?? ''));

            if ($name === '') {
                $this->errors[] = "Baris {$lineNumber}: nama resep kosong.";

                continue;
            }

            // Pengelompokan lewat id bila ada, lewat nama ternormalisasi bila
            // tidak -- sama dengan pembanding yang dipakai kolom name_norm.
            $key = filled($row['resep_id'] ?? null)
                ? 'id:'.$row['resep_id']
                : 'nama:'.Recipe::normalizeName($name);

            $groups[$key] ??= ['recipe' => null, 'items' => []];

            if ($groups[$key]['recipe'] === null) {
                $recipe = $this->parseRecipe($row, $name, $lineNumber);

                if ($recipe === null) {
                    continue;
                }

                $groups[$key]['recipe'] = $recipe;
            }

            $item = $this->parseItem($row, $lineNumber);

            if ($item !== null) {
                $groups[$key]['items'][] = $item;
            }
        }

        if ($this->errors !== []) {
            return;
        }

        DB::transaction(function () use ($groups) {
            foreach ($groups as $group) {
                $this->save($group['recipe'], $group['items']);
            }

            // Daftar bahan belum cocok dan tautan hasil rekonsiliasi disegarkan
            // di sini, supaya keadaan setelah import sama persis dengan keadaan
            // setelah perpindahan data -- tidak ada dua jalan masuk yang
            // meninggalkan sistem dalam keadaan berbeda.
            $resolver = app(RecipeMismatchResolver::class);
            $resolver->reapplyAll();
            $resolver->rebuild();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseRecipe(Collection $row, string $name, int $lineNumber): ?array
    {
        $jenis = mb_strtolower(trim((string) ($row['jenis'] ?? Recipe::JENIS_UTAMA))) ?: Recipe::JENIS_UTAMA;

        if (! array_key_exists($jenis, Recipe::jenisOptions())) {
            $this->errors[] = "Baris {$lineNumber}: jenis '{$jenis}' tidak dikenal (isi 'utama' atau 'sub').";

            return null;
        }

        $yield = (float) $this->number($row['hasil_qty'] ?? 1);

        if ($yield <= 0) {
            $this->errors[] = "Baris {$lineNumber}: jumlah hasil harus lebih dari nol; resep tanpa hasil tidak bisa dihitung HPP-nya.";

            return null;
        }

        // produk_id boleh berisi beberapa id dipisah koma (varian harga). Hanya
        // menambah tautan; melepas tautan dilakukan di Pencocokan Menu, karena
        // kolom kosong juga berarti "resep ini belum dipetakan".
        $productIds = collect(explode(',', (string) ($row['produk_id'] ?? '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isNotEmpty() && Product::query()->whereKey($productIds)->count() !== $productIds->count()) {
            $this->errors[] = "Baris {$lineNumber}: produk_id '{$row['produk_id']}' tidak ditemukan.";

            return null;
        }

        return [
            'id' => $row['resep_id'] ?? null,
            'name' => $name,
            'jenis' => $jenis,
            'kategori' => blank($row['kategori'] ?? null) ? null : trim((string) $row['kategori']),
            'product_ids' => $productIds->all(),
            'yield_qty' => $yield,
            'yield_unit' => trim((string) ($row['hasil_satuan'] ?? '')) ?: 'porsi',
            // Kolomnya bernama persen dan selalu dibagi 100; tidak ada tebakan
            // "kalau angkanya kecil berarti pecahan", karena tebakan seperti itu
            // akan membaca OHC 100% sebagai 1%.
            'ohc_pct' => $this->number($row['ohc_persen'] ?? 40) / 100,
            'profit_pct' => $this->number($row['profit_persen'] ?? 25) / 100,
            'target_price' => blank($row['harga_target'] ?? null) ? null : $this->number($row['harga_target']),
            'is_active' => ! in_array(mb_strtolower(trim((string) ($row['aktif'] ?? 'ya'))), ['tidak', 'no', '0', 'false', 'nonaktif'], true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseItem(Collection $row, int $lineNumber): ?array
    {
        $rawName = trim((string) ($row['nama_bahan'] ?? ''));

        if ($rawName === '') {
            return null;
        }

        $itemId = blank($row['bahan_id'] ?? null) ? null : (int) $row['bahan_id'];
        $refId = blank($row['sub_resep_id'] ?? null) ? null : (int) $row['sub_resep_id'];

        if ($itemId !== null && $refId !== null) {
            $this->errors[] = "Baris {$lineNumber}: '{$rawName}' menunjuk bahan sekaligus sub-resep; biayanya tidak bisa dihitung dua kali.";

            return null;
        }

        if ($itemId !== null && ! InventoryItem::query()->whereKey($itemId)->exists()) {
            $this->errors[] = "Baris {$lineNumber}: bahan_id '{$itemId}' tidak ditemukan.";

            return null;
        }

        if ($refId !== null && ! Recipe::query()->whereKey($refId)->exists()) {
            $this->errors[] = "Baris {$lineNumber}: sub_resep_id '{$refId}' tidak ditemukan.";

            return null;
        }

        return [
            'sort_order' => (int) ($row['urut'] ?? 0),
            'section' => blank($row['kelompok'] ?? null) ? null : trim((string) $row['kelompok']),
            'raw_name' => $rawName,
            'qty' => $this->number($row['jumlah'] ?? 0),
            'unit' => blank($row['satuan'] ?? null) ? null : trim((string) $row['satuan']),
            'inventory_item_id' => $itemId,
            'ref_recipe_id' => $refId,
            'notes' => blank($row['catatan_baris'] ?? null) ? null : trim((string) $row['catatan_baris']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function save(array $data, array $items): void
    {
        $id = $data['id'];
        $productIds = $data['product_ids'];
        unset($data['id'], $data['product_ids']);

        $recipe = filled($id)
            ? Recipe::query()->find($id)
            : Recipe::query()->firstWhere('name_norm', Recipe::normalizeName($data['name']));

        if ($recipe) {
            $recipe->update($data);
            $this->updated++;
        } else {
            $recipe = Recipe::query()->create($data);
            $this->created++;
        }

        if ($productIds !== []) {
            Product::query()->whereKey($productIds)->update(['recipe_id' => $recipe->id, 'needs_recipe' => true]);
        }

        // Rincian diganti seluruhnya: bahan yang dihapus di Excel harus ikut
        // hilang, bukan tertinggal diam-diam dan terus menambah HPP.
        $recipe->items()->delete();

        foreach ($items as $index => $item) {
            $recipe->items()->create($item + ['sort_order' => $item['sort_order'] ?: $index]);
            $this->lines++;
        }
    }

    protected function number(mixed $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Angka berformat Indonesia ("1.500,25") dari berkas yang diedit manusia.
        $clean = str_replace(['.', ' '], '', (string) $value);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    public function lines(): int
    {
        return $this->lines;
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
