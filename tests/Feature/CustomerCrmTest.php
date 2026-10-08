<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\CustomerInsightService;
use Spatie\Permission\Models\Role;

/**
 * Admin sebagai mini CRM (revisi 7 Okt 2026): daftar customer dengan order
 * terakhir & omzet, tab status (aktif / baru / lama tidak order / belum
 * pernah), dan profil per customer. Dasarnya PO completed.
 */
function adminCrm(string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function customerCrm(array $attributes): Customer
{
    $area = App\Models\Area::query()->firstOrCreate(['name' => 'Area Tes']);

    return Customer::query()->create($attributes + ['area_id' => $area->id]);
}

function poCustomer(Customer $customer, string $date, float $total, string $status = 'completed', array $items = []): PurchaseOrder
{
    $po = PurchaseOrder::query()->create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'shipping_address' => 'Jl. Tes',
        'area_id' => $customer->area_id,
        'delivery_date' => $date,
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'status' => $status,
        'completed_at' => $status === 'completed' ? $date.' 12:00:00' : null,
        'created_by' => User::factory()->create()->id,
    ]);
    $po->forceFill(['total_amount' => $total])->saveQuietly();

    foreach ($items as [$product, $qty]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id, 'qty' => $qty,
            'unit' => 'porsi', 'unit_price' => 10000, 'subtotal' => $qty * 10000,
        ]);
    }
    // Item PO menghitung ulang total dari subtotal; tes menetapkan totalnya sendiri.
    PurchaseOrder::query()->whereKey($po->id)->update(['total_amount' => $total]);

    return $po->refresh();
}

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay()));

