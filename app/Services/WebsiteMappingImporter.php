<?php

namespace App\Services;

use App\Exports\WebsiteMappingSheet;
use App\Http\Controllers\MenuCatalogController;
use App\Models\AuditLog;
use App\Models\Product;
use App\Support\MenuPhotoProcessor;
use App\Support\WebsiteCatalog;
use App\Support\WebsiteMenuSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Impor lembar pemetaan menu website yang sudah diisi marketing (Bagian C).
 *
 * Setiap baris ber-ID produk membuat produk itu: ber-SKU = nama di website,
 * dicentang Tampil di website, berkategori sesuai kelompoknya di website lama,
 * dan -- bila belum berfoto -- memakai foto dari website lama.
 *
 * Seluruh berkas diperiksa dulu; satu baris bermasalah membatalkan semuanya
 * (pemetaan separuh lebih sulit dibereskan daripada belum sama sekali).
 * Produk yang sudah tampil di website tetapi tidak ada di berkas tidak diubah.
 */
class WebsiteMappingImporter
{
    /**
     * warnings = baris yang tetap diterapkan tetapi belum bisa terbit (tanda 3S
     * produknya berbeda dengan halaman hidangannya). Menu itu muncul di kotak
     * peringatan aplikasi Marketing; marketing yang memutuskan halamannya.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, warnings: list<string>, skipped: int}
     */
    public function check(string $path): array
    {
        $rows = $this->read($path);
        $errors = [];
        $warnings = [];
        $planned = [];
        $skipped = 0;

        $products = Product::query()->whereIn('id', array_filter(array_column($rows, 'product_id')))->get()->keyBy('id');

        foreach ($rows as $row) {
            $line = "Baris {$row['line']} ({$row['name']})";

            if ($row['product_id'] === null && $row['product_id_raw'] !== '') {
                $errors[] = "{$line}: PILIH ID PRODUK \"{$row['product_id_raw']}\" bukan angka ID.";

                continue;
            }

            if ($row['product_id'] === null) {
                $skipped++;

                continue;
            }

            $product = $products->get($row['product_id']);

            if (! $product || ! $product->active) {
                $errors[] = "{$line}: produk ID {$row['product_id']} tidak ada atau tidak aktif.";

                continue;
            }

            if ($row['sku'] === null) {
                $errors[] = "{$line}: SKU / NAMA DI WEBSITE kosong.";

                continue;
            }

            if ($row['category'] === null) {
                $errors[] = "{$line}: kelompok \"{$row['group']}\" tidak dikenal di halaman {$row['page_label']}. Jangan ubah kolom HALAMAN / KELOMPOK.";

                continue;
            }

            if (! WebsiteCatalog::fits($product, $row['category'])) {
                $warnings[] = "{$line}: produk {$product->id} ({$product->name}) bertanda "
                    .WebsiteCatalog::brands()[WebsiteCatalog::brandOf($product)]['label']
                    .", tetapi hidangan ini di halaman {$row['page_label']}. Tetap disimpan, belum ikut terbit:"
                    .' muncul di kotak peringatan Marketing sampai kategori atau tanda 3S-nya dibetulkan.';
            }

            $planned[] = $row + ['product' => $product];
        }

        // Satu produk mewakili satu hidangan; satu SKU dipakai satu produk.
        foreach (collect($planned)->groupBy(fn ($row) => $row['product']->id)->filter(fn ($group) => $group->count() > 1) as $id => $group) {
            $errors[] = "Produk {$id} dipilih untuk lebih dari satu hidangan: baris ".$group->pluck('line')->implode(', ').'.';
        }

        foreach (collect($planned)->groupBy(fn ($row) => mb_strtolower($row['sku']))->filter(fn ($group) => $group->count() > 1) as $group) {
            $errors[] = "SKU \"{$group->first()['sku']}\" dipakai lebih dari satu baris: ".$group->pluck('line')->implode(', ').'.';
        }

        $chosen = collect($planned)->map(fn ($row) => $row['product']->id)->all();
        $taken = Product::query()->whereNotIn('id', $chosen ?: [0])->whereNotNull('sku')
            ->get(['id', 'name', 'sku'])->keyBy(fn ($product) => mb_strtolower($product->sku));

        foreach ($planned as $row) {
            if ($owner = $taken->get(mb_strtolower($row['sku']))) {
                $errors[] = "Baris {$row['line']}: SKU \"{$row['sku']}\" sudah dipakai produk {$owner->id} ({$owner->name}) yang tidak ada di lembar ini.";
            }
        }

        return ['rows' => $planned, 'errors' => $errors, 'warnings' => $warnings, 'skipped' => $skipped];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  hasil check() tanpa error
     * @return array{updated: int, sku_changed: int, photos: int}
     */
    public function apply(array $rows, string $siteDir, ?int $userId = null): array
    {
        $summary = ['updated' => 0, 'sku_changed' => 0, 'photos' => 0];
        $written = [];

        try {
            DB::transaction(function () use ($rows, $siteDir, $userId, &$summary, &$written) {
                // SKU unik: kosongkan dulu yang berganti supaya pertukaran SKU antar
                // produk di lembar yang sama tidak menabrak indeks unik.
                $changing = collect($rows)->filter(fn ($row) => $row['product']->sku !== $row['sku']);
                Product::query()->whereIn('id', $changing->map(fn ($row) => $row['product']->id))->update(['sku' => null]);

                foreach ($rows as $row) {
                    /** @var Product $product */
                    $product = $row['product'];
                    $oldSku = $product->sku;
                    $attributes = ['sku' => $row['sku'], 'show_on_website' => true, 'website_category' => $row['category']];

                    if ($product->photo_path === null && ($path = $this->photoFromSite($row, $siteDir, $product))) {
                        $attributes += ['photo_path' => $path, 'photo_updated_at' => now()];
                        $written[] = $path;
                        $summary['photos']++;
                    }

                    $product->forceFill($attributes)->save();
                    $summary['updated']++;

                    if ($oldSku !== $row['sku']) {
                        $summary['sku_changed']++;
                    }

                    AuditLog::create([
                        'user_id' => $userId,
                        'entity' => 'product',
                        'entity_id' => $product->id,
                        'action' => 'website_mapped',
                        'message' => "Pemetaan website: {$product->name} → \"{$row['sku']}\" (".WebsiteCatalog::title($row['category']).')'
                            .($oldSku !== $row['sku'] ? ', SKU lama '.($oldSku ?? '(kosong)') : '')
                            .(isset($attributes['photo_path']) ? ', foto dari website lama' : ''),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Transaksi batal: foto yang sudah ditulis ke disk ikut dibuang.
            Storage::disk(MenuCatalogController::DISK)->delete($written);

            throw $e;
        }

        return $summary;
    }

    /** Foto website lama (assets/menu/x.webp) sebagai foto katalog, lewat pengolah yang sama dengan unggahan. */
    private function photoFromSite(array $row, string $siteDir, Product $product): ?string
    {
        if ($row['photo'] === null) {
            return null;
        }

        $file = rtrim($siteDir, '/\\').'/assets/menu/'.basename($row['photo']);

        if (! is_file($file)) {
            return null;
        }

        $photo = app(MenuPhotoProcessor::class)->process((string) file_get_contents($file));
        $path = MenuCatalogController::DIR.'/'.(Str::slug($row['sku']) ?: 'menu-'.$product->id)
            .'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.jpg';
        Storage::disk(MenuCatalogController::DISK)->put($path, $photo['data']);

        return $path;
    }

    /** @return list<array{line: int, page_label: string, brand: ?string, group: string, category: ?string, name: string, photo: ?string, product_id: ?int, product_id_raw: string, sku: ?string}> */
    private function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheetByName(WebsiteMappingSheet::TITLE);
        } catch (\Throwable) {
            throw new RuntimeException('Berkas tidak bisa dibaca sebagai Excel.');
        }

        if ($sheet === null) {
            throw new RuntimeException('Lembar "'.WebsiteMappingSheet::TITLE.'" tidak ada. Pakai berkas dari website:mapping-export.');
        }

        $data = $sheet->toArray(null, true, false, false);
        $columns = array_flip(array_map(fn ($heading) => trim((string) $heading), $data[0] ?? []));

        foreach (WebsiteMappingSheet::HEADINGS as $heading) {
            if (! isset($columns[$heading])) {
                throw new RuntimeException("Kolom \"{$heading}\" tidak ditemukan. Jangan ubah judul kolom lembar Pemetaan.");
            }
        }

        $col = fn (array $row, string $key) => $row[$columns[WebsiteMappingSheet::HEADINGS[$key]]] ?? null;
        $brandByLabel = collect(WebsiteCatalog::brands())->mapWithKeys(fn ($brand, $key) => [$brand['label'] => $key]);
        $rows = [];

        foreach (array_slice($data, 1) as $index => $row) {
            $name = trim((string) $col($row, 'name'));

            if ($name === '') {
                continue;
            }

            $pageLabel = trim((string) $col($row, 'page'));
            $brand = $brandByLabel[$pageLabel] ?? null;
            $group = trim((string) $col($row, 'group'));
            $id = trim((string) $col($row, 'product_id'));

            $rows[] = [
                'line' => $index + 2,
                'page_label' => $pageLabel,
                'brand' => $brand,
                'group' => $group,
                'category' => $brand ? WebsiteMenuSource::categoryFor($brand, $group) : null,
                'name' => $name,
                'photo' => filled($col($row, 'photo')) ? trim((string) $col($row, 'photo')) : null,
                'product_id' => ctype_digit($id) ? (int) $id : null,
                'product_id_raw' => $id,
                'sku' => Product::normalizeSku($col($row, 'sku')),
            ];
        }

        return $rows;
    }
}
