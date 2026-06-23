<?php

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\OtherIncome;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\User;
use App\Services\SalesActualService;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

it('creates draft from completed delivery and submits actual with carry forward returns', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);

    $area = Area::query()->create([
        'name' => 'Area Sales Actual',
        'code' => 'SLA',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Sales Actual',
        'phone' => '08123456789',
        'address' => 'Jl. Sales Actual',
        'area_id' => $area->id,
    ]);

    $product = Product::query()->create([
        'name' => 'Paket Nasi',
        'sku' => 'PKT-NASI',
        'unit' => 'box',
        'base_price' => 10000,
        'active' => true,
    ]);

    $repackagedProduct = Product::query()->create([
        'name' => 'Paket Retur',
        'sku' => 'PKT-RETUR',
        'unit' => 'paket',
        'base_price' => 18000,
        'active' => true,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Sales Test',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-SALES-ACTUAL-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Sales Actual',
        'shipping_address' => 'Jl. Sales Actual',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '10:00:00',
        'payment_type' => 'cash',
        'cash_account_id' => $cashAccount->id,
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $purchaseOrderItem = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 5,
        'unit' => 'box',
        'unit_price' => 10000,
        'subtotal' => 50000,
    ]);

    $deliveryOrder = DeliveryOrder::query()->create([
        'do_code' => 'DO-SALES-ACTUAL-001',
        'area_id' => $area->id,
        'scheduled_at' => now(),
        'driver_user_id' => $user->id,
        'status' => 'delivered',
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder);

    $service = app(SalesActualService::class);
    $drafts = $service->createDraftFromDeliveryOrder($deliveryOrder);

    expect($drafts)->toHaveCount(1);

    $salesActual = SalesActual::query()->with('items')->firstOrFail();
    $item = $salesActual->items->first();

    expect($salesActual->status)->toBe('draft')
        ->and($salesActual->delivery_order_id)->toBe($deliveryOrder->id)
        ->and($salesActual->notes)->toBeNull()
        ->and((float) $item->qty_delivery)->toBe(5.0)
        ->and((float) $item->qty_actual)->toBe(5.0)
        ->and($item->purchase_order_item_id)->toBe($purchaseOrderItem->id);

    actingAs($user);

    expect(fn () => $service->updateActualItems($salesActual, [
        $item->id => [
            'qty_actual' => 3,
        ],
    ]))->toThrow(ValidationException::class);

    $service->updateActualItems($salesActual, [
        $item->id => [
            'qty_actual' => 3,
        ],
    ], 'Retur sebagian');

    $service->submit($salesActual->fresh());

    $salesActual->refresh();

    expect($salesActual->status)->toBe('submitted')
        ->and($salesActual->submitted_by)->toBe($user->id);

    expect(OtherIncome::query()->count())->toBe(0);

    $closing = $service->postDailyClosing(now(), now(), 5000, 'Diskon harian');

    expect((float) OtherIncome::query()->sum('amount'))->toBe(25000.0)
        ->and((float) $closing->gross_amount)->toBe(30000.0)
        ->and((float) $closing->discount_amount)->toBe(5000.0)
        ->and((float) $closing->net_amount)->toBe(25000.0);

    expect(OtherIncome::query()->sole()->cash_account_id)->toBe($cashAccount->id)
        ->and(OtherIncome::query()->sole()->source_type)->toBe(OtherIncome::SOURCE_SALES_DAILY_CLOSING)
        ->and(OtherIncome::query()->sole()->source_id)->toBe($closing->id);

    expect($salesActual->fresh()->sales_daily_closing_id)->toBe($closing->id);

    $item->refresh();

    expect((float) $item->qty_return)->toBe(2.0)
        ->and((float) $item->qty_cancel)->toBe(0.0)
        ->and($item->notes)->toBeNull()
        ->and($salesActual->notes)->toBe('Retur sebagian');

    $carryForwardItem = SalesActualItem::query()
        ->where('is_carry_forward', true)
        ->firstOrFail();

    expect((float) $carryForwardItem->qty_delivery)->toBe(2.0)
        ->and((float) $carryForwardItem->qty_actual)->toBe(2.0)
        ->and($carryForwardItem->source_sales_actual_item_id)->toBe($item->id);

    $carryForwardSalesActual = $carryForwardItem->salesActual;

    $service->updateActualItems($carryForwardSalesActual, [
        $carryForwardItem->id => [
            'product_id' => $repackagedProduct->id,
            'qty_actual' => 1,
            'unit_price' => 18000,
        ],
    ], 'Retur dijual ulang sebagai paket baru');

    $carryForwardItem->refresh();

    expect($carryForwardItem->product_id)->toBe($repackagedProduct->id)
        ->and($carryForwardItem->item_name)->toBe('PAKET RETUR')
        ->and($carryForwardItem->unit)->toBe('paket')
        ->and((float) $carryForwardItem->unit_price)->toBe(18000.0)
        ->and((float) $carryForwardItem->qty_actual)->toBe(1.0)
        ->and((float) $carryForwardItem->qty_return)->toBe(1.0)
        ->and((float) $carryForwardItem->subtotal_actual)->toBe(18000.0);

    expect(AuditLog::query()->where('entity', 'sales_actual')->pluck('action')->all())->toContain(
        'sales_actual_draft_created',
        'sales_actual_updated',
        'sales_actual_submitted',
        'sales_actual_carry_forward_created',
    );
    expect(AuditLog::query()->where('entity', 'sales_daily_closing')->pluck('action')->all())->toContain(
        'sales_daily_closing_posted',
    );

    $submitLog = AuditLog::query()
        ->where('entity', 'sales_actual')
        ->where('action', 'sales_actual_submitted')
        ->firstOrFail();

    expect($submitLog->entity_id)->toBe($salesActual->id)
        ->and($submitLog->after_json['status'])->toBe('submitted')
        ->and((float) $submitLog->after_json['total_actual_amount'])->toBe(30000.0)
        ->and((float) $submitLog->after_json['total_return_qty'])->toBe(2.0);

    expect(fn () => $service->updateActualItems($salesActual->fresh(), [
        $item->id => [
            'qty_actual' => 5,
        ],
    ], 'Catatan diganti setelah submit'))->toThrow(ValidationException::class);

    expect($salesActual->fresh()->notes)->toBe('Retur sebagian');
});

