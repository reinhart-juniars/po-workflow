<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Services\WebsiteMappingImporter;
use App\Support\WebsiteMenuSource;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bagian C: hidangan di website lama dipetakan ke produk Master Menu lewat
 * lembar Excel (website:mapping-export -> diisi marketing -> website:mapping-import).
 */
function situsLama(): string
{
    $dir = sys_get_temp_dir().'/situs-'.uniqid();
    mkdir($dir.'/assets/menu', 0777, true);

    $wwp = <<<'HTML'
    <html><body>
    <div class="menu-group" data-reveal data-cat="ayam"><div class="menu-group__head"><h2>Menu Ayam</h2></div>
      <ul class="menu-list menu-list--photo">
        <li><img src="assets/menu/nasi-ayam-teriyaki.webp" alt=""><span class="mi__n">Nasi Ayam Teriyaki</span></li>
        <li><img src="assets/menu/nasi-ayam-mentega.webp" alt=""><span class="mi__n">Nasi Ayam Mentega</span></li>
        <li><span class="mi__n">Nasi Ayam Saus Inggris</span></li>
      </ul></div>
    <div class="menu-group" data-reveal data-cat="inter"><div class="menu-group__head"><h2>Internasional &amp; Rice Bowl</h2></div>
      <ul class="menu-list menu-list--text"><li>Ricebowl Dori Cabe Garam</li><li>Rice Bowl Dori Cabe Garam</li><li>Spaghetti Bolognese</li></ul></div>
    </body></html>
    HTML;
    $tigaS = <<<'HTML'
    <html><body><div class="menu-group" data-cat="premium"><div class="menu-group__head"><h2>Nasi Premium &amp; Signature</h2></div>
      <ul class="menu-list menu-list--text"><li>Rawon</li></ul></div></body></html>
    HTML;

    file_put_contents($dir.'/wuenak-poll.html', $wwp);
    file_put_contents($dir.'/3s.html', $tigaS);

    // Foto kecil asli (WebP) supaya pengolah foto katalog benar-benar dijalankan.
    $image = imagecreatetruecolor(80, 60);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    imagewebp($image, $dir.'/assets/menu/nasi-ayam-teriyaki.webp');
    imagewebp($image, $dir.'/assets/menu/nasi-ayam-mentega.webp');

    return $dir;
}

/** Ubah sel lembar Pemetaan: [NO hidangan => [kolom => nilai]]. */
function isiLembar(string $path, array $changes): void
{
    $book = IOFactory::load($path);
    $sheet = $book->getSheetByName('Pemetaan');
    $headings = array_flip($sheet->rangeToArray('A1:L1')[0]);

    foreach ($changes as $no => $values) {
        foreach ($values as $heading => $value) {
            $sheet->setCellValue([$headings[$heading] + 1, $no + 1], $value);
        }
    }

    IOFactory::createWriter($book, 'Xlsx')->save($path);
}

beforeEach(function () {
    Storage::fake('public');
    $this->site = situsLama();
    $this->file = sys_get_temp_dir().'/pemetaan-'.uniqid().'.xlsx';

    $make = fn (string $name, ?string $sku, bool $is3s = false, bool $active = true) => Product::query()->create([
        'name' => $name, 'sku' => $sku, 'is_3s' => $is3s, 'active' => $active, 'base_price' => 15000,
    ]);
    $this->teriyaki12 = $make('NASI AYAM TERIYAKI 12K', 'ALACARTE-TERIYAKI-12');
    $this->teriyaki18 = $make('NASI AYAM TERIYAKI 18K', 'ALACARTE-TERIYAKI-18');
    $this->mentega = $make('NASI AYAM MENTEGA 20K', 'Nasi Ayam Teriyaki');   // SKU-nya akan ditukar
    $this->saos = $make('NASI AYAM SAOS INGGRIS 15K', null);
    $this->dori = $make('RICEBOWL DORI CABE GARAM 18K', null);
    $this->rawon = $make('RAWON 45K', null, is3s: false);                    // brand salah untuk halaman 3S
    $this->other = $make('NASI GORENG KAMPUNG 15K', 'Spaghetti Bolognese'); // SKU dipakai produk di luar lembar
});

afterEach(function () {
    @unlink($this->file);
    array_map('unlink', glob($this->site.'/assets/menu/*') ?: []);
    @unlink($this->site.'/wuenak-poll.html');
    @unlink($this->site.'/3s.html');
});

