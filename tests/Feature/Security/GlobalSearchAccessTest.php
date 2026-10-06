<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\GlobalSearchService;
use Spatie\Permission\Models\Role;

/**
 * Pagar pencarian global.
 *
 * Pencarian adalah permukaan bocor yang khas: satu kotak yang membaca banyak
 * tabel sekaligus, dipakai semua peran. Aturan yang dijaga di sini: sebuah
 * hasil hanya boleh muncul bila penggunanya memang boleh membuka halaman
 * tujuannya -- kalau tidak, dokumen tidak dicari sama sekali.
 *
 * Tiap tes negatif di bawah membawa kendali positifnya sendiri: peran yang
 * SEHARUSNYA menemukan dokumen itu diuji di tes yang sama, supaya "tidak
 * ketemu" tidak pernah hijau hanya karena datanya memang tidak ada atau
 * pencariannya rusak total.
 */
function penggunaAkses(string ...$roles): User
{
    foreach ($roles as $role) {
        Role::findOrCreate($role, 'web');
    }

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($roles);

    return $user;
}

function areaAkses(): Area
{
    return Area::query()->firstOrCreate(['code' => 'AKS'], ['name' => 'Area Akses']);
}

function poAkses(string $recipient = 'Rahasia Admin'): PurchaseOrder
{
    $area = areaAkses();
    $customer = Customer::query()->create(['name' => 'Pelanggan Rahasia', 'area_id' => $area->id, 'is_lapak' => false]);
    $pembuat = User::factory()->create();

    return PurchaseOrder::query()->create([
        'customer_id' => $customer->id,
        'recipient_name' => $recipient,
        'shipping_address' => 'Jl. Rahasia 9',
        'area_id' => $area->id,
        'delivery_date' => '2026-09-21',
        'delivery_time' => '10:00:00',
        'payment_type' => 'cash',
        'status' => 'pending',
        'created_by' => $pembuat->id,
        'updated_by' => $pembuat->id,
    ]);
}

/* ---------------------------------------------------------------------------
 | Tes arsitektur -- sumber baru tidak boleh lolos tanpa pagar
 * ------------------------------------------------------------------------- */

it('mewajibkan setiap sumber pencarian menyebut pagar aksesnya', function () {
    $tanpaPagar = [];

    foreach (GlobalSearchService::sources() as $key => $source) {
        if (empty($source['targets'])) {
            $tanpaPagar[] = $key.' (tidak punya targets)';

            continue;
        }

        foreach ($source['targets'] as $i => $target) {
            $punyaPeran = ! empty($target['roles']);
            $punyaIzin = ! empty($target['permission']);

            if (! $punyaPeran && ! $punyaIzin) {
                $tanpaPagar[] = $key.' target #'.$i.' (tanpa roles maupun permission)';
            }
        }
    }

    expect($tanpaPagar)->toBe([], 'Sumber pencarian tanpa pagar akses: '.implode(', ', $tanpaPagar));
});

it('memastikan setiap route tujuan pencarian benar-benar terdaftar', function () {
    // Route yang salah tulis bikin pencarian melempar RouteNotFoundException
    // di produksi, bukan di sini.
    expect(GlobalSearchService::missingRoutes())->toBe([]);
});

/* ---------------------------------------------------------------------------
 | Tes penyalahgunaan -- peran A menembak dokumen peran B
 * ------------------------------------------------------------------------- */

it('menyembunyikan Purchase Order dari peran sales, tetapi tetap menampilkannya untuk admin', function () {
    $po = poAkses();
    $cari = app(GlobalSearchService::class);

    // Negatif: sales tidak punya route detail PO, jadi PO tidak dicari.
    $hasilSales = collect($cari->search(penggunaAkses('sales'), $po->po_number))->pluck('title');
    expect($hasilSales)->not->toContain($po->po_number);

    // Kendali positif: dokumen, kata kunci, dan jalur kode yang sama.
    $hasilAdmin = collect($cari->search(penggunaAkses('admin'), $po->po_number))->pluck('title');
    expect($hasilAdmin)->toContain($po->po_number);
});

