<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

function makeDeliveredPurchaseOrder(): array
{
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('admin');

    $area = Area::query()->create([
        'name' => 'Area Delivered PO',
        'code' => 'DPO',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Delivered PO',
        'phone' => '08123456789',
        'address' => 'Jl. Delivered PO',
        'area_id' => $area->id,
        'active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Menu Delivered',
        'sku' => 'MENU-DELIVERED',
        'unit' => 'box',
        'base_price' => 10000,
        'active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-DELIVERED-LOCK-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Recipient Before',
        'shipping_address' => 'Jl. Delivered PO',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '10:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 7,
        'due_date' => now()->addDays(7)->toDateString(),
        'receivable_status' => 'unpaid',
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 5,
        'total_amount' => 50000,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 5,
        'unit' => 'box',
        'unit_price' => 10000,
        'subtotal' => 50000,
    ]);

    $deliveryOrder = DeliveryOrder::query()->create([
        'do_code' => 'DO-DELIVERED-LOCK-001',
        'area_id' => $area->id,
        'scheduled_at' => now(),
        'driver_user_id' => $user->id,
        'status' => 'delivered',
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder);

    return compact('user', 'area', 'customer', 'product', 'purchaseOrder');
}

it('blocks correction page for purchase orders that already have delivered delivery order', function () {
    ['user' => $user, 'purchaseOrder' => $purchaseOrder] = makeDeliveredPurchaseOrder();

    actingAs($user);

    get(route('adminapp.orders.edit', $purchaseOrder))
        ->assertRedirect(route('adminapp.orders.show', $purchaseOrder))
        ->assertSessionHas('error', 'PO yang sudah selesai produksi atau delivered tidak bisa diubah.');
});

it('blocks update request for purchase orders that already have delivered delivery order', function () {
    [
        'user' => $user,
        'area' => $area,
        'customer' => $customer,
        'product' => $product,
        'purchaseOrder' => $purchaseOrder,
    ] = makeDeliveredPurchaseOrder();

    actingAs($user);

    put(route('adminapp.orders.update', $purchaseOrder), [
        'customer_id' => $customer->id,
        'recipient_name' => 'Recipient After',
        'shipping_address' => 'Alamat berubah',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '11:00',
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'payment_type' => 'receivable',
        'receivable_days' => 14,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'notes' => 'Harus ditolak',
            ],
        ],
        'edit_reason' => 'Percobaan perubahan setelah delivered',
    ])
        ->assertRedirect(route('adminapp.orders.show', $purchaseOrder))
        ->assertSessionHas('error', 'PO yang sudah selesai produksi atau delivered tidak bisa diubah.');

    $purchaseOrder->refresh();

    expect($purchaseOrder->recipient_name)->toBe('Recipient Before')
        ->and((int) $purchaseOrder->items()->sum('qty'))->toBe(5);
});

it('keeps edit action visible for draft orders in PO report but hides completed and delivered actions', function () {
    [
        'user' => $user,
        'area' => $area,
        'customer' => $customer,
        'product' => $product,
    ] = makeDeliveredPurchaseOrder();

    $draftPurchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-DRAFT-ACTION-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Draft Recipient',
        'shipping_address' => 'Jl. Draft',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '12:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 7,
        'status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $draftPurchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 2,
        'unit' => 'box',
        'unit_price' => 10000,
        'subtotal' => 20000,
    ]);

    $completedPurchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-COMPLETED-ACTION-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Completed Recipient',
        'shipping_address' => 'Jl. Completed',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '13:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 7,
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $completedPurchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 3,
        'unit' => 'box',
        'unit_price' => 10000,
        'subtotal' => 30000,
    ]);

    actingAs($user);

    get(route('adminapp.reports.orders'))
        ->assertOk()
        ->assertSee('PO-DRAFT-ACTION-001')
        ->assertSee('Edit PO')
        ->assertSee(route('adminapp.orders.edit', $draftPurchaseOrder), false)
        ->assertSee('PO-DELIVERED-LOCK-001')
        ->assertDontSee(route('adminapp.orders.edit', PurchaseOrder::where('po_number', 'PO-DELIVERED-LOCK-001')->firstOrFail()), false)
        ->assertSee('PO-COMPLETED-ACTION-001')
        ->assertDontSee(route('adminapp.orders.edit', $completedPurchaseOrder), false);
});

it('blocks edit page for completed production purchase orders even before delivery', function () {
    [
        'user' => $user,
        'area' => $area,
        'customer' => $customer,
        'product' => $product,
    ] = makeDeliveredPurchaseOrder();

    $completedPurchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-COMPLETED-LOCK-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Completed Recipient',
        'shipping_address' => 'Jl. Completed',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '13:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 7,
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $completedPurchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 3,
        'unit' => 'box',
        'unit_price' => 10000,
        'subtotal' => 30000,
    ]);

    actingAs($user);

    get(route('adminapp.orders.edit', $completedPurchaseOrder))
        ->assertRedirect(route('adminapp.orders.show', $completedPurchaseOrder))
        ->assertSessionHas('error', 'PO yang sudah selesai produksi atau delivered tidak bisa diubah.');
});