it('does not create cash in from submitted sales actual for receivable po', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);

    $area = Area::query()->create([
        'name' => 'Area Receivable Actual',
        'code' => 'SRA',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Receivable Actual',
        'phone' => '081111111111',
        'address' => 'Jl. Piutang',
        'area_id' => $area->id,
    ]);

    $product = Product::query()->create([
        'name' => 'Paket Piutang',
        'sku' => 'PKT-PIUTANG',
        'unit' => 'box',
        'base_price' => 12000,
        'active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-SALES-ACTUAL-RECEIVABLE-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Receivable Actual',
        'shipping_address' => 'Jl. Piutang',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '10:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 14,
        'due_date' => now()->addDays(14)->toDateString(),
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'qty' => 4,
        'unit' => 'box',
        'unit_price' => 12000,
        'subtotal' => 48000,
    ]);

    $deliveryOrder = DeliveryOrder::query()->create([
        'do_code' => 'DO-SALES-ACTUAL-RECEIVABLE-001',
        'area_id' => $area->id,
        'scheduled_at' => now(),
        'driver_user_id' => $user->id,
        'status' => 'delivered',
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder);

    actingAs($user);

    $service = app(SalesActualService::class);
    $salesActual = $service->createDraftFromDeliveryOrder($deliveryOrder)->first();

    $service->submit($salesActual->fresh());

    expect($salesActual->fresh()->status)->toBe('submitted')
        ->and(OtherIncome::query()->count())->toBe(0);
});

