<?php

use App\Filament\Menu\Resources\RecipeResource;
use App\Filament\Resources\CustomUnitResource;
use App\Filament\Resources\InventoryItemResource;
use App\Models\CustomUnit;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\IngredientUnitConverter;
use App\Services\UnitConverter;
use App\Support\Units\Unit;
use App\Support\Units\UnitCatalog;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Satuan panjang (cm & meter) bawaan yang terkonversi otomatis, dan Master
 * Satuan di aplikasi Inventory supaya gudang bisa menambah satuan hitung
 * sendiri (roll, karung, ...) tanpa menunggu pengembang sistem.
 */
function penggunaSatuan(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function itemBersatuan(string $name, string $unit): InventoryItem
{
    return InventoryItem::query()->create([
        'name' => $name, 'unit' => $unit, 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true,
    ]);
}

it('mengonversi sentimeter dan meter seperti gram dan kilogram', function () {
    $converter = app(UnitConverter::class);

    expect($converter->convert(1, Unit::Meter, Unit::Sentimeter))->toBe(100.0)
        ->and($converter->convert(250, Unit::Sentimeter, Unit::Meter))->toBe(2.5)
        // 50 cm plastik, harga Rp 20.000 per meter -> Rp 10.000.
        ->and($converter->lineCost(50, Unit::Sentimeter, 20000, Unit::Meter))->toBe(10000.0);

    // Panjang tidak bisa jadi berat; kontrol positif di atas membuktikan ini pagar besaran, bukan konverter rusak.
    expect(fn () => $converter->convert(1, Unit::Meter, Unit::Kilogram))->toThrow(RuntimeException::class);

    expect(Unit::tryFromAlias('cm'))->toBe(Unit::Sentimeter)
        ->and(Unit::tryFromAlias('Senti'))->toBe(Unit::Sentimeter)
        ->and(Unit::tryFromAlias('m'))->toBe(Unit::Meter)
        ->and(Unit::tryFromAlias('MTR'))->toBe(Unit::Meter)
        ->and(Unit::groupedOptions()['Panjang'])->toBe(['cm' => 'Sentimeter (cm)', 'meter' => 'Meter (m)']);
});

it('menghitung baris resep dalam cm terhadap bahan berharga per meter, dan ejaan m disimpan baku', function () {
    $item = itemBersatuan('Plastik Wrap', 'm');

    expect($item->refresh()->unit)->toBe('meter')
        ->and(app(IngredientUnitConverter::class)->convert(30, 'cm', $item->unit, $item->id))->toBe(0.3);
});

it('membiarkan gudang menambah satuan sendiri dan langsung muncul di pilihan satuan', function () {
    $this->actingAs(penggunaSatuan('inventory'));

    $this->get(CustomUnitResource::getUrl('index'))->assertOk()
        ->assertSee('Master Satuan')
        ->assertSee('cm, meter');

    Livewire::test(CustomUnitResource\Pages\CreateCustomUnit::class)
        ->fillForm(['name' => '  Roll ', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CustomUnit::query()->pluck('name')->all())->toBe(['roll']);

    // Muncul di Item Inventaris & resep, di kelompok Satuan Tambahan, bukan "Satuan Lama".
    $itemOptions = InventoryItemResource::unitOptions('roll');
    expect($itemOptions[UnitCatalog::CUSTOM_GROUP])->toBe(['roll' => 'roll'])
        ->and($itemOptions)->not->toHaveKey('Satuan Lama')
        ->and(RecipeResource::unitOptions('roll')[UnitCatalog::CUSTOM_GROUP])->toBe(['roll' => 'roll']);

    // Kontrol: satuan yang benar-benar tidak terdaftar tetap masuk Satuan Lama.
    expect(InventoryItemResource::unitOptions('unit')['Satuan Lama'])->toBe(['unit' => 'unit']);

    // Bisa dipakai untuk menyimpan item.
    Livewire::test(InventoryItemResource\Pages\CreateInventoryItem::class)
        ->fillForm(['name' => 'Aluminium Foil', 'unit' => 'roll', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InventoryItem::query()->latest('id')->value('unit'))->toBe('roll');
});

it('menolak satuan yang sudah ada bawaan atau sudah terdaftar', function (string $input) {
    $this->actingAs(penggunaSatuan('inventory'));
    CustomUnit::query()->create(['name' => 'roll']);

    Livewire::test(CustomUnitResource\Pages\CreateCustomUnit::class)
        ->fillForm(['name' => $input, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(CustomUnit::query()->count())->toBe(1);

    // Kontrol positif: nama baru yang sah tetap diterima di form yang sama.
    Livewire::test(CustomUnitResource\Pages\CreateCustomUnit::class)
        ->fillForm(['name' => 'karung', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CustomUnit::query()->count())->toBe(2);
})->with([
    'meter (alias m)' => 'm',
    'cm' => 'CM',
    'kg' => 'Kg',
    'duplikat beda huruf' => 'ROLL',
    'karakter aneh' => '<b>x</b>',
]);

it('menyembunyikan satuan nonaktif dari pilihan baru tanpa merusak item lama', function () {
    $unit = CustomUnit::query()->create(['name' => 'karung']);
    itemBersatuan('Beras', 'karung');

    expect(UnitCatalog::groupedOptions()[UnitCatalog::CUSTOM_GROUP])->toBe(['karung' => 'karung']);

    $unit->update(['is_active' => false]);

    expect(UnitCatalog::groupedOptions())->not->toHaveKey(UnitCatalog::CUSTOM_GROUP)
        // Item lama yang memakainya tetap bisa disunting dengan satuannya.
        ->and(InventoryItemResource::unitOptions('karung')['Satuan Lama'])->toBe(['karung' => 'karung']);
});

it('mengunci nama dan tombol hapus satuan yang sudah dipakai', function () {
    $this->actingAs(penggunaSatuan('inventory'));
    $dipakai = CustomUnit::query()->create(['name' => 'roll']);
    $kosong = CustomUnit::query()->create(['name' => 'gulung']);
    itemBersatuan('Plastik', 'Roll');

    expect($dipakai->usageCount())->toBe(1)->and($kosong->usageCount())->toBe(0);

    Livewire::test(CustomUnitResource\Pages\EditCustomUnit::class, ['record' => $dipakai->getKey()])
        ->assertFormFieldIsDisabled('name')
        ->assertActionHidden('delete')
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($dipakai->refresh())->name->toBe('roll')->is_active->toBeFalse();

    // Kontrol positif: satuan yang belum dipakai bisa diganti nama dan dihapus.
    Livewire::test(CustomUnitResource\Pages\EditCustomUnit::class, ['record' => $kosong->getKey()])
        ->assertFormFieldIsEnabled('name')
        ->fillForm(['name' => 'Gulungan'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('delete');

    expect(CustomUnit::query()->pluck('name')->all())->toBe(['roll']);
});

it('membatasi Master Satuan: lihat butuh inventory.view, tambah butuh inventory.manage', function () {
    foreach (['sales', 'menu', 'marketing'] as $role) {
        $this->actingAs(penggunaSatuan($role))->get(CustomUnitResource::getUrl('index'))->assertForbidden();
    }

    // Produksi hanya boleh melihat.
    $this->actingAs(penggunaSatuan('production'))->get(CustomUnitResource::getUrl('index'))->assertOk();
    $this->get(CustomUnitResource::getUrl('create'))->assertForbidden();

    // Kontrol positif: gudang & owner boleh menambah.
    foreach (['inventory', 'owner'] as $role) {
        $this->actingAs(penggunaSatuan($role))->get(CustomUnitResource::getUrl('create'))->assertOk();
    }
});
