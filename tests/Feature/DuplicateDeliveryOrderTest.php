<?php

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\SalesActualService;
use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\post;

/*
 * Kasus 14 Nov 2026: klik ganda "Teruskan ke Delivery" membuat DO-20261114-0002
 * dan -0003 berisi PO yang sama; tiap DO menghasilkan Sales Actual sendiri dan
 * Laporan Penjualan menampilkan tiap customer lapak dua kali.
 */

beforeEach(function () {
    Role::findOrCreate('admin', 'web');

    $this->admin = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->admin->assignRole('admin');

    $this->area = Area::query()->create(['name' => 'Area DO Ganda', 'code' => 'DOG']);
    $this->cash = CashAccount::query()->create(['name' => 'Kas DO Ganda', 'type' => 'cash', 'is_active' => true]);
    $this->product = Product::query()->create([
        'name' => 'NASI AYAM SAOS INGGRIS', 'sku' => 'DOG-NASI', 'unit' => 'box', 'base_price' => 12000, 'active' => true,
    ]);

    $this->makePo = function (string $number, string $customerName, float $qty): PurchaseOrder {
        $customer = Customer::query()->create([
            'name' => $customerName, 'phone' => '0812', 'address' => 'Jl. Lapak', 'area_id' => $this->area->id, 'is_lapak' => true,
        ]);

        $po = PurchaseOrder::query()->create([
            'po_number' => $number,
            'customer_id' => $customer->id,
            'recipient_name' => $customerName,
            'shipping_address' => 'Jl. Lapak',
            'area_id' => $this->area->id,
            'delivery_date' => '2026-11-14',
            'delivery_time' => '10:00:00',
            'payment_type' => 'cash',
            'cash_account_id' => $this->cash->id,
            'status' => 'completed',
            'completed_at' => now(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'qty' => $qty,
            'unit' => 'box',
            'unit_price' => 12000,
            'subtotal' => $qty * 12000,
        ]);

        return $po;
    };

    $this->storePayload = fn (array $poIds) => [
        'po_ids' => $poIds,
        'area_id' => $this->area->id,
        'schedule_date' => '2026-11-14',
        'schedule_time' => '15:00',
        'driver_user_id' => $this->admin->id,
    ];
});

it('menolak DO kedua untuk PO yang sudah punya DO (klik ganda)', function () {
    $kutisari = ($this->makePo)('PO-20261114-0004', 'KUTISARI LAPAK', 32);
    $aris = ($this->makePo)('PO-20261114-0006', 'ARIS LAPAK', 18);
    $payload = ($this->storePayload)([$kutisari->id, $aris->id]);

    actingAs($this->admin);

    // Kontrol positif: kiriman pertama tetap membuat DO.
    post(route('adminapp.delivery.store'), $payload)
        ->assertRedirect(route('adminapp.delivery.index'))
        ->assertSessionHasNoErrors();

    expect(DeliveryOrder::query()->count())->toBe(1)
        ->and(DB::table('delivery_order_purchase_orders')->count())->toBe(2);

    // Kiriman kedua (klik ganda) ditolak dan tidak membuat DO baru.
    post(route('adminapp.delivery.store'), $payload)
        ->assertRedirect(route('adminapp.delivery.index'))
        ->assertSessionHasErrors('po_ids');

    expect(DeliveryOrder::query()->count())->toBe(1)
        ->and(DB::table('delivery_order_purchase_orders')->count())->toBe(2);
});

it('menolak seluruh kiriman bila satu PO di dalamnya sudah punya DO', function () {
    $kutisari = ($this->makePo)('PO-20261114-0004', 'KUTISARI LAPAK', 32);
    $siany = ($this->makePo)('PO-20261114-0003', 'SIANY', 12);
    $faris = ($this->makePo)('PO-20261114-0005', 'FARIS LAPAK', 35);

    actingAs($this->admin);

    post(route('adminapp.delivery.store'), ($this->storePayload)([$kutisari->id]))->assertSessionHasNoErrors();

    post(route('adminapp.delivery.store'), ($this->storePayload)([$kutisari->id, $siany->id]))
        ->assertSessionHasErrors(['po_ids' => 'PO berikut sudah dijadwalkan ke DO lain, jadi tidak dibuatkan DO lagi: PO-20261114-0004 ('.DeliveryOrder::query()->sole()->do_code.'). Muat ulang halaman ini sebelum membuat DO.']);

    expect(DeliveryOrder::query()->count())->toBe(1)
        ->and($siany->deliveryOrders()->exists())->toBeFalse();

    // Kontrol positif: PO yang belum punya DO tetap bisa dibuatkan DO.
    post(route('adminapp.delivery.store'), ($this->storePayload)([$siany->id, $faris->id]))->assertSessionHasNoErrors();

    expect(DeliveryOrder::query()->count())->toBe(2)
        ->and($siany->deliveryOrders()->count())->toBe(1);
});

/** Bentuk ulang data 14 Nov: dua DO berisi PO yang sama, semua Sales Actual tersubmit. */
function buatDoGanda(object $test): array
{
    $pos = collect([
        ($test->makePo)('PO-20261114-0004', 'KUTISARI LAPAK', 32),
        ($test->makePo)('PO-20261114-0006', 'ARIS LAPAK', 18),
    ]);

    $service = app(SalesActualService::class);
    actingAs($test->admin);

    $dos = collect(['DO-20261114-0002', 'DO-20261114-0003'])->map(function (string $code) use ($test, $pos, $service) {
        $do = DeliveryOrder::query()->create([
            'do_code' => $code,
            'area_id' => $test->area->id,
            'scheduled_at' => '2026-11-14 15:00:00',
            'driver_user_id' => $test->admin->id,
            'status' => 'delivered',
        ]);
        $do->purchaseOrders()->attach($pos->pluck('id')->all());
        DB::table('proof_of_delivery')->insert(['delivery_order_id' => $do->id, 'purchase_order_id' => $pos->first()->id, 'photo_path' => 'pod/'.$code.'.jpg', 'created_at' => now(), 'updated_at' => now()]);

        foreach ($service->createDraftFromDeliveryOrder($do) as $draft) {
            $service->submit($draft);
        }

        return $do;
    });

    return [$dos[0], $dos[1], $pos];
}

function laporanPenjualan14Nov(): array
{
    return app(SalesReportService::class)
        ->buildReportData(Request::create('/', 'GET', ['date_from' => '2026-11-14', 'date_to' => '2026-11-14']))['salesGrandTotals'];
}

it('menghapus DO ganda beserta Sales Actual-nya sehingga laporan penjualan tidak dobel', function () {
    [$keep, $duplicate] = buatDoGanda($this);

    expect(laporanPenjualan14Nov())->toMatchArray(['total_orders' => 4, 'grand_total' => 1200000.0]);

    artisan('delivery:hapus-do-ganda')
        ->expectsOutputToContain('DO-20261114-0003')
        ->assertSuccessful();

    // Uji-jalan tidak mengubah apa pun.
    artisan('delivery:hapus-do-ganda', ['do' => [$duplicate->id]])
        ->expectsOutputToContain('Uji-jalan')
        ->assertSuccessful();

    expect(DeliveryOrder::query()->count())->toBe(2)
        ->and(SalesActual::query()->count())->toBe(4);

    $duplicateSaIds = $duplicate->salesActuals()->pluck('id')->all();
    $keptSaIds = $keep->salesActuals()->pluck('id')->all();

    artisan('delivery:hapus-do-ganda', ['do' => [$duplicate->id], '--execute' => true])->assertSuccessful();

    expect(DeliveryOrder::query()->pluck('id')->all())->toBe([$keep->id])
        ->and(SalesActual::query()->pluck('id')->sort()->values()->all())->toBe($keptSaIds)
        ->and(SalesActualItem::query()->whereIn('sales_actual_id', $duplicateSaIds)->exists())->toBeFalse()
        ->and(DB::table('delivery_order_purchase_orders')->where('delivery_order_id', $duplicate->id)->exists())->toBeFalse()
        ->and(DB::table('delivery_order_purchase_orders')->where('delivery_order_id', $keep->id)->count())->toBe(2)
        ->and(DB::table('proof_of_delivery')->where('delivery_order_id', $duplicate->id)->exists())->toBeFalse()
        ->and(DB::table('proof_of_delivery')->where('delivery_order_id', $keep->id)->count())->toBe(1);

    expect(laporanPenjualan14Nov())->toMatchArray(['total_orders' => 2, 'grand_total' => 600000.0]);

    // Isi yang dihapus tersimpan di audit log.
    $deletedSa = AuditLog::query()->where('action', 'sales_actual_duplicate_deleted')->get();
    expect($deletedSa)->toHaveCount(2)
        ->and($deletedSa->pluck('entity_id')->sort()->values()->all())->toBe($duplicateSaIds)
        ->and($deletedSa->first()->before_json['items'])->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'deleted_duplicate')->sole()->before_json['purchase_order_ids'])->toHaveCount(2);

    artisan('delivery:hapus-do-ganda')
        ->expectsOutputToContain('Tidak ada PO yang ada di lebih dari satu DO.')
        ->assertSuccessful();
});