it('membaca hidangan per kelompok dan halaman dari HTML website lama', function () {
    $dishes = collect(WebsiteMenuSource::read($this->site));

    expect($dishes)->toHaveCount(7)
        ->and($dishes->firstWhere('name', 'Nasi Ayam Teriyaki'))->toMatchArray(['brand' => 'wwp', 'category' => 'ayam', 'photo' => 'nasi-ayam-teriyaki.webp'])
        ->and($dishes->firstWhere('name', 'Spaghetti Bolognese'))->toMatchArray(['category' => 'inter', 'photo' => null])
        ->and($dishes->firstWhere('name', 'Rawon'))->toMatchArray(['brand' => '3s', 'category' => 'premium']);
});

it('membuat lembar dengan usulan varian terlaris dan hidangan dobel dikosongkan', function () {
    // Varian 18K lebih sering dipesan: itu yang diusulkan, walau ID-nya lebih besar.
    $user = \App\Models\User::factory()->create();
    $area = \App\Models\Area::query()->create(['name' => 'Area Uji', 'code' => 'AU']);
    $customer = \App\Models\Customer::query()->create(['name' => 'Customer Uji', 'area_id' => $area->id]);
    $cash = \App\Models\CashAccount::query()->create(['name' => 'Kas', 'type' => 'cash', 'is_active' => true]);
    $po = \App\Models\PurchaseOrder::query()->create([
        'po_number' => 'PO-WEB-001', 'customer_id' => $customer->id, 'recipient_name' => 'Uji', 'shipping_address' => 'Jl. Uji',
        'area_id' => $area->id, 'delivery_date' => now()->toDateString(), 'delivery_time' => '09:00:00', 'payment_type' => 'cash',
        'cash_account_id' => $cash->id, 'status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id, 'total_qty' => 3, 'total_amount' => 54000,
    ]);
    foreach (range(1, 3) as $i) {
        \App\Models\PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $this->teriyaki18->id, 'qty' => 1, 'unit_price' => 18000, 'subtotal' => 18000]);
    }

    $this->artisan('website:mapping-export', ['--site' => $this->site, '--output' => $this->file])->assertSuccessful();

    $rows = collect(IOFactory::load($this->file)->getSheetByName('Pemetaan')->toArray())->slice(1)->keyBy(fn ($row) => $row[3]);

    expect($rows['Nasi Ayam Teriyaki'][5])->toBe('Pilih varian')
        ->and((int) $rows['Nasi Ayam Teriyaki'][8])->toBe($this->teriyaki18->id)
        ->and($rows['Nasi Ayam Teriyaki'][7])->toContain((string) $this->teriyaki12->id)
        ->and($rows['Nasi Ayam Mentega'][5])->toBe('Cocok')
        ->and((int) $rows['Ricebowl Dori Cabe Garam'][8])->toBe($this->dori->id)
        ->and($rows['Rice Bowl Dori Cabe Garam'][8])->toBeNull()
        ->and($rows['Rice Bowl Dori Cabe Garam'][10])->toContain('Hidangan yang sama dengan baris NO 4')
        ->and($rows['Rawon'][10])->toContain('bertanda Warung Wuenak Poll')
        ->and($rows['Spaghetti Bolognese'][10])->toContain('sudah dipakai produk '.$this->other->id);
});

it('menerapkan pemetaan: SKU (termasuk tukar), centang website, kategori, foto dari website lama', function () {
    $this->artisan('website:mapping-export', ['--site' => $this->site, '--output' => $this->file])->assertSuccessful();
    // Rawon dikosongkan (brand belum dibetulkan), Spaghetti tidak dijual lagi.
    isiLembar($this->file, [7 => ['PILIH ID PRODUK' => null], 6 => ['PILIH ID PRODUK' => null], 3 => ['PILIH ID PRODUK' => $this->saos->id]]);

    $this->artisan('website:mapping-import', ['file' => $this->file, '--site' => $this->site])
        ->expectsOutputToContain('Tersimpan: 4 produk')
        ->assertSuccessful();

    $teriyaki = $this->teriyaki12->fresh();
    expect($teriyaki->sku)->toBe('Nasi Ayam Teriyaki')
        ->and($teriyaki->show_on_website)->toBeTrue()
        ->and($teriyaki->website_category)->toBe('ayam')
        ->and($teriyaki->photo_path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($teriyaki->photo_path))->toBeTrue()
        // SKU "Nasi Ayam Teriyaki" pindah dari produk mentega tanpa menabrak indeks unik.
        ->and($this->mentega->fresh()->sku)->toBe('Nasi Ayam Mentega')
        ->and($this->saos->fresh())->toMatchArray(['sku' => 'Nasi Ayam Saus Inggris', 'website_category' => 'ayam', 'photo_path' => null])
        ->and($this->dori->fresh()->website_category)->toBe('inter')
        ->and($this->rawon->fresh()->show_on_website)->toBeFalse()
        ->and(Product::query()->onWebsite()->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'website_mapped')->count())->toBe(4);
});