it('posts cash po shipping cost as Penjualan Lain-Lain other income at daily closing', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);

    $area = Area::query()->create([
        'name' => 'Area Shipping Cash',
        'code' => 'ASC',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Ongkir Tunai',
        'area_id' => $area->id,
    ]);

    $product = Product::query()->create([
        'name' => 'Paket Ongkir',
        'sku' => 'PKT-ONGKIR',
        'unit' => 'box',
        'base_price' => 10000,
        'active' => true,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Ongkir Tunai',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => 'PO-SHIP-CASH-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Ongkir Tunai',
        'shipping_address' => 'Jl. Ongkir',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'cash_account_id' => $cashAccount->id,
        'shipping_cost' => 7500,
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
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
        'do_code' => 'DO-SHIP-CASH-001',
        'area_id' => $area->id,
        'scheduled_at' => now(),
        'driver_user_id' => $user->id,
        'status' => 'delivered',
    ]);
    $deliveryOrder->purchaseOrders()->attach($purchaseOrder);

    actingAs($user);

    $service = app(SalesActualService::class);
    $salesActual = $service->createDraftFromDeliveryOrder($deliveryOrder)->first();
    $service->submit($salesActual->fresh());

    $closing = $service->postDailyClosing(now(), now(), 0);

    $rows = OtherIncome::query()->with('category:id,name')->orderBy('id')->get();

    expect($rows)->toHaveCount(2);

    $revenueRow = $rows->firstWhere(fn ($row) => $row->category?->name === OtherIncome::CATEGORY_SALES_ACTUAL);
    $shippingRow = $rows->firstWhere(fn ($row) => $row->category?->name === OtherIncome::CATEGORY_OTHER_SALES);

    expect($revenueRow)->not->toBeNull()
        ->and((float) $revenueRow->amount)->toBe(50000.0)
        ->and($shippingRow)->not->toBeNull()
        ->and((float) $shippingRow->amount)->toBe(7500.0)
        ->and($shippingRow->cash_account_id)->toBe($cashAccount->id)
        ->and($shippingRow->source_type)->toBe(OtherIncome::SOURCE_SALES_DAILY_CLOSING)
        ->and($shippingRow->source_id)->toBe($closing->id);
});

it('posts receivable po shipping as Penjualan Lain-Lain at receivable settlement', function () {
    \Spatie\Permission\Models\Role::findOrCreate('accounting', 'web');

    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $area = Area::query()->create([
        'name' => 'Area Receivable Shipping',
        'code' => 'ARS',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Ongkir Piutang',
        'area_id' => $area->id,
    ]);

    $cashAccount = CashAccount::query()->create([
        'name' => 'Kas Ongkir Piutang',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Paket Ongkir Piutang',
        'sku' => 'PKT-ONGKIR-REC',
        'unit' => 'box',
        'base_price' => 25000,
        'active' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'po_number' => 'PO-SHIP-REC-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Ongkir Piutang',
        'shipping_address' => 'Jl. Ongkir Piutang',
        'area_id' => $area->id,
        'delivery_date' => now()->subDays(2)->toDateString(),
        'delivery_time' => '10:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 7,
        'receivable_status' => 'unpaid',
        'due_date' => now()->addDays(5)->toDateString(),
        'shipping_cost' => 12000,
        'total_amount' => 112000,
        'status' => 'completed',
        'completed_at' => now()->subDays(2),
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 4,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'qty' => 4,
        'unit' => 'box',
        'unit_price' => 25000,
        'subtotal' => 100000,
    ]);

    actingAs($user);

    \Pest\Laravel\post(route('accountingapp.periods.complete', $po), [
        'cash_account_id' => $cashAccount->id,
        'cash_received_at' => now()->toDateString(),
    ])->assertRedirect(route('accountingapp.periods.index'));

    $shippingIncome = OtherIncome::query()
        ->where('source_type', OtherIncome::SOURCE_PURCHASE_ORDER_SHIPPING)
        ->where('source_id', $po->id)
        ->first();

    expect($shippingIncome)->not->toBeNull()
        ->and((float) $shippingIncome->amount)->toBe(12000.0)
        ->and($shippingIncome->cash_account_id)->toBe($cashAccount->id)
        ->and($shippingIncome->category?->name)->toBe(OtherIncome::CATEGORY_OTHER_SALES);
});