it('merangkum profil: omzet, rata-rata, jarak order, menu favorit, dan omzet bulanan dari PO selesai saja', function () {
    $customer = customerCrm(['name' => 'PT Maju', 'active' => true]);
    $ayam = Product::query()->create(['name' => 'Nasi Ayam', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);
    $ikan = Product::query()->create(['name' => 'Nasi Ikan', 'unit' => 'porsi', 'base_price' => 10000, 'active' => true]);

    poCustomer($customer, '2026-08-01', 300_000, items: [[$ayam, 20], [$ikan, 10]]);
    poCustomer($customer, '2026-09-10', 500_000, items: [[$ayam, 30]]);
    poCustomer($customer, '2026-10-01', 400_000, items: [[$ikan, 35]]);
    poCustomer($customer, '2026-10-08', 999_000, 'draft', items: [[$ayam, 500]]); // draft: tidak dihitung

    $service = app(CustomerInsightService::class);
    $profile = $service->profile($customer);

    expect($profile)->toMatchArray([
        'po_count' => 3,
        'revenue' => 1_200_000.0,
        'avg_po_value' => 400_000.0,
        'days_since_last' => 8,
        'avg_interval_days' => 30.5, // 1 Agu..1 Okt = 61 hari / 2 jeda
        'recent_po_count' => 3,
        'status' => CustomerInsightService::STATUS_ACTIVE,
    ]);

    $favorit = $service->favoriteMenus($customer);
    // Nama produk disimpan kapital oleh model Product.
    expect($favorit->pluck('name')->all())->toBe(['NASI AYAM', 'NASI IKAN'])
        ->and($favorit->first()->qty)->toBe(50.0)
        ->and($favorit->last()->qty)->toBe(45.0)
        // Draft 500 porsi Nasi Ayam tidak ikut.
        ->and($favorit->first()->orders)->toBe(2);

    $bulanan = collect($service->monthlyRevenue($customer))->keyBy('month');
    expect($bulanan)->toHaveCount(12)
        ->and($bulanan['2026-10']['revenue'])->toBe(400_000.0)
        ->and($bulanan['2026-09']['revenue'])->toBe(500_000.0)
        ->and($bulanan['2025-11']['revenue'])->toBe(0.0);
});

it('menentukan status customer dari order pertama & terakhir', function () {
    $service = app(CustomerInsightService::class);
    $baru = customerCrm(['name' => 'Baru', 'active' => true]);
    $aktif = customerCrm(['name' => 'Aktif', 'active' => true]);
    $lama = customerCrm(['name' => 'Lama', 'active' => true]);
    $belum = customerCrm(['name' => 'Belum', 'active' => true]);
    poCustomer($baru, '2026-10-01', 100_000);
    poCustomer($aktif, '2026-05-01', 100_000);
    poCustomer($aktif, '2026-10-05', 100_000);
    poCustomer($lama, '2026-08-20', 100_000);         // 50 hari lalu
    poCustomer($belum, '2026-10-07', 100_000, 'draft'); // draft bukan order

    foreach ([[$baru, 'baru'], [$aktif, 'aktif'], [$lama, 'lama'], [$belum, 'belum']] as [$customer, $expected]) {
        expect($service->profile($customer)['status'])->toBe($expected, $customer->name);
        // Filter database = status per baris (supaya tab dan badge tidak berbeda).
        expect($service->filterByStatus(Customer::query(), $expected)->pluck('name')->all())->toBe([$customer->name]);
    }
});

it('menampilkan daftar customer dengan tab status dan profil yang bisa dibuka admin', function () {
    $admin = adminCrm();
    $lama = customerCrm(['name' => 'Kantin Lama', 'active' => true, 'phone' => '0811']);
    $aktif = customerCrm(['name' => 'Kantin Aktif', 'active' => true]);
    poCustomer($lama, '2026-07-01', 250_000);
    poCustomer($aktif, '2026-10-02', 175_000);

    $this->actingAs($admin)->get(route('adminapp.customers.index'))->assertOk()
        ->assertSee('Kantin Lama')->assertSee('Kantin Aktif')
        ->assertSee('Lama tidak order')
        ->assertSee('Rp 175.000');

    $this->actingAs($admin)->get(route('adminapp.customers.index', ['status' => 'lama']))->assertOk()
        ->assertSee('Kantin Lama')->assertDontSee('Kantin Aktif');

    $this->actingAs($admin)->get(route('adminapp.customers.show', $aktif))->assertOk()
        ->assertSee('Kantin Aktif')
        ->assertSee('Omzet per bulan')
        ->assertSee('Rp 175.000')
        ->assertSee('Riwayat PO');
});

it('membatasi profil customer untuk aplikasi Admin', function () {
    $customer = customerCrm(['name' => 'Rahasia', 'active' => true]);

    $this->get(route('adminapp.customers.show', $customer))->assertRedirect(route('login'));

    foreach (['sales', 'delivery', 'inventory'] as $role) {
        $this->actingAs(adminCrm($role))->get(route('adminapp.customers.show', $customer))->assertForbidden();
    }

    // Kontrol positif.
    $this->actingAs(adminCrm('owner'))->get(route('adminapp.customers.show', $customer))->assertOk()->assertSee('Belum pernah order');
});

it('menyusun sidebar Admin sebagai CRM', function () {
    expect(array_keys(App\Support\Navigation::menus()['admin']))->toBe(['Ringkasan', 'Customer', 'Pesanan', 'Menu & Harga', 'Laporan']);
});

it('menampilkan pengeluaran per kategori sebagai batang berurutan, bukan pie chart', function () {
    $owner = adminCrm('owner');
    $kas = App\Models\CashAccount::query()->create(['name' => 'Kas', 'type' => 'cash', 'is_active' => true]);
    $lokasi = App\Models\ExpenseLocation::query()->firstOrCreate(['name' => 'Pusat'], ['type' => 'center', 'is_active' => true]);
    foreach ([['Gaji', 300_000], ['Bahan Baku', 700_000], ['Pajak', 0.0]] as [$nama, $nilai]) {
        $kategori = App\Models\ExpenseCategory::query()->create(['name' => $nama, 'expense_mode' => 'direct_expense', 'is_active' => true]);
        if ($nilai > 0) {
            App\Models\CashOut::query()->create(['expense_category_id' => $kategori->id, 'expense_location_id' => $lokasi->id, 'cash_account_id' => $kas->id, 'amount' => $nilai, 'expense_date' => '2026-10-05']);
        }
    }

    $response = $this->actingAs($owner)->get(route('ownerapp.dashboard', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))->assertOk()
        ->assertSee('Pengeluaran per Kategori')
        ->assertDontSee('Pie Chart')
        ->assertSeeInOrder(['Bahan Baku', 'Rp 700.000', '70,0%', 'Gaji', 'Rp 300.000', '30,0%']);

    $bars = $response->viewData('expenseCategoryBars');
    expect($bars['total'])->toBe(1_000_000.0)
        ->and(collect($bars['rows'])->pluck('label')->all())->toBe(['Bahan Baku', 'Gaji']) // kategori nol tidak tampil
        ->and($bars['rows'][0]['width'])->toBe(100.0)
        ->and($bars['rows'][1]['width'])->toBe(round(300 / 700 * 100, 2));
});
