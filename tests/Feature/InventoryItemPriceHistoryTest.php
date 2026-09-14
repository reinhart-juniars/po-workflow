<?php

use App\Filament\Resources\InventoryItemResource\Pages\EditInventoryItem;
use App\Imports\InventoryItemsImport;
use App\Models\InventoryItem;
use App\Models\InventoryItemPriceHistory;
use App\Models\User;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Histori harga bahan: setiap perubahan harga lewat panel atau import
 * meninggalkan jejak (siapa, kapan, dari berapa ke berapa), seperti yang
 * dulu dilakukan Master Menu. Tanpa ini, lonjakan HPP tidak bisa dijelaskan
 * dan notifikasi perubahan harga (Bagian B) tidak punya sumber.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['force_password_change' => false, 'is_active' => true]);
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $this->bucket = InventoryItem::query()->create(['name' => 'Bahan Baku', 'unit' => 'All', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
});

it('mencatat harga awal, kenaikan, dan penurunan saat bahan diubah lewat panel', function () {
    $item = InventoryItem::query()->create(['name' => 'Tepung', 'unit' => 'kg', 'parent_id' => $this->bucket->id, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true, 'unit_price' => 12000]);

    expect($item->priceHistories()->count())->toBe(1)
        ->and($item->priceHistories()->first()->action)->toBe(InventoryItemPriceHistory::ACTION_SET_AWAL);

    Livewire::test(EditInventoryItem::class, ['record' => $item->id])
        ->fillForm(['unit_price' => 13500])
        ->call('save')
        ->assertHasNoFormErrors();

    $naik = $item->priceHistories()->first();
    expect($naik->action)->toBe(InventoryItemPriceHistory::ACTION_NAIK)
        ->and((float) $naik->old_unit_price)->toBe(12000.0)
        ->and((float) $naik->new_unit_price)->toBe(13500.0)
        ->and($naik->source)->toBe('panel')
        ->and($naik->created_by)->toBe($this->user->id);

    // Menyimpan tanpa mengubah harga tidak menambah histori.
    Livewire::test(EditInventoryItem::class, ['record' => $item->id])
        ->fillForm(['description' => 'catatan saja'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->priceHistories()->count())->toBe(2);

    $item->update(['unit_price' => 11000]);
    expect($item->priceHistories()->first()->action)->toBe(InventoryItemPriceHistory::ACTION_TURUN_DIPAKSA)
        ->and($item->priceHistories()->count())->toBe(3);
});

it('menandai histori dari import excel dengan sumber import', function () {
    $item = InventoryItem::query()->create(['name' => 'Gula', 'unit' => 'kg', 'parent_id' => $this->bucket->id, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true, 'unit_price' => 15000]);

    $path = tempnam(sys_get_temp_dir(), 'ph_').'.csv';
    file_put_contents($path, "id,nama_item,satuan,kategori,harga_satuan\n{$item->id},Gula,kg,bahan_baku,16000\n");
    Excel::import(new InventoryItemsImport, $path);
    unlink($path);

    $terakhir = $item->priceHistories()->first();
    expect($item->priceHistories()->count())->toBe(2)
        ->and($terakhir->source)->toBe('import')
        ->and((float) $terakhir->new_unit_price)->toBe(16000.0)
        ->and(InventoryItem::$priceChangeSource)->toBe('panel'); // sumber dipulihkan
});

it('tidak mencatat histori di dalam withoutPriceHistory', function () {
    $item = InventoryItem::withoutPriceHistory(fn () => InventoryItem::query()->create([
        'name' => 'Garam', 'unit' => 'kg', 'parent_id' => $this->bucket->id, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true, 'unit_price' => 5000,
    ]));

    expect($item->priceHistories()->count())->toBe(0);

    // Positive control: di luar blok itu pencatatan berjalan lagi.
    $item->update(['unit_price' => 6000]);
    expect($item->priceHistories()->count())->toBe(1);
});

it('menampilkan histori harga di halaman edit bahan', function () {
    $item = InventoryItem::query()->create(['name' => 'Tepung', 'unit' => 'kg', 'parent_id' => $this->bucket->id, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true, 'unit_price' => 12000]);
    $item->update(['unit_price' => 13500]);

    Livewire::test(\App\Filament\Resources\InventoryItemResource\RelationManagers\PriceHistoriesRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => EditInventoryItem::class,
    ])
        ->assertOk()
        ->call('loadTable')
        ->assertCanSeeTableRecords($item->priceHistories()->get())
        ->assertSeeInOrder(['Naik', 'Harga Awal']);

    // Kolom harga & kemasan bisa disunting di panel: harga satuan terhitung dari kemasan.
    Livewire::test(EditInventoryItem::class, ['record' => $item->id])
        ->fillForm(['pack_qty' => 25, 'pack_price' => 350000])
        ->assertFormSet(['unit_price' => 14000.0])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $item->fresh()->unit_price)->toBe(14000.0)
        ->and($item->priceHistories()->first()->action)->toBe(InventoryItemPriceHistory::ACTION_NAIK);
});
