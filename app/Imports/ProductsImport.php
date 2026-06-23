<?php

namespace App\Imports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    private const MAX_MONEY_VALUE = 9999999999.99;

    protected int $created = 0;
    protected int $updated = 0;
    protected array $previewRows = [];

    public function __construct(
        protected bool $commit = true
    ) {
    }

    public function collection(Collection $rows): void
    {
        $this->previewRows = $this->parseRows($rows);

        if ($this->commit) {
            $this->commitRows($this->previewRows);
        }
    }

    public function previewRows(): array
    {
        return $this->previewRows;
    }

    public function previewSummary(): array
    {
        return $this->summarize($this->previewRows);
    }

    public function commitRows(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $data = $row['data'] ?? $row;
                $product = $this->resolveProduct($data['id'] ?? null, $data['sku'] ?? null);

                if ($product) {
                    $payload = $this->payloadForSave($data, $product);

                    $product->update($payload);
                    $this->updated++;

                    continue;
                }

                Product::create($this->payloadForCreate($data));
                $this->created++;
            }
        });
    }

    public function summary(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'total' => $this->created + $this->updated,
        ];
    }

    protected function parseRows(Collection $rows): array
    {
        $parsedRows = [];

        foreach ($rows as $index => $row) {
            $excelRow = $index + 2;
            $data = $this->mapRow($row, $excelRow);

            if ($data === null) {
                continue;
            }

            $product = $this->resolveProduct($data['id'] ?? null, $data['sku'] ?? null);

            $parsedRows[] = [
                'excel_row' => $excelRow,
                'action' => $product ? 'update' : 'create',
                'matched_id' => $product?->id,
                'data' => $data,
                'profit' => $this->calculateProfit($data),
            ];
        }

        return $parsedRows;
    }

    protected function resolveProduct(mixed $id, mixed $sku): ?Product
    {
        $product = null;

        if (filled($id)) {
            $product = Product::find((int) $id);
        }

        if (! $product && filled($sku)) {
            return Product::where('sku', Str::upper(trim((string) $sku)))->first();
        }

        return $product;
    }

    protected function payloadForSave(array $data, ?Product $product = null): array
    {
        $payload = $data;
        unset($payload['id']);

        if (($payload['active'] ?? null) === null) {
            unset($payload['active']);
        }

        if (($payload['is_3s'] ?? null) === null) {
            unset($payload['is_3s']);
        }

        if (blank($payload['sku'] ?? null)) {
            $payload['sku'] = $product?->sku ?: Product::generateUniqueSku($payload['name'], $product?->id);
        }

        return $payload;
    }

    protected function payloadForCreate(array $data): array
    {
        $payload = $this->payloadForSave($data);

        if (($payload['active'] ?? null) === null) {
            $payload['active'] = true;
        }

        if (! array_key_exists('is_3s', $payload)) {
            $payload['is_3s'] = false;
        }

        return $payload;
    }

    protected function calculateProfit(array $data): float
    {
        return round(
            (float) $data['base_price']
            - ((float) ($data['raw_material_cost'] ?? 0) + (float) ($data['overhead_cost'] ?? 0)),
            2
        );
    }

    protected function summarize(array $rows): array
    {
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            if (($row['action'] ?? null) === 'update') {
                $updated++;
            } else {
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => $created + $updated,
        ];
    }

    protected function mapRow(Collection $row, int $excelRow): ?array
    {
        $name = $this->stringValue($row, ['nama_menu', 'name', 'nama']);
        $unit = $this->stringValue($row, ['satuan', 'unit']);
        $sku = $this->stringValue($row, ['sku']);
        $basePriceRaw = $this->cellValue($row, ['harga_jual', 'base_price']);
        $rawMaterialRaw = $this->cellValue($row, ['bahan_baku', 'raw_material_cost']);
        $overheadRaw = $this->cellValue($row, ['overhead_cost', 'overhead']);
        $activeRaw = $this->cellValue($row, ['aktif', 'active']);
        $is3sRaw = $this->cellValue($row, ['menu_3s', 'is_3s', '3s']);
        $id = $this->cellValue($row, ['id']);

        if (
            blank($name)
            && blank($unit)
            && blank($sku)
            && blank($basePriceRaw)
            && blank($rawMaterialRaw)
            && blank($overheadRaw)
            && blank($activeRaw)
            && blank($is3sRaw)
            && blank($id)
        ) {
            return null;
        }

        if (blank($name)) {
            throw new \RuntimeException("Baris {$excelRow}: kolom nama menu wajib diisi.");
        }

        if (blank($unit)) {
            throw new \RuntimeException("Baris {$excelRow}: kolom satuan wajib diisi.");
        }

        $basePrice = $this->parseNumber($basePriceRaw, 'harga jual', $excelRow, false);
        $rawMaterialCost = $this->parseNumber($rawMaterialRaw, 'bahan baku', $excelRow, false);
        $overheadCost = $this->parseNumber($overheadRaw, 'overhead cost', $excelRow, false);

        return [
            'id' => filled($id) ? (int) $id : null,
            'sku' => filled($sku) ? Str::upper(trim($sku)) : null,
            'name' => $name,
            'unit' => $unit,
            'base_price' => $basePrice,
            'raw_material_cost' => $rawMaterialCost,
            'overhead_cost' => $overheadCost,
            'active' => $this->parseBoolean($activeRaw, $excelRow),
            'is_3s' => $this->parseBoolean($is3sRaw, $excelRow),
        ];
    }

    protected function cellValue(Collection $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($row->has($key)) {
                return $row->get($key);
            }
        }

        return null;
    }

    protected function stringValue(Collection $row, array $keys): ?string
    {
        $value = $this->cellValue($row, $keys);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function parseBoolean(mixed $value, int $excelRow): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'ya', 'aktif' => true,
            '0', 'false', 'no', 'tidak', 'nonaktif', 'non-aktif' => false,
            default => throw new \RuntimeException("Baris {$excelRow}: nilai aktif `{$value}` tidak valid."),
        };
    }

    protected function parseNumber(mixed $value, string $columnLabel, int $excelRow, bool $required): ?float
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new \RuntimeException("Baris {$excelRow}: kolom {$columnLabel} wajib diisi.");
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } else {
            $normalized = preg_replace('/[^\d,\.\-]/', '', trim((string) $value));

            if ($normalized === '' || $normalized === null) {
                if ($required) {
                    throw new \RuntimeException("Baris {$excelRow}: kolom {$columnLabel} wajib berupa angka.");
                }

                return null;
            }

            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');

            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    $normalized = str_replace('.', '', $normalized);
                    $normalized = str_replace(',', '.', $normalized);
                } else {
                    $normalized = str_replace(',', '', $normalized);
                }
            } elseif ($lastComma !== false) {
                $fractionLength = strlen(substr($normalized, $lastComma + 1));

                $normalized = substr_count($normalized, ',') > 1 || $fractionLength === 3
                    ? str_replace(',', '', $normalized)
                    : str_replace(',', '.', $normalized);
            } elseif ($lastDot !== false) {
                $fractionLength = strlen(substr($normalized, $lastDot + 1));

                if (substr_count($normalized, '.') > 1 || $fractionLength === 3) {
                    $normalized = str_replace('.', '', $normalized);
                }
            }

            if (! is_numeric($normalized)) {
                throw new \RuntimeException("Baris {$excelRow}: kolom {$columnLabel} wajib berupa angka.");
            }

            $number = (float) $normalized;
        }

        if ($number < 0) {
            throw new \RuntimeException("Baris {$excelRow}: kolom {$columnLabel} tidak boleh negatif.");
        }

        if ($number > self::MAX_MONEY_VALUE) {
            throw new \RuntimeException(
                "Baris {$excelRow}: kolom {$columnLabel} melebihi batas maksimal " . number_format(self::MAX_MONEY_VALUE, 2, ',', '.') . "."
            );
        }

        return round($number, 2);
    }
}
