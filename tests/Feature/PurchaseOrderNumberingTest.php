<?php

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\Permission\Models\Role;

/**
 * Nomor PO diambil dari nomor terbesar hari ini (+1), jadi dua admin yang
 * menyimpan di detik yang sama bisa mendapat nomor yang sama (stress test:
 * 14 dari 340 simpan bersamaan gagal 500). PO kini disimpan dalam satu
 * transaksi dan diulang dengan nomor baru bila index unik menolaknya.
 */
function formPoBaru(): array
{
    $area = Area::query()->create(['name' => 'Area PO', 'code' => 'APO']);
    $customer = Customer::query()->create(['name' => 'Pelanggan PO', 'area_id' => $area->id, 'active' => true]);
    $cash = CashAccount::query()->create(['name' => 'Kas PO', 'type' => 'cash', 'is_active' => true]);
    $nasi = Product::query()->create(['name' => 'Nasi Box', 'unit' => 'porsi', 'base_price' => 20000, 'active' => true]);
    $teh = Product::query()->create(['name' => 'Es Teh', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]);

    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $admin->assignRole('admin');

    return [$admin, [
        'customer_id' => $customer->id, 'recipient_name' => 'Bu Rina', 'shipping_address' => 'Jl. Mawar 1',
        'area_id' => $area->id, 'delivery_date' => '2026-10-02', 'delivery_time' => '09:00',
        'payment_type' => 'cash', 'cash_account_id' => $cash->id, 'shipping_cost' => 10000,
        'items' => [
            ['product_id' => $nasi->id, 'qty' => 3],
            ['product_id' => $teh->id, 'qty' => 2],
        ],
    ]];
}

function nomorKembar(): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException(
        'sqlite', 'insert into "purchase_orders"', [],
        new PDOException('UNIQUE constraint failed: purchase_orders.po_number'),
    );
}

it('membuat PO lengkap dengan nomor urut, item, total, dan audit log', function () {
    [$admin, $form] = formPoBaru();

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form)
        ->assertRedirect(route('adminapp.orders.index'));

    $po = PurchaseOrder::query()->sole();

    expect($po->po_number)->toBe('PO-'.now()->format('Ymd').'-0001')
        ->and($po->items()->count())->toBe(2)
        ->and((int) $po->total_qty)->toBe(5)
        ->and((float) $po->total_amount)->toBe(3 * 20000.0 + 2 * 5000.0 + 10000.0)
        ->and(AuditLog::query()->where('entity_id', $po->id)->where('action', 'created')->count())->toBe(1);
});

it('mengulang dengan nomor baru saat nomor PO keburu dipakai admin lain, tanpa item ganda', function () {
    [$admin, $form] = formPoBaru();

    // Penyimpanan pertama ditolak index unik (admin lain menyimpan nomor yang sama).
    $percobaan = 0;
    PurchaseOrder::creating(function () use (&$percobaan) {
        if (++$percobaan === 1) {
            throw nomorKembar();
        }
    });

    $this->actingAs($admin)->post(route('adminapp.orders.store'), $form)
        ->assertRedirect(route('adminapp.orders.index'));

    expect($percobaan)->toBe(2)
        ->and(PurchaseOrder::query()->count())->toBe(1)
        // Item dari percobaan pertama ikut dibatalkan, bukan tersimpan dobel.
        ->and(PurchaseOrderItem::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'created')->count())->toBe(1);
});

it('tidak meninggalkan PO setengah jadi bila nomor terus bentrok', function () {
    [$admin, $form] = formPoBaru();

    $percobaan = 0;
    // Header tersimpan, lalu item pertama ditolak -- tiap percobaan.
    PurchaseOrderItem::creating(function () use (&$percobaan) {
        $percobaan++;
        throw nomorKembar();
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->post(route('adminapp.orders.store'), $form))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($percobaan)->toBe(5)
        ->and(PurchaseOrder::query()->count())->toBe(0)
        ->and(PurchaseOrderItem::query()->count())->toBe(0);
});
