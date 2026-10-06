<?php

use App\Models\Area;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\LeftoverBreakdown;
use App\Models\LeftoverComponent;
use App\Models\OtherIncome;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\LeftoverStockService;
use App\Services\SalesActualService;
use App\Support\Settings\Settings;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * Adendum revisi Owner: Barang Sisa dirinci per komponen yang diketik bebas
 * (nasi goreng yang sudah digoreng tidak bisa kembali ke resep), nilainya
 * diketik sendiri dengan acuan HPP, lalu tiap komponen bisa dijual sesuai
 * yang diambil atau dibuang (waste).
 */

/**
 * Customer A meretur 3 Nasi Goreng (HPP 5.000) kemarin. Customer B punya DO
 * hari ini dengan PO tunai, draft Sales Actual-nya sudah dibuat.
 *
 * @return array<string, mixed>
 */
function komponenFixture(): array
{
    Role::findOrCreate('sales', 'web');
    app(Settings::class)->set('leftover.accounting_start', now()->subMonth()->toDateString());

    $sales = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $sales->assignRole('sales');

    $area = Area::query()->create(['name' => 'Area Komponen', 'code' => 'KMP']);
    $customerA = Customer::query()->create(['name' => 'Customer Retur', 'area_id' => $area->id]);
    $customerB = Customer::query()->create(['name' => 'Customer Komponen', 'area_id' => $area->id]);
    $product = Product::query()->create([
        'name' => 'Nasi Goreng', 'sku' => 'NG-KMP', 'unit' => 'porsi', 'base_price' => 15000,
        'raw_material_cost' => 5000, 'overhead_cost' => 2000, 'active' => true,
    ]);

    $asal = SalesActual::query()->create([
        'customer_id' => $customerA->id, 'sales_date' => now()->subDay()->toDateString(),
        'status' => 'submitted', 'submitted_at' => now()->subDay(), 'submitted_by' => $sales->id, 'notes' => 'Retur 3',
    ]);
    $entry = SalesActualItem::query()->create([
        'sales_actual_id' => $asal->id, 'product_id' => $product->id, 'item_name' => 'NASI GORENG', 'unit' => 'porsi',
        'qty_delivery' => 10, 'qty_actual' => 7, 'unit_price' => 15000, 'raw_material_cost' => 5000, 'overhead_cost' => 2000,
    ]);

    $cashB = CashAccount::query()->create(['name' => 'Kas Komponen B', 'type' => 'cash', 'is_active' => true]);
    $poB = PurchaseOrder::query()->create([
        'po_number' => 'PO-KMP-B', 'customer_id' => $customerB->id, 'recipient_name' => 'B', 'shipping_address' => 'Jl. B',
        'area_id' => $area->id, 'delivery_date' => now()->toDateString(), 'delivery_time' => '09:00:00',
        'payment_type' => 'cash', 'cash_account_id' => $cashB->id, 'status' => 'completed', 'completed_at' => now(),
        'created_by' => $sales->id, 'updated_by' => $sales->id,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poB->id, 'product_id' => $product->id, 'qty' => 4, 'unit' => 'porsi', 'unit_price' => 15000, 'subtotal' => 60000,
    ]);
    $doB = DeliveryOrder::query()->create([
        'do_code' => 'DO-KMP-B', 'area_id' => $area->id, 'scheduled_at' => now(), 'driver_user_id' => $sales->id, 'status' => 'delivered',
    ]);
    $doB->purchaseOrders()->attach($poB);
    $draftB = app(SalesActualService::class)->createDraftFromDeliveryOrder($doB)->first();

    return compact('sales', 'customerB', 'product', 'entry', 'cashB', 'poB', 'draftB');
}

/** Rinci 2 porsi (HPP 10.000) menjadi nasi 400 g Rp 6.000 + telur 2 butir Rp 3.000. */
function rinciDuaPorsi(array $f): void
{
    test()->post(route('salesapp.leftovers.breakdowns.store', $f['entry']), [
        'portion_qty' => 2,
        'broken_at' => now()->toDateString(),
        'notes' => 'nasi dipisah dari telur',
        'components' => [
            ['name' => 'Nasi goreng', 'qty' => 400, 'unit' => 'gram', 'value' => 6000],
            ['name' => 'Telur ceplok', 'qty' => 2, 'unit' => 'butir', 'value' => 3000],
            ['name' => '', 'qty' => '', 'unit' => '', 'value' => ''], // baris kosong diabaikan
        ],
    ])->assertRedirect(route('salesapp.leftovers.index'))->assertSessionHasNoErrors();
}