it('menyembunyikan Delivery Order dari peran admin, tetapi tetap menampilkannya untuk delivery', function () {
    $do = DeliveryOrder::query()->create([
        'area_id' => areaAkses()->id,
        'scheduled_at' => '2026-09-21 08:00:00',
        'driver_user_id' => penggunaAkses('delivery')->id,
        'status' => 'ready',
    ]);

    $cari = app(GlobalSearchService::class);

    $hasilAdmin = collect($cari->search(penggunaAkses('admin'), $do->do_code))->pluck('title');
    expect($hasilAdmin)->not->toContain($do->do_code);

    $hasilDelivery = collect($cari->search(penggunaAkses('delivery'), $do->do_code))->pluck('title');
    expect($hasilDelivery)->toContain($do->do_code);
});

it('menyembunyikan pelanggan dari peran delivery, tetapi tetap menampilkannya untuk admin', function () {
    Customer::query()->create(['name' => 'Katering Seruni', 'area_id' => areaAkses()->id, 'is_lapak' => false]);

    $cari = app(GlobalSearchService::class);

    expect(collect($cari->search(penggunaAkses('delivery'), 'Seruni'))->pluck('title'))
        ->not->toContain('Katering Seruni');

    expect(collect($cari->search(penggunaAkses('admin'), 'Seruni'))->pluck('title'))
        ->toContain('Katering Seruni');
});

it('menyaring SPK Produksi lewat izin modul, bukan lewat peran saja', function () {
    $spk = ProductionOrder::query()->create([
        'title' => 'Produksi Seblak Pagi',
        'production_date' => '2026-09-21',
        'status' => ProductionOrder::STATUS_DRAFT ?? 'draft',
    ]);

    $cari = app(GlobalSearchService::class);

    // Negatif: sales tidak punya production.view (matriks ModuleAccess kosong).
    expect(collect($cari->search(penggunaAkses('sales'), 'Seblak'))->pluck('title'))
        ->not->toContain($spk->number);

    // Kendali positif: production punya production.view.
    expect(collect($cari->search(penggunaAkses('production'), 'Seblak'))->pluck('title'))
        ->toContain($spk->number);
});

it('mengarahkan PO ke halaman yang boleh dibuka masing-masing peran', function () {
    $po = poAkses();
    $cari = app(GlobalSearchService::class);

    $admin = collect($cari->search(penggunaAkses('admin'), $po->po_number))->firstWhere('title', $po->po_number);
    expect($admin['url'])->toBe(route('adminapp.orders.show', $po));

    // Produksi melihat PO yang sama, tapi lewat halaman progres Production App
    // -- bukan detail Admin yang akan membalas 403.
    $produksi = collect($cari->search(penggunaAkses('production'), $po->po_number))->firstWhere('title', $po->po_number);
    expect($produksi['url'])->toBe(route('productionapp.orders.show', $po));
});

/* ---------------------------------------------------------------------------
 | Tes HTTP -- pagar yang sama lewat route sungguhan
 * ------------------------------------------------------------------------- */

it('tidak membocorkan dokumen lewat endpoint suggest untuk peran yang tidak berhak', function () {
    $po = poAkses();

    $this->actingAs(penggunaAkses('sales'))
        ->getJson(route('search.suggest', ['q' => $po->po_number]))
        ->assertOk()
        ->assertJsonPath('results', []);

    // Kendali positif: endpoint dan kata kunci yang sama, peran yang berhak.
    $this->actingAs(penggunaAkses('admin'))
        ->getJson(route('search.suggest', ['q' => $po->po_number]))
        ->assertOk()
        ->assertJsonPath('results.0.title', $po->po_number);
});

it('menutup pencarian untuk tamu, tetapi tetap membukanya untuk pengguna masuk', function () {
    $this->get(route('search.index', ['q' => 'apa saja']))->assertRedirect(route('login'));
    $this->getJson(route('search.suggest', ['q' => 'apa saja']))->assertUnauthorized();

    $this->actingAs(penggunaAkses('owner'))
        ->get(route('search.index', ['q' => 'apa saja']))
        ->assertOk();
});

it('membatasi laju endpoint suggest', function () {
    $user = penggunaAkses('owner');

    // Batasnya 60/menit; panggilan ke-61 harus ditolak.
    foreach (range(1, 60) as $i) {
        $this->actingAs($user)->getJson(route('search.suggest', ['q' => 'abc']))->assertOk();
    }

    $this->actingAs($user)->getJson(route('search.suggest', ['q' => 'abc']))->assertStatus(429);
});
