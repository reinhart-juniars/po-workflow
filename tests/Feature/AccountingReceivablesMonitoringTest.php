<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
});

it('shows outstanding receivables even when due date is outside the current month by default', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $area = Area::query()->create([
        'name' => 'Area Test',
        'code' => 'ART',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Piutang',
        'area_id' => $area->id,
    ]);

    PurchaseOrder::query()->create([
        'po_number' => 'PO-TEST-PIUTANG-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Piutang',
        'shipping_address' => 'Jl. Test',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '10:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 15,
        'due_date' => now()->endOfMonth()->addDays(10)->toDateString(),
        'cash_received_at' => null,
        'cash_received_by' => null,
        'cash_account_id' => null,
        'receivable_status' => 'unpaid',
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 150000,
    ]);

    actingAs($user);

    get(route('accountingapp.periods.index'))
        ->assertOk()
        ->assertSee('PO-TEST-PIUTANG-001')
        ->assertSeeText('Export Excel')
        ->assertSee(route('accountingapp.periods.export.excel', [], false))
        ->assertSee(route('accountingapp.periods.export.pdf', [], false));
});

it('shows legacy receivables with null receivable status when cash has not been received', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $area = Area::query()->create([
        'name' => 'Area Legacy',
        'code' => 'ARL',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Legacy',
        'area_id' => $area->id,
    ]);

    PurchaseOrder::query()->create([
        'po_number' => 'PO-TEST-LEGACY-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Legacy',
        'shipping_address' => 'Jl. Legacy',
        'area_id' => $area->id,
        'delivery_date' => now()->toDateString(),
        'delivery_time' => '11:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 30,
        'due_date' => now()->addDays(30)->toDateString(),
        'cash_received_at' => null,
        'cash_received_by' => null,
        'cash_account_id' => null,
        'receivable_status' => null,
        'status' => 'completed',
        'completed_at' => now(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 225000,
    ]);

    actingAs($user);

    get(route('accountingapp.periods.index'))
        ->assertOk()
        ->assertSee('PO-TEST-LEGACY-001')
        ->assertSee('Legacy');
});

it('exports receivables monitoring with active filters', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('accounting');

    $area = Area::query()->create([
        'name' => 'Area Export',
        'code' => 'ARE',
    ]);

    $customer = Customer::query()->create([
        'name' => 'Customer Export',
        'area_id' => $area->id,
    ]);

    PurchaseOrder::query()->create([
        'po_number' => 'PO-EXPORT-PIUTANG-001',
        'customer_id' => $customer->id,
        'recipient_name' => 'Customer Export',
        'shipping_address' => 'Jl. Export',
        'area_id' => $area->id,
        'delivery_date' => '2026-04-20',
        'delivery_time' => '11:00:00',
        'payment_type' => 'receivable',
        'receivable_days' => 14,
        'due_date' => '2026-05-04',
        'cash_received_at' => null,
        'cash_received_by' => null,
        'cash_account_id' => null,
        'receivable_status' => 'unpaid',
        'status' => 'completed',
        'completed_at' => '2026-04-20 12:00:00',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'total_qty' => 1,
        'total_amount' => 175000,
    ]);

    actingAs($user);

    get(route('accountingapp.periods.export.pdf', [
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]))->assertOk();
});