it('merinci Barang Sisa per komponen dan menjaga nilai persediaan tetap utuh', function () {
    $f = komponenFixture();
    actingAs($f['sales']);
    $leftovers = app(LeftoverStockService::class);

    expect($leftovers->valueAt(now()))->toBe(15000.0);

    rinciDuaPorsi($f);

    $breakdown = LeftoverBreakdown::query()->with('components')->sole();
    expect((float) $breakdown->portion_value)->toBe(10000.0)
        ->and($breakdown->components)->toHaveCount(2)
        ->and($breakdown->unallocatedValue())->toBe(1000.0)
        // 1 porsi utuh tersisa; 2 porsi kini berupa komponen.
        ->and($leftovers->availableQty($f['entry']))->toBe(1.0);

    // Persediaan = 1 porsi x 5.000 + komponen 9.000; sisa 1.000 tidak terinci = waste.
    expect($leftovers->valueAt(now()))->toBe(14000.0)
        ->and($leftovers->wasteValueBetween(now()->startOfMonth(), now()))->toBe(1000.0)
        ->and($leftovers->valueAt(now()) + $leftovers->wasteValueBetween(now()->startOfMonth(), now()))->toBe(15000.0);

    $this->get(route('salesapp.leftovers.index'))
        ->assertOk()
        ->assertSee('Telur ceplok')
        ->assertSee('tidak terinci')
        ->assertViewHas('totalValue', 14000.0);
});

it('menjual komponen sesuai yang diambil dengan cara bayar customer pembeli', function () {
    $f = komponenFixture();
    actingAs($f['sales']);
    rinciDuaPorsi($f);
    $telur = LeftoverComponent::query()->where('name', 'Telur ceplok')->sole();
    $leftovers = app(LeftoverStockService::class);

    $this->post(route('salesapp.leftovers.components.sell', $telur), [
        'sales_actual_id' => $f['draftB']->id,
        'qty' => 2,
        'unit_price' => 2500,
    ])->assertRedirect(route('salesapp.actuals.edit', $f['draftB']));

    $sale = SalesActualItem::query()->where('leftover_component_id', $telur->id)->sole();
    expect($sale->product_id)->toBeNull()
        ->and($sale->item_name)->toBe('Telur ceplok')
        ->and($sale->unit)->toBe('butir')
        ->and((float) $sale->raw_material_cost)->toBe(1500.0)
        ->and($sale->source_sales_actual_item_id)->toBe($f['entry']->id)
        ->and($sale->purchase_order_id)->toBe($f['poB']->id)
        ->and($leftovers->componentAvailableQty($telur))->toBe(0.0)
        // Porsi utuh entri tidak ikut berkurang oleh penjualan komponen.
        ->and($leftovers->availableQty($f['entry']))->toBe(1.0);

    // Edit Sales Actual B: nama komponen tetap (bukan pemilih menu), harga boleh diubah.
    $this->get(route('salesapp.actuals.edit', $f['draftB']))
        ->assertOk()
        ->assertSee('Komponen rincian NASI GORENG');
    app(SalesActualService::class)->updateActualItems($f['draftB'], [
        $sale->id => ['qty_actual' => 2, 'product_id' => $f['product']->id, 'unit_price' => 3000],
    ]);
    expect($sale->fresh()->item_name)->toBe('Telur ceplok')
        ->and($sale->fresh()->product_id)->toBeNull()
        ->and((float) $sale->fresh()->unit_price)->toBe(3000.0);

    app(SalesActualService::class)->submit($f['draftB']->fresh());

    // Persediaan turun sebesar HPP telur (3.000): 14.000 -> 11.000.
    expect($leftovers->valueAt(now()))->toBe(11000.0);

    app(SalesActualService::class)->postDailyClosing(now(), now());
    $income = OtherIncome::query()->where('source_type', OtherIncome::SOURCE_SALES_DAILY_CLOSING)->sole();
    // PO B 4 x 15.000 + telur 2 x 3.000.
    expect($income->cash_account_id)->toBe($f['cashB']->id)
        ->and((float) $income->amount)->toBe(66000.0);
});

it('bisa memilih komponen dari panel Penjualan Barang Sisa di Sales Actual', function () {
    $f = komponenFixture();
    actingAs($f['sales']);
    rinciDuaPorsi($f);
    $nasi = LeftoverComponent::query()->where('name', 'Nasi goreng')->sole();

    // Komponen wajib diberi harga.
    $this->from(route('salesapp.actuals.edit', $f['draftB']))
        ->post(route('salesapp.actuals.leftovers.store', $f['draftB']), [
            'leftover_source' => 'component:'.$nasi->id,
            'leftover_qty' => 100,
        ])->assertSessionHasErrors('leftover_price');

    $this->post(route('salesapp.actuals.leftovers.store', $f['draftB']), [
        'leftover_source' => 'component:'.$nasi->id,
        'leftover_qty' => 100,
        'leftover_price' => 20,
    ])->assertRedirect(route('salesapp.actuals.edit', $f['draftB']));

    // Porsi utuh tetap bisa lewat pilihan yang sama.
    $this->post(route('salesapp.actuals.leftovers.store', $f['draftB']), [
        'leftover_source' => 'entry:'.$f['entry']->id,
        'leftover_qty' => 1,
    ])->assertRedirect(route('salesapp.actuals.edit', $f['draftB']));

    expect(SalesActualItem::query()->where('leftover_component_id', $nasi->id)->value('qty_delivery'))->toEqual('100.00')
        ->and(app(LeftoverStockService::class)->componentAvailableQty($nasi))->toBe(300.0)
        ->and(app(LeftoverStockService::class)->availableQty($f['entry']))->toBe(0.0);
});

