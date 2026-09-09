<?php

use App\Filament\Resources\InventoryUnitConversionResource\Pages\CreateInventoryUnitConversion;
use App\Filament\Resources\InventoryUnitConversionResource\Pages\MissingUnitConversions;
use App\Models\InventoryItem;
use App\Models\InventoryUnitConversion;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Menu aturan konversi satuan.
 *
 * Aturan ini diisi manusia dan langsung memengaruhi HPP, jadi yang dijaga di
 * sini adalah pagar formnya: aturan kembar dan aturan yang tidak mengubah apa
 * pun harus ditolak sebelum tersimpan, bukan ditemukan belakangan lewat angka
 * HPP yang aneh.
 */
beforeEach(function () {
    Role::findOrCreate('accounting', 'web');

    $this->user = User::factory()->create([
        'is_active' => true,
        'force_password_change' => false,
    ]);

    $this->user->assignRole('accounting');
    $this->actingAs($this->user);

    $bucket = InventoryItem::query()->create([
        'name' => 'Bahan Baku',
        'unit' => 'All',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'is_active' => true,
    ]);

    $this->ayam = InventoryItem::query()->create([
        'parent_id' => $bucket->id,
        'name' => 'Ayam Fillet',
        'unit' => 'pcs',
        'category' => InventoryItem::CATEGORY_RAW_MATERIAL,
        'unit_price' => 15000,
        'is_active' => true,
    ]);
});

it('menyimpan aturan konversi beserta jejak pembuatnya', function () {
    Livewire::test(CreateInventoryUnitConversion::class)
        ->fillForm([
            'inventory_item_id' => $this->ayam->id,
            'from_unit' => 'pcs',
            'to_unit' => 'gram',
            'factor' => 250,
            'note' => 'Hasil timbang dapur',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = InventoryUnitConversion::query()->firstWhere('inventory_item_id', $this->ayam->id);

    expect($rule)->not->toBeNull()
        ->and((float) $rule->factor)->toBe(250.0)
        ->and($rule->created_by)->toBe($this->user->id)
        ->and($rule->summary())->toBe('1 pcs = 250 gram');
});

it('menolak aturan kedua untuk pasangan satuan yang sama', function () {
    InventoryUnitConversion::query()->create([
        'inventory_item_id' => $this->ayam->id,
        'from_unit' => 'pcs',
        'to_unit' => 'gram',
        'factor' => 250,
    ]);

    Livewire::test(CreateInventoryUnitConversion::class)
        ->fillForm([
            'inventory_item_id' => $this->ayam->id,
            'from_unit' => 'pcs',
            'to_unit' => 'gram',
            'factor' => 300,
        ])
        ->call('create')
        ->assertHasFormErrors(['to_unit']);

    // Kontrol positif: pasangan satuan lain pada bahan yang sama tetap diterima.
    Livewire::test(CreateInventoryUnitConversion::class)
        ->fillForm([
            'inventory_item_id' => $this->ayam->id,
            'from_unit' => 'pcs',
            'to_unit' => 'ml',
            'factor' => 240,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InventoryUnitConversion::query()->count())->toBe(2);
});

it('menolak aturan yang satuan asal dan tujuannya sama', function () {
    Livewire::test(CreateInventoryUnitConversion::class)
        ->fillForm([
            'inventory_item_id' => $this->ayam->id,
            'from_unit' => 'gram',
            'to_unit' => 'gram',
            'factor' => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['to_unit']);

    expect(InventoryUnitConversion::query()->count())->toBe(0);
});

it('menolak aturan kembar pada aturan umum yang tidak terikat bahan', function () {
    InventoryUnitConversion::query()->create([
        'inventory_item_id' => null,
        'from_unit' => 'butir',
        'to_unit' => 'gram',
        'factor' => 60,
    ]);

    // Keunikan aturan umum tidak bisa diandalkan pada indeks basis data: MySQL
    // menganggap dua NULL selalu berbeda, jadi pagarnya harus ada di aplikasi.
    Livewire::test(CreateInventoryUnitConversion::class)
        ->fillForm([
            'from_unit' => 'butir',
            'to_unit' => 'gram',
            'factor' => 70,
        ])
        ->call('create')
        ->assertHasFormErrors(['to_unit']);

    expect(InventoryUnitConversion::query()->count())->toBe(1);
});

it('menampilkan pasangan satuan yang tertahan pada halaman butuh aturan', function () {
    $resep = Recipe::query()->create([
        'name' => 'Ayam Bakar',
        'jenis' => Recipe::JENIS_UTAMA,
        'yield_qty' => 1,
        'yield_unit' => 'porsi',
        'ohc_pct' => 0.40,
        'profit_pct' => 0.25,
    ]);

    RecipeItem::query()->create([
        'recipe_id' => $resep->id,
        'inventory_item_id' => $this->ayam->id,
        'raw_name' => 'ayam fillet',
        'qty' => 1000,
        'unit' => 'gr',
    ]);

    Livewire::test(MissingUnitConversions::class)
        ->assertSee('Ayam Fillet')
        ->assertSee('Buat aturan');

    InventoryUnitConversion::query()->create([
        'inventory_item_id' => $this->ayam->id,
        'from_unit' => 'pcs',
        'to_unit' => 'gram',
        'factor' => 250,
    ]);

    // Setelah aturannya ada, pasangan itu hilang dari daftar.
    Livewire::test(MissingUnitConversions::class)
        ->assertDontSee('Buat aturan');
});

it('mengisi awal form aturan dari tautan halaman butuh aturan', function () {
    // Satuan datang apa adanya dari data resep ("gr"), sementara daftar pilihan
    // form memakai nilai bakunya ("gram"). Yang diuji justru penyamaan itu:
    // tanpanya kolom satuan tampil kosong dan pengguna mengisi ulang dari nol.
    Livewire::withQueryParams([
        'inventory_item_id' => $this->ayam->id,
        'from_unit' => 'gr',
        'to_unit' => 'pcs',
    ])
        ->test(CreateInventoryUnitConversion::class)
        ->assertFormSet([
            'inventory_item_id' => $this->ayam->id,
            'from_unit' => 'gram',
            'to_unit' => 'pcs',
        ])
        // Tanpa usulan angka, tidak ada peringatan apa pun.
        ->assertNotNotified();
});

it('memperingatkan bahwa faktor usulan berasal dari nama bahan', function () {
    Livewire::withQueryParams([
        'inventory_item_id' => $this->ayam->id,
        'from_unit' => 'pack',
        'to_unit' => 'gr',
        'factor' => 500,
    ])
        ->test(CreateInventoryUnitConversion::class)
        ->assertFormSet(['factor' => 500.0])
        // Angka usulan ikut menentukan HPP, jadi peringatannya harus muncul di
        // halaman tempat orang menekan simpan, bukan hanya di halaman daftar.
        ->assertNotified('Angka diisi dari nama bahan');
});

it('tidak mengisi apa pun ketika form aturan dibuka tanpa tautan', function () {
    Livewire::test(CreateInventoryUnitConversion::class)
        ->assertFormSet([
            'inventory_item_id' => null,
            'from_unit' => null,
            'to_unit' => null,
        ]);
});
