<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\SalesActualService;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

it('marks part of a carry forward item as waste and excludes it from carry forward', function () {
    Role::findOrCreate('sales', 'web');

    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('sales');

    $area = Area::query()->create(['name' => 'Area Waste', 'code' => 'WST']);

    $customer = Customer::query()->create([
        'name' => 'Customer Waste',
        'area_id' => $area->id,
    ]);

    $product = Product::query()->create([
        'name' => 'Roti Waste',
        'sku' => 'ROTI-WST',
        'unit' => 'pcs',
        'base_price' => 10000,
        'active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-WASTE-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Waste',
        'shipping_address' => 'Jl. Waste',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '08:00:00',
        'payment_type' => 'cash',
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 5,
        'unit' => 'pcs',
        'unit_price' => 10000,
        'subtotal' => 50000,
        'raw_material_cost' => 4000,
        'overhead_cost' => 1000,
    ]);

    $deliveryOrder = DeliveryOrder::query()->create([
        'do_code' => 'DO-WASTE-001',
        'area_id' => $area->id,
        'scheduled_at' => now(),
        'driver_user_id' => $user->id,
        'status' => 'delivered',
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder);

    actingAs($user);

    $service = app(SalesActualService::class);
    $salesActual = $service->createDraftFromDeliveryOrder($deliveryOrder)->first();
    $item = $salesActual->items->first();

    // Hari H-1: 5 dikirim, 3 terjual, 2 retur -> carry forward.
    $service->updateActualItems($salesActual, [
        $item->id => ['qty_actual' => 3],
    ], 'Retur 2 untuk besok');
    $service->submit($salesActual->fresh());

    $carryForwardItem = SalesActualItem::query()->where('is_carry_forward', true)->firstOrFail();
    expect((float) $carryForwardItem->qty_delivery)->toBe(2.0);

    // Hari H: dari 2 carry forward, 1 terjual, 1 ternyata tidak layak jual -> waste.
    $carryForwardActual = $carryForwardItem->salesActual;
    $service->updateActualItems($carryForwardActual, [
        $carryForwardItem->id => [
            'qty_actual' => 1,
            'qty_waste' => 1,
        ],
    ]);

    $carryForwardItem->refresh();

    expect((float) $carryForwardItem->qty_actual)->toBe(1.0)
        ->and((float) $carryForwardItem->qty_waste)->toBe(1.0)
        ->and((float) $carryForwardItem->qty_return)->toBe(0.0)
        ->and((float) $carryForwardItem->subtotal_actual)->toBe(10000.0);

    $service->submit($carryForwardActual->fresh());

    // Waste tidak dibawa ke draft berikutnya (qty_return = 0 -> tidak ada carry forward baru).
    expect(SalesActualItem::query()->where('source_sales_actual_item_id', $carryForwardItem->id)->count())->toBe(0);

    // Laporan Waste menampilkan item beserta nilai cost & harga jual.
    $response = $this->get(route('salesapp.reports.waste', [
        'date_from' => $carryForwardActual->sales_date->toDateString(),
        'date_to' => $carryForwardActual->sales_date->toDateString(),
    ]));

    $response->assertOk();
    $response->assertViewHas('totalWasteQty', 1.0);
    $response->assertViewHas('totalWasteCost', 5000.0);     // 1 x (4000 + 1000)
    $response->assertViewHas('totalWasteSelling', 10000.0); // 1 x 10000

    // Export Excel & PDF mengikuti filter yang sama.
    $exportParams = [
        'date_from' => $carryForwardActual->sales_date->toDateString(),
        'date_to' => $carryForwardActual->sales_date->toDateString(),
    ];

    $this->get(route('salesapp.reports.waste.export.excel', $exportParams))->assertOk();
    $this->get(route('salesapp.reports.waste.export.pdf', $exportParams))->assertOk();
});

it('rejects qty actual plus waste exceeding qty delivery', function () {
    Role::findOrCreate('sales', 'web');

    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('sales');

    $area = Area::query()->create(['name' => 'Area Waste 2', 'code' => 'WS2']);
    $customer = Customer::query()->create(['name' => 'Customer Waste 2', 'area_id' => $area->id]);
    $product = Product::query()->create([
        'name' => 'Roti Waste 2',
        'sku' => 'ROTI-WST2',
        'unit' => 'pcs',
        'base_price' => 10000,
        'active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-WASTE-002',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Waste 2',
        'shipping_address' => 'Jl. Waste 2',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '08:00:00',
        'payment_type' => 'cash',
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 4,
        'unit' => 'pcs',
        'unit_price' => 10000,
        'subtotal' => 40000,
    ]);

    $deliveryOrder = DeliveryOrder::query()->create([
        'do_code' => 'DO-WASTE-002',
        'area_id' => $area->id,
        'scheduled_at' => now(),
        'driver_user_id' => $user->id,
        'status' => 'delivered',
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder);

    actingAs($user);

    $service = app(SalesActualService::class);
    $salesActual = $service->createDraftFromDeliveryOrder($deliveryOrder)->first();
    $item = $salesActual->items->first();

    $service->updateActualItems($salesActual, [
        $item->id => ['qty_actual' => 2],
    ], 'Retur 2');
    $service->submit($salesActual->fresh());

    $carryForwardItem = SalesActualItem::query()->where('is_carry_forward', true)->firstOrFail();
    $carryForwardActual = $carryForwardItem->salesActual;

    // qty_delivery carry forward = 2, actual 2 + waste 1 = 3 > 2 -> ditolak.
    expect(fn () => $service->updateActualItems($carryForwardActual, [
        $carryForwardItem->id => [
            'qty_actual' => 2,
            'qty_waste' => 1,
        ],
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