it('menolak rincian yang nilainya melebihi HPP atau porsinya melebihi sisa', function () {
    $f = komponenFixture();
    actingAs($f['sales']);
    $payload = fn (float $portion, float $value) => [
        'portion_qty' => $portion,
        'broken_at' => now()->toDateString(),
        'components' => [['name' => 'Nasi', 'qty' => 1, 'unit' => 'porsi', 'value' => $value]],
    ];

    $this->post(route('salesapp.leftovers.breakdowns.store', $f['entry']), $payload(1, 5000.01))
        ->assertSessionHasErrors('components');
    $this->post(route('salesapp.leftovers.breakdowns.store', $f['entry']), $payload(4, 100))
        ->assertSessionHasErrors('portion_qty');
    $this->post(route('salesapp.leftovers.breakdowns.store', $f['entry']), [
        'portion_qty' => 1, 'broken_at' => now()->toDateString(),
        'components' => [['name' => '', 'qty' => '', 'unit' => '', 'value' => '']],
    ])->assertSessionHasErrors('components');
    expect(LeftoverBreakdown::query()->count())->toBe(0);

    // Kontrol positif: nilai tepat sama dengan HPP porsi diterima.
    $this->post(route('salesapp.leftovers.breakdowns.store', $f['entry']), $payload(1, 5000))
        ->assertSessionHasNoErrors();
    expect(LeftoverBreakdown::query()->count())->toBe(1);
});

it('mengubah atau membatalkan rincian selama komponennya belum tersentuh', function () {
    $f = komponenFixture();
    actingAs($f['sales']);
    rinciDuaPorsi($f);
    $breakdown = LeftoverBreakdown::query()->sole();
    $leftovers = app(LeftoverStockService::class);

    $this->put(route('salesapp.leftovers.breakdowns.update', $breakdown), [
        'portion_qty' => 3,
        'components' => [['name' => 'Nasi goreng', 'qty' => 600, 'unit' => 'gram', 'value' => 15000]],
    ])->assertSessionHasNoErrors();

    expect((float) $breakdown->fresh()->portion_qty)->toBe(3.0)
        ->and($breakdown->fresh()->components)->toHaveCount(1)
        ->and($leftovers->availableQty($f['entry']))->toBe(0.0)
        ->and($leftovers->valueAt(now()))->toBe(15000.0);

    $this->delete(route('salesapp.leftovers.breakdowns.destroy', $breakdown))->assertSessionHasNoErrors();
    expect(LeftoverBreakdown::query()->count())->toBe(0)
        ->and($leftovers->availableQty($f['entry']))->toBe(3.0);

    // Setelah ada komponen yang di-waste, rincian terkunci.
    rinciDuaPorsi($f);
    $breakdown = LeftoverBreakdown::query()->sole();
    $nasi = LeftoverComponent::query()->where('name', 'Nasi goreng')->sole();

    $this->post(route('salesapp.leftovers.components.dispose', $nasi), [
        'qty' => 100, 'disposed_at' => now()->toDateString(), 'reason' => 'basi',
    ])->assertSessionHasNoErrors();

    $this->delete(route('salesapp.leftovers.breakdowns.destroy', $breakdown))->assertSessionHasErrors('components');
    $this->put(route('salesapp.leftovers.breakdowns.update', $breakdown), [
        'portion_qty' => 2,
        'components' => [['name' => 'Nasi goreng', 'qty' => 400, 'unit' => 'gram', 'value' => 6000]],
    ])->assertSessionHasErrors('components');

    // Waste komponen: 100 g x Rp 15/g = 1.500, ditambah 1.000 yang tidak terinci.
    expect($leftovers->wasteValueBetween(now()->startOfMonth(), now()))->toBe(2500.0)
        ->and($leftovers->componentAvailableQty($nasi))->toBe(300.0);

    $this->get(route('salesapp.reports.waste', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
        ->assertOk()
        ->assertSee('Nasi goreng (rincian NASI GORENG)')
        ->assertSee('NASI GORENG (selisih rincian)')
        ->assertViewHas('totalWasteCost', 2500.0);

    // Komponen tidak bisa di-waste melebihi sisanya.
    $this->post(route('salesapp.leftovers.components.dispose', $nasi), [
        'qty' => 301, 'disposed_at' => now()->toDateString(), 'reason' => 'basi',
    ])->assertSessionHasErrors('dispose_qty');
});
