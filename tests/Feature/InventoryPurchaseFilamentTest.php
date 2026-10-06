<?php

use App\Filament\Resources\InventoryPurchaseResource;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('owner', 'web');

    $this->user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);

    $this->item = InventoryItem::query()->create([
        'name' => 'Tepung Terigu',
        'unit' => 'kg',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $this->category = ExpenseCategory::query()->create([
        'name' => 'Pembelian Bahan Baku',
        'expense_mode' => ExpenseCategory::MODE_INVENTORY_PURCHASE,
        'is_active' => true,
    ]);

    $this->cashAccount = CashAccount::query()->create([
        'name' => 'Kas Besar',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $this->purchase = InventoryPurchase::query()->create([
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 100000,
        'total_value' => 100000,
        'payment_type' => 'cash',
    ]);
});

it('tidak menyediakan pembuatan pembelian dari panel', function () {
    // Pembelian harus lahir dari modul Pengeluaran supaya jurnal kas/hutangnya
    // ikut terbentuk; halaman Create di sini akan melewati jurnal itu.
    expect(InventoryPurchaseResource::canCreate())->toBeFalse()
        ->and(array_keys(InventoryPurchaseResource::getPages()))->toBe(['index', 'edit']);
});

it('menyimpan perubahan pembelian lewat alur jurnal yang sama dengan modul Blade', function () {
    Livewire::test(InventoryPurchaseResource\Pages\EditInventoryPurchase::class, [
        'record' => $this->purchase->getKey(),
    ])
        ->fillForm([
            'inventory_item_id' => $this->item->id,
            'transaction_date' => '2026-03-10',
            'total_cost' => 175000,
            'payment_type' => 'cash',
            'expense_category_id' => $this->category->id,
            'cash_account_id' => $this->cashAccount->id,
            'condition' => InventoryPurchase::CONDITION_GOOD,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->purchase->refresh();
    $cashOut = CashOut::query()->find($this->purchase->cash_out_id);

    expect((float) $this->purchase->total_value)->toBe(175000.0)
        ->and($cashOut)->not->toBeNull()
        ->and((float) $cashOut->amount)->toBe(175000.0);
});

it('melepas cash out saat pembelian diubah menjadi kredit dari panel', function () {
    Livewire::test(InventoryPurchaseResource\Pages\EditInventoryPurchase::class, [
        'record' => $this->purchase->getKey(),
    ])
        ->fillForm([
            'inventory_item_id' => $this->item->id,
            'transaction_date' => '2026-03-10',
            'total_cost' => 100000,
            'payment_type' => 'cash',
            'expense_category_id' => $this->category->id,
            'cash_account_id' => $this->cashAccount->id,
            'condition' => InventoryPurchase::CONDITION_GOOD,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $cashOutId = $this->purchase->refresh()->cash_out_id;
    $supplier = App\Models\Supplier::query()->create(['name' => 'CV Sumber Pangan', 'is_active' => true]);

    Livewire::test(InventoryPurchaseResource\Pages\EditInventoryPurchase::class, [
        'record' => $this->purchase->getKey(),
    ])
        ->fillForm([
            'inventory_item_id' => $this->item->id,
            'transaction_date' => '2026-03-10',
            'total_cost' => 100000,
            'payment_type' => 'payable',
            'supplier_id' => $supplier->id,
            'condition' => InventoryPurchase::CONDITION_GOOD,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->purchase->refresh();

    expect(CashOut::query()->whereKey($cashOutId)->exists())->toBeFalse()
        ->and($this->purchase->cash_out_id)->toBeNull()
        ->and(Payable::query()->whereKey($this->purchase->payable_id)->exists())->toBeTrue()
        ->and($this->purchase->payable->supplier_id)->toBe($supplier->id)
        ->and($this->purchase->payable->supplier_name)->toBe('CV Sumber Pangan');
});

it('menandai barang tidak baik lewat aksi cek kondisi berikut pemeriksanya', function () {
    Livewire::test(InventoryPurchaseResource\Pages\ListInventoryPurchases::class)
        ->callTableAction('cek_kondisi', $this->purchase, [
            'condition' => InventoryPurchase::CONDITION_DAMAGED,
            'condition_notes' => 'Karung basah',
        ]);

    $this->purchase->refresh();

    expect($this->purchase->isDamaged())->toBeTrue()
        ->and($this->purchase->condition_notes)->toBe('Karung basah')
        ->and($this->purchase->condition_checked_by)->toBe($this->user->id)
        ->and($this->purchase->condition_checked_at)->not->toBeNull();

    // Positive control: kondisi bisa dikembalikan ke Baik lewat aksi yang sama,
    // jadi yang teruji benar-benar aksinya, bukan nilai default kolom.
    Livewire::test(InventoryPurchaseResource\Pages\ListInventoryPurchases::class)
        ->callTableAction('cek_kondisi', $this->purchase, [
            'condition' => InventoryPurchase::CONDITION_GOOD,
        ]);

    expect($this->purchase->refresh()->isDamaged())->toBeFalse();
});

it('menyembunyikan tombol hapus dari pengguna selain owner dan superadmin', function () {
    Role::findOrCreate('accounting', 'web');

    $akuntan = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $akuntan->assignRole('accounting');
    $this->actingAs($akuntan);

    Livewire::test(InventoryPurchaseResource\Pages\ListInventoryPurchases::class)
        ->assertTableActionHidden('delete', $this->purchase);

    // Positive control: owner tetap melihat tombolnya.
    $this->actingAs($this->user);

    Livewire::test(InventoryPurchaseResource\Pages\ListInventoryPurchases::class)
        ->assertTableActionVisible('delete', $this->purchase);
});

it('menautkan pembelian ke form kebutuhan yang menjadi alasannya', function () {
    $order = \App\Models\ProductionOrder::query()->create([
        'title' => 'SPK uji', 'production_date' => '2026-03-10', 'status' => \App\Models\ProductionOrder::STATUS_PLANNED,
    ]);
    $requisition = \App\Models\Requisition::query()->create(['production_order_id' => $order->id]);

    Livewire::test(InventoryPurchaseResource\Pages\EditInventoryPurchase::class, [
        'record' => $this->purchase->getKey(),
    ])
        ->fillForm([
            'inventory_item_id' => $this->item->id,
            'transaction_date' => '2026-03-10',
            'total_cost' => 175000,
            'payment_type' => 'cash',
            'expense_category_id' => $this->category->id,
            'cash_account_id' => $this->cashAccount->id,
            'condition' => InventoryPurchase::CONDITION_GOOD,
            'requisition_id' => $requisition->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Tautan ini bukti langkah "Diperiksa saat barang dibeli"; pembeliannya
    // sendiri tetap lahir dari modul Pengeluaran, form hanya alasannya.
    expect($this->purchase->fresh()->requisition_id)->toBe($requisition->id)
        ->and($requisition->purchases()->count())->toBe(1);
});