it('menolak seluruh lembar bila ada baris bermasalah, lalu menerima setelah dibetulkan', function () {
    $this->artisan('website:mapping-export', ['--site' => $this->site, '--output' => $this->file])->assertSuccessful();
    isiLembar($this->file, [
        2 => ['PILIH ID PRODUK' => 'mentega'],                                 // bukan angka
        6 => ['PILIH ID PRODUK' => null],
        3 => ['PILIH ID PRODUK' => $this->teriyaki12->id],                     // produk dipakai dua baris
    ]);

    $result = app(WebsiteMappingImporter::class)->check($this->file);
    $errors = implode("\n", $result['errors']);

    expect($errors)->toContain('bukan angka ID')
        ->toContain('dipilih untuk lebih dari satu hidangan')
        // Rawon (bertanda WWP) di halaman 3S bukan error: tetap disimpan, diperingatkan.
        ->not->toContain('bertanda Warung Wuenak Poll')
        ->and(implode('
', $result['warnings']))->toContain('bertanda Warung Wuenak Poll');

    $this->artisan('website:mapping-import', ['file' => $this->file, '--site' => $this->site])->assertFailed();
    expect(Product::query()->where('show_on_website', true)->count())->toBe(0)
        ->and($this->mentega->fresh()->sku)->toBe('Nasi Ayam Teriyaki');

    // SKU yang dipakai produk di luar lembar juga ditolak.
    isiLembar($this->file, [2 => ['PILIH ID PRODUK' => $this->mentega->id], 3 => ['PILIH ID PRODUK' => $this->saos->id], 7 => ['PILIH ID PRODUK' => null],
        6 => ['PILIH ID PRODUK' => $this->dori->id + 1000]]);
    expect(implode("\n", app(WebsiteMappingImporter::class)->check($this->file)['errors']))->toContain('tidak ada atau tidak aktif');

    // Kontrol positif: setelah dibetulkan, lembar yang sama diterima.
    isiLembar($this->file, [6 => ['PILIH ID PRODUK' => null]]);
    $this->artisan('website:mapping-import', ['file' => $this->file, '--site' => $this->site, '--dry-run' => true])
        ->expectsOutputToContain('--dry-run')->assertSuccessful();
    expect(Product::query()->where('show_on_website', true)->count())->toBe(0);

    $this->artisan('website:mapping-import', ['file' => $this->file, '--site' => $this->site])->assertSuccessful();
    expect(Product::query()->onWebsite()->count())->toBe(4);
});

it('tetap menyimpan hidangan yang halamannya beda dengan tanda 3S, lalu memperingatkan marketing', function () {
    $this->artisan('website:mapping-export', ['--site' => $this->site, '--output' => $this->file])->assertSuccessful();
    // Rawon (bertanda Warung Wuenak Poll) dibiarkan di halaman 3S; Spaghetti tidak dijual lagi.
    isiLembar($this->file, [6 => ['PILIH ID PRODUK' => null], 3 => ['PILIH ID PRODUK' => $this->saos->id]]);

    $this->artisan('website:mapping-import', ['file' => $this->file, '--site' => $this->site])
        ->expectsOutputToContain('1 hidangan halamannya berbeda dengan tanda 3S produknya')
        ->expectsOutputToContain('Tersimpan: 5 produk')
        ->assertSuccessful();

    $rawon = $this->rawon->fresh();
    expect($rawon)->toMatchArray(['show_on_website' => true, 'website_category' => 'premium', 'is_3s' => false])
        // Belum terbit sampai marketing memutuskan halamannya; yang lain tetap terbit.
        ->and(Product::query()->onWebsite()->pluck('id'))->not->toContain($rawon->id)
        ->and(Product::query()->onWebsite()->count())->toBe(4);

    $excluded = collect(app(\App\Services\WebsiteMenuWarnings::class)->excluded());
    expect($excluded)->toHaveCount(1)
        ->and($excluded->first()['type'])->toBe('salah-halaman')
        ->and($excluded->first()['reason'])->toBe('Kategori Nasi Premium & Signature bukan milik halaman Warung Wuenak Poll');
});
