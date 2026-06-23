<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('shows the sales report inside accounting app with the same sales report view', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $area = Area::query()->create([
        'name' => 'Area Sales Report',
        'code' => 'ASR',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Sales Report',
        'area_id' => $area->id,
        'is_lapak' => false,
    ]);

    $product = Product::query()->create([
        'name' => 'Paket Accounting Sales',
        'unit' => 'box',
        'base_price' => 25000,
        'active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-ACC-SALES-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Sales Report',
        'shipping_address' => 'Jl. Sales Report',
        'area_id' => $area->id,
        'delivery_date' => '2026-04-22',
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'status' => 'completed',
        'completed_at' => '2026-04-22 10:00:00',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 2,
        'total_amount' => 50000,
    ]);

    $purchaseOrderItem = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 2,
        'unit' => 'box',
        'unit_price' => 25000,
        'subtotal' => 50000,
    ]);

    $deliveryOrder = DeliveryOrder::query()->create([
        'do_code' => 'DO-ACC-SALES-001',
        'area_id' => $area->id,
        'driver_user_id' => $user->id,
        'status' => 'delivered',
        'scheduled_at' => '2026-04-22 09:00:00',
        'created_by' => $user->id,
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder->id);

    $salesActual = SalesActual::query()->create([
        'delivery_order_id' => $deliveryOrder->id,
        'customer_id' => $customer->id,
        'sales_date' => '2026-04-22',
        'status' => 'submitted',
        'submitted_at' => '2026-04-22 12:00:00',
        'submitted_by' => $user->id,
    ]);

    SalesActualItem::query()->create([
        'sales_actual_id' => $salesActual->id,
        'purchase_order_item_id' => $purchaseOrderItem->id,
        'product_id' => $product->id,
        'item_name' => $product->name,
        'unit' => 'box',
        'qty_delivery' => 2,
        'qty_actual' => 2,
        'qty_return' => 0,
        'qty_cancel' => 0,
        'unit_price' => 25000,
        'subtotal_actual' => 50000,
        'is_carry_forward' => false,
    ]);

    actingAs($user);

    get(route('accountingapp.reports.sales', [
        'date_from' => '2026-04-01',
        'date_to' => '2026-04-30',
    ]))
        ->assertOk()
        ->assertViewIs('reports.sales')
        ->assertViewHas('reportRouteName', 'accountingapp.reports.sales')
        ->assertSeeText('Laporan Penjualan')
        ->assertSeeText('Customer Sales Report')
        ->assertSeeText('PO-ACC-SALES-001')
        ->assertSee(route('accountingapp.reports.sales.export.excel', [], false));
});