it('menolak menghapus DO yang tidak dobel, DO yang Sales Actual-nya sudah closing, dan DO beserta kembarannya', function () {
    [$keep, $duplicate] = buatDoGanda($this);
    $single = DeliveryOrder::query()->create([
        'do_code' => 'DO-20261114-0009', 'area_id' => $this->area->id, 'scheduled_at' => '2026-11-14 15:00:00',
        'driver_user_id' => $this->admin->id, 'status' => 'delivered',
    ]);
    $single->purchaseOrders()->attach(($this->makePo)('PO-20261114-0009', 'SIANY', 12)->id);

    artisan('delivery:hapus-do-ganda', ['do' => [$single->id], '--execute' => true])
        ->expectsOutputToContain('bukan dobel')
        ->assertFailed();

    artisan('delivery:hapus-do-ganda', ['do' => [$keep->id, $duplicate->id], '--execute' => true])
        ->expectsOutputToContain('sebutkan satu saja')
        ->assertFailed();

    // Isi berbeda dengan kembarannya: pengguna harus memilih sendiri mana yang benar.
    $differentItem = $duplicate->salesActuals()->first()->items()->first();
    $differentItem->forceFill(['qty_actual' => 30, 'qty_return' => 2])->save();

    artisan('delivery:hapus-do-ganda', ['do' => [$duplicate->id], '--execute' => true])
        ->expectsOutputToContain('tidak punya kembaran dengan isi sama')
        ->assertFailed();

    $differentItem->forceFill(['qty_actual' => 32, 'qty_return' => 0])->save();

    $closingId = DB::table('sales_daily_closings')->insertGetId([
        'closing_date' => '2026-11-14', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $duplicate->salesActuals()->first()->forceFill(['sales_daily_closing_id' => $closingId])->save();

    artisan('delivery:hapus-do-ganda', ['do' => [$duplicate->id], '--execute' => true])
        ->expectsOutputToContain('sudah masuk closing harian')
        ->assertFailed();

    expect(DeliveryOrder::query()->count())->toBe(3)
        ->and(SalesActual::query()->count())->toBe(4);

    // Kontrol positif: begitu closing dilepas, DO ganda yang sama bisa dihapus.
    $duplicate->salesActuals()->update(['sales_daily_closing_id' => null]);

    artisan('delivery:hapus-do-ganda', ['do' => [$duplicate->id], '--execute' => true])->assertSuccessful();

    expect(DeliveryOrder::query()->find($duplicate->id))->toBeNull()
        ->and(SalesActual::query()->count())->toBe(2);
});
