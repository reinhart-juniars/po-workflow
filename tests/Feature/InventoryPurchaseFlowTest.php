<?php

use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\put;

/**
 * Alur uang pembelian bahan baku.
 *
 * Pembelian tidak berdiri sendiri: yang tunai membentuk CashOut, yang kredit
 * membentuk Payable, dan berpindah jenis pembayaran harus melepas pasangan
 * yang lama. Tanpa itu uang tercatat dua kali atau hilang sama sekali.
 */
beforeEach(function () {
    Role::findOrCreate('accounting', 'web');
    Role::findOrCreate('owner', 'web');

    $this->user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->user->assignRole('owner');
    actingAs($this->user);

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
});

function buatPembelianTunai(): InventoryPurchase
{
    return InventoryPurchase::query()->create([
        'inventory_item_id' => test()->item->id,
        'transaction_date' => '2026-03-10',
        'qty' => 1,
        'unit_cost' => 100000,
        'total_value' => 100000,
        'payment_type' => 'cash',
    ]);
}

it('membentuk cash out saat pembelian tunai disimpan', function () {
    $purchase = buatPembelianTunai();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 150000,
        'payment_type' => 'cash',
        'expense_category_id' => $this->category->id,
        'cash_account_id' => $this->cashAccount->id,
        'notes' => 'Pembelian mingguan',
    ])->assertRedirect(route('accountingapp.inventory-purchases.index'));

    $purchase->refresh();
    $cashOut = CashOut::query()->find($purchase->cash_out_id);

    expect($cashOut)->not->toBeNull()
        ->and((float) $cashOut->amount)->toBe(150000.0)
        ->and($cashOut->cash_account_id)->toBe($this->cashAccount->id)
        ->and((float) $purchase->total_value)->toBe(150000.0)
        ->and($purchase->payable_id)->toBeNull();
});

it('memindahkan pembelian dari tunai ke kredit dan melepas cash out lamanya', function () {
    $purchase = buatPembelianTunai();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'cash',
        'expense_category_id' => $this->category->id,
        'cash_account_id' => $this->cashAccount->id,
    ])->assertRedirect();

    $cashOutId = $purchase->refresh()->cash_out_id;
    expect($cashOutId)->not->toBeNull();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'payable',
        'supplier_name' => 'CV Sumber Pangan',
        'due_date' => '2026-04-10',
    ])->assertRedirect();

    $purchase->refresh();

    // Cash out lama harus benar-benar hilang, bukan sekadar dilepas kaitannya --
    // kalau tertinggal, uang keluar tercatat dua kali.
    expect(CashOut::query()->whereKey($cashOutId)->exists())->toBeFalse()
        ->and($purchase->cash_out_id)->toBeNull()
        ->and($purchase->payable_id)->not->toBeNull();

    $payable = Payable::query()->find($purchase->payable_id);

    expect((float) $payable->amount)->toBe(100000.0)
        ->and($payable->supplier_name)->toBe('CV Sumber Pangan')
        ->and($payable->status)->toBe('unpaid');
});

it('menolak pembelian tunai tanpa kategori atau akun kas', function () {
    $purchase = buatPembelianTunai();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'cash',
    ])->assertSessionHasErrors(['expense_category_id', 'cash_account_id']);

    // Positive control: dengan keduanya terisi, penyimpanan yang sama berhasil.
    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'cash',
        'expense_category_id' => $this->category->id,
        'cash_account_id' => $this->cashAccount->id,
    ])->assertSessionHasNoErrors();
});

it('menolak pembelian kredit tanpa supplier', function () {
    $purchase = buatPembelianTunai();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'payable',
    ])->assertSessionHasErrors('supplier_name');

    // Positive control: supplier terisi, tersimpan.
    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'payable',
        'supplier_name' => 'CV Sumber Pangan',
    ])->assertSessionHasNoErrors();
});

it('menolak kategori yang bukan bertipe pembelian stok', function () {
    $purchase = buatPembelianTunai();

    $kategoriBeban = ExpenseCategory::query()->create([
        'name' => 'Listrik',
        'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
        'is_active' => true,
    ]);

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'cash',
        'expense_category_id' => $kategoriBeban->id,
        'cash_account_id' => $this->cashAccount->id,
    ])->assertSessionHasErrors('expense_category_id');
});

it('menghapus pembelian kredit beserta hutangnya selama belum dibayar', function () {
    $purchase = buatPembelianTunai();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'payable',
        'supplier_name' => 'CV Sumber Pangan',
    ])->assertRedirect();

    $payableId = $purchase->refresh()->payable_id;

    delete(route('accountingapp.inventory-purchases.destroy', $purchase))
        ->assertRedirect(route('accountingapp.inventory-purchases.index'));

    expect(InventoryPurchase::query()->whereKey($purchase->id)->exists())->toBeFalse()
        ->and(Payable::query()->whereKey($payableId)->exists())->toBeFalse();
});

it('menolak menghapus pembelian kredit yang hutangnya sudah dibayar', function () {
    $purchase = buatPembelianTunai();

    put(route('accountingapp.inventory-purchases.update', $purchase), [
        'inventory_item_id' => $this->item->id,
        'transaction_date' => '2026-03-10',
        'total_cost' => 100000,
        'payment_type' => 'payable',
        'supplier_name' => 'CV Sumber Pangan',
    ])->assertRedirect();

    $purchase->refresh();
    Payable::query()->whereKey($purchase->payable_id)->update(['status' => 'paid']);

    delete(route('accountingapp.inventory-purchases.destroy', $purchase))
        ->assertSessionHasErrors('payment_type');

    expect(InventoryPurchase::query()->whereKey($purchase->id)->exists())->toBeTrue();
});
