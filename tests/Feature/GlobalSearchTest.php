<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\GlobalSearchService;
use Spatie\Permission\Models\Role;

/**
 * Perilaku pencarian global: apa yang ketemu, apa yang tidak, dan bagaimana
 * kata kunci aneh diperlakukan. Hak aksesnya diuji terpisah di
 * tests/Feature/Security/GlobalSearchAccessTest.php.
 */
function penggunaCari(string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** Satu PO lengkap dengan pelanggan & area, supaya relasi ikut teruji. */
function poPencarian(string $recipient = 'Budi Santoso', string $customer = 'Warung Mawar'): PurchaseOrder
{
    $area = Area::query()->firstOrCreate(['code' => 'ACR'], ['name' => 'Area Cari']);
    $pelanggan = Customer::query()->create([
        'name' => $customer, 'phone' => '08123456789', 'area_id' => $area->id, 'is_lapak' => false,
    ]);
    $pembuat = User::factory()->create();

    return PurchaseOrder::query()->create([
        'customer_id' => $pelanggan->id,
        'recipient_name' => $recipient,
        'shipping_address' => 'Jl. Melati 1',
        'area_id' => $area->id,
        'delivery_date' => '2026-09-20',
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'status' => 'pending',
        'created_by' => $pembuat->id,
        'updated_by' => $pembuat->id,
    ]);
}

it('menemukan PO lewat nomor dokumennya dan menautkannya ke detail Admin', function () {
    $po = poPencarian();

    $hasil = app(GlobalSearchService::class)->search(penggunaCari('owner'), $po->po_number);

    expect($hasil)->not->toBeEmpty();
    expect($hasil[0]['title'])->toBe($po->po_number);
    expect($hasil[0]['group'])->toBe('Purchase Order');
    expect($hasil[0]['url'])->toBe(route('adminapp.orders.show', $po));
});

it('menemukan PO lewat nama pelanggannya, bukan hanya kolom PO sendiri', function () {
    $po = poPencarian(customer: 'Warung Kenanga');

    $hasil = app(GlobalSearchService::class)->search(penggunaCari('owner'), 'Kenanga');

    expect(collect($hasil)->pluck('title'))->toContain($po->po_number);
});

it('menemukan pelanggan dan menu lewat nama maupun SKU', function () {
    $area = Area::query()->firstOrCreate(['code' => 'ACR'], ['name' => 'Area Cari']);
    Customer::query()->create(['name' => 'Toko Anggrek', 'area_id' => $area->id, 'is_lapak' => false]);
    // Product menyimpan name & sku dalam huruf besar (mutator di modelnya),
    // jadi judul hasil mengikuti bentuk tersimpan itu.
    $menu = Product::query()->create(['name' => 'Nasi Goreng', 'sku' => 'NG-001', 'unit' => 'porsi', 'base_price' => 20000, 'active' => true]);

    $cari = app(GlobalSearchService::class);
    $owner = penggunaCari('owner');

    expect(collect($cari->search($owner, 'Anggrek'))->pluck('group'))->toContain('Pelanggan');
    expect(collect($cari->search($owner, 'NG-001'))->pluck('title'))->toContain($menu->name);
    // Huruf kecil tetap ketemu: pengguna tidak mengetik dalam huruf besar.
    expect(collect($cari->search($owner, 'nasi goreng'))->pluck('title'))->toContain($menu->name);
});

it('tidak mencari apa pun untuk kata kunci lebih pendek dari batas minimum', function () {
    poPencarian(recipient: 'Ax');

    $hasil = app(GlobalSearchService::class)->search(penggunaCari('owner'), 'A');

    expect($hasil)->toBe([]);
    expect(GlobalSearchService::MIN_LENGTH)->toBe(2);
});

it('memperlakukan % dan _ sebagai huruf biasa, bukan wildcard LIKE', function () {
    // Tanpa pelepasan wildcard, "%%" cocok dengan SEMUA baris -- pencarian
    // berubah jadi "tampilkan seluruh database".
    poPencarian(recipient: 'Budi Santoso');
    $diskon = poPencarian(recipient: 'Promo 50% Off', customer: 'Warung Diskon');

    $cari = app(GlobalSearchService::class);
    $owner = penggunaCari('owner');

    $semua = collect($cari->search($owner, '%%'))->pluck('title');
    expect($semua)->toBeEmpty();

    $cocok = collect($cari->search($owner, '50%'))->pluck('title');
    expect($cocok)->toContain($diskon->po_number);
    expect($cocok)->toHaveCount(1);
});

it('membatasi jumlah hasil per jenis dokumen', function () {
    foreach (range(1, GlobalSearchService::PER_SOURCE + 3) as $i) {
        poPencarian(recipient: "Pelanggan Banyak {$i}", customer: "Warung Banyak {$i}");
    }

    $hasil = collect(app(GlobalSearchService::class)->search(penggunaCari('owner'), 'Banyak'))
        ->where('group', 'Purchase Order');

    expect($hasil)->toHaveCount(GlobalSearchService::PER_SOURCE);
});

it('menyajikan hasil yang sama lewat endpoint suggest dan halaman hasil', function () {
    $po = poPencarian();
    $owner = penggunaCari('owner');

    $this->actingAs($owner)
        ->getJson(route('search.suggest', ['q' => $po->po_number]))
        ->assertOk()
        ->assertJsonPath('query', $po->po_number)
        ->assertJsonPath('results.0.title', $po->po_number);

    $this->actingAs($owner)
        ->get(route('search.index', ['q' => $po->po_number]))
        ->assertOk()
        ->assertSee($po->po_number);
});

it('memasang pencarian di sidebar dan tidak menyentuh bilah atas', function () {
    // Bilah atas memuat sampai 8 tab aplikasi untuk superadmin/owner dan
    // ruangnya pas-pasan: menambahkan apa pun di sana -- bahkan tombol ikon --
    // memotong tab terakhir. Tes ini menjaga penempatannya, bukan sekadar
    // keberadaannya, karena justru penempatan itu yang pernah merusak layout.
    $html = $this->actingAs(penggunaCari('owner'))
        ->get(route('ownerapp.dashboard'))
        ->assertOk()
        ->getContent();

    preg_match('/<header.*?<\/header>/s', $html, $header);
    preg_match('/<aside.*?<\/aside>/s', $html, $sidebar);

    // Bilah atas harus bersih dari pencarian; sidebar harus memuat pemicunya.
    expect($header[0] ?? '')->not->toContain('sh-search');
    expect($header[0] ?? '')->not->toContain('<dialog');
    expect($sidebar[0] ?? '')->toContain('sh-search-trigger');

    // Pemicunya sendiri, plus jalur tanpa-JavaScript-nya (tautan ke halaman
    // hasil) dan endpoint sarannya -- ketiganya, bukan salah satu.
    expect($html)->toContain('data-sh-search-input');
    expect($html)->toContain(route('search.index'));
    expect($html)->toContain(route('search.suggest'));
});

it('menyediakan kotak ketik di halaman hasil untuk jalur tanpa JavaScript', function () {
    // Tanpa JavaScript pemicu di sidebar hanya menautkan ke halaman ini, jadi
    // halaman ini wajib membawa form-nya sendiri -- kalau tidak, pencarian
    // mustahil dilakukan tanpa JavaScript.
    $this->actingAs(penggunaCari('owner'))
        ->get(route('search.index'))
        ->assertOk()
        ->assertSee('sh-search-page-form', escape: false)
        ->assertSee('name="q"', escape: false);
});

it('menolak kata kunci yang melewati batas panjang', function () {
    $this->actingAs(penggunaCari('owner'))
        ->getJson(route('search.suggest', ['q' => str_repeat('a', 101)]))
        ->assertStatus(422);
});
