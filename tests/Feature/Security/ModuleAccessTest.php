<?php

use App\Filament\Resources\InventoryItemResource\Pages\CreateInventoryItem;
use App\Filament\Resources\ProductionOrderResource\Pages\RequisitionForm;
use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\User;
use App\Policies\ModulePolicy;
use App\Support\Access\ModuleAccess;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Hak akses modul Inventory Terpadu: satu matriks peran -> izin
 * (ModuleAccess), dibaca policy, dan diuji di sini lewat halaman sungguhan.
 *
 * Tes arsitekturnya membaca daftar resource & page yang benar-benar terdaftar
 * di panel, supaya resource baru yang lupa dibuatkan policy membuat build
 * merah -- bukan diam-diam terbuka untuk semua peran panel.
 */
function penggunaBerperan(string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

/** @return array<class-string, string> model => awalan modul dari policy-nya */
function modulPerResource(): array
{
    $rows = [];

    foreach (Filament::getResources() as $resource) {
        $policy = Gate::getPolicyFor($resource::getModel());

        if ($policy instanceof ModulePolicy) {
            $rows[$resource] = explode('.', $policy->permissions()[0])[0];
        }
    }

    return $rows;
}

it('menjaga setiap resource modul baru dengan policy yang membaca izin modul', function () {
    // Semua resource panel adalah modul inventory/resep/produksi; domain lain
    // (PO, SPK, Produk, Pengguna, ...) hidup di aplikasi Blade.
    $tanpaPolicy = collect(Filament::getResources())
        ->reject(fn (string $resource) => Gate::getPolicyFor($resource::getModel()) instanceof ModulePolicy)
        ->map(fn (string $resource) => $resource::getSlug())
        ->values()
        ->all();

    expect($tanpaPolicy)->toBe([], 'Resource ini belum punya ModulePolicy: '.implode(', ', $tanpaPolicy));

    // Setiap policy menunjuk modul yang memang terdaftar di matriks.
    foreach (modulPerResource() as $resource => $modul) {
        expect(Gate::getPolicyFor($resource::getModel())->isKnownModule())
            ->toBeTrue("Policy {$resource} menunjuk modul '{$modul}' yang tidak ada di ModuleAccess.");
    }

    expect(modulPerResource())->not->toBeEmpty();
});

it('menjaga setiap halaman khusus panel dengan canAccess sendiri', function () {
    $tanpaGate = collect(Filament::getPages())
        ->filter(fn (string $page) => (new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName() === Page::class)
        ->values()
        ->all();

    expect($tanpaGate)->toBe([], 'Halaman ini masih memakai canAccess bawaan (terbuka untuk semua peran panel): '.implode(', ', $tanpaGate));
    expect(Filament::getPages())->not->toBeEmpty();
});

it('membuka daftar resource hanya untuk peran yang punya izin lihat modulnya', function () {
    $resources = modulPerResource();
    expect($resources)->not->toBeEmpty();

    foreach (array_keys(ModuleAccess::DEFAULT_MATRIX) as $role) {
        if (! in_array($role, User::PANEL_ROLES, true)) {
            continue;
        }

        $user = penggunaBerperan($role);
        $bolehLihat = $diblokir = 0;

        foreach ($resources as $resource => $modul) {
            $response = $this->actingAs($user)->get($resource::getUrl('index'));

            if (in_array($modul.'.view', ModuleAccess::DEFAULT_MATRIX[$role], true)) {
                $response->assertOk();
                $bolehLihat++;
            } else {
                $response->assertForbidden();
                $diblokir++;
            }
        }

        // Positive control per peran: matriks yang salah tulis sehingga satu
        // peran diblokir dari segalanya (atau dibuka segalanya) ikut ketahuan.
        expect($bolehLihat)->toBeGreaterThan(0, "Peran {$role} tidak bisa membuka satu resource pun.");
    }

    // Akunting tidak melihat modul yang memang bukan urusannya -- pastikan
    // cabang 403 di atas benar-benar pernah dijalankan.
    $akunting = penggunaBerperan('accounting');
    $this->actingAs($akunting)->get('/inventory/inventory-movements')->assertOk();
    $this->actingAs(penggunaBerperan('sales'))->get('/inventory/inventory-movements')->assertForbidden();
});

it('menolak pembuatan data oleh peran yang hanya boleh melihat', function () {
    // Produksi boleh melihat bahan tetapi tidak menambah; akunting boleh.
    $this->actingAs(penggunaBerperan('production'));
    Livewire::test(CreateInventoryItem::class)->assertForbidden();

    $this->actingAs(penggunaBerperan('accounting'));
    Livewire::test(CreateInventoryItem::class)->assertOk();
});

it('memisahkan tahap form kebutuhan: menyusun, menyetujui, memeriksa, dan menutup', function () {
    $order = ProductionOrder::query()->create(['production_date' => now()->toDateString(), 'status' => ProductionOrder::STATUS_PLANNED]);
    $requisition = Requisition::query()->create(['production_order_id' => $order->id, 'status' => Requisition::STATUS_DRAFT]);

    $draftCase = fn (string $role) => Livewire::actingAs(penggunaBerperan($role))
        ->test(RequisitionForm::class, ['record' => $order->id]);

    // Produksi menyusun, mengisi, dan mengajukan -- tidak menyetujui.
    $draftCase('production')
        ->assertActionVisible('susun')
        ->assertActionVisible('simpan')
        ->assertActionVisible('ajukan')
        ->assertActionHidden('setujui');

    // Akunting hanya membaca pada tahap draft.
    $draftCase('accounting')
        ->assertActionHidden('susun')
        ->assertActionHidden('simpan')
        ->assertActionHidden('ajukan')
        ->assertActionHidden('setujui');

    // Belum diajukan: supervisor gudang pun belum bisa menyetujui.
    $draftCase('inventory-supervisor')->assertActionHidden('setujui')->assertActionHidden('tolak');

    $requisition->update(['status' => Requisition::STATUS_SUBMITTED]);

    // Diajukan: supervisor gudang menyetujui / menolak; produksi & staf gudang tidak.
    $draftCase('inventory-supervisor')->assertActionVisible('setujui')->assertActionVisible('tolak');
    $draftCase('production')->assertActionHidden('setujui')->assertActionHidden('tolak')->assertActionHidden('simpan');
    $draftCase('inventory')->assertActionHidden('setujui')->assertActionHidden('tolak');
    // Admin tetap bisa (cadangan) -- positive control.
    $draftCase('admin')->assertActionVisible('setujui');

    $requisition->update(['status' => Requisition::STATUS_APPROVED]);

    $draftCase('production')->assertActionHidden('periksa');
    $draftCase('accounting')->assertActionVisible('periksa');

    $requisition->update(['status' => Requisition::STATUS_CHECKED]);

    $draftCase('accounting')->assertActionHidden('tutup');
    $draftCase('production')->assertActionVisible('tutup');
});

it('menolak penyimpanan isian form lewat panggilan langsung tanpa izin kelola', function () {
    $order = ProductionOrder::query()->create(['production_date' => now()->toDateString(), 'status' => ProductionOrder::STATUS_PLANNED]);
    Requisition::query()->create(['production_order_id' => $order->id, 'status' => Requisition::STATUS_DRAFT]);

    // Tombolnya tersembunyi, tetapi metode Livewire-nya tetap bisa dipanggil
    // dari konsol browser -- pagar harus ada di metodenya juga.
    Livewire::actingAs(penggunaBerperan('accounting'))
        ->test(RequisitionForm::class, ['record' => $order->id])
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs(penggunaBerperan('production'))
        ->test(RequisitionForm::class, ['record' => $order->id])
        ->call('save')
        ->assertOk();
});

it('menyinkronkan izin secara idempoten dan mengembalikan matriks bawaan saat reset', function () {
    $admin = Role::findByName('admin', 'web');
    $bawaan = collect(ModuleAccess::DEFAULT_MATRIX['admin'])->sort()->values()->all();

    expect($admin->permissions->pluck('name')->sort()->values()->all())->toBe($bawaan);

    // Pemilik menggeser izin secara manual: sync biasa tidak mencabutnya.
    $admin->givePermissionTo('settings.manage');
    $admin->revokePermissionTo('requisition.approve');

    $this->artisan('access:sync')->assertSuccessful();
    $admin->refresh();

    expect($admin->hasPermissionTo('settings.manage'))->toBeTrue()
        ->and($admin->hasPermissionTo('requisition.approve'))->toBeTrue(); // izin bawaan yang hilang ditambahkan kembali

    $this->artisan('access:sync', ['--reset' => true])->assertSuccessful();
    $admin->refresh();

    expect($admin->permissions->pluck('name')->sort()->values()->all())->toBe($bawaan);
});

it('meloloskan superadmin ke semua modul tanpa izin eksplisit', function () {
    $super = penggunaBerperan('superadmin');

    expect(Role::findByName('superadmin', 'web')->permissions)->toBeEmpty();

    foreach (array_keys(modulPerResource()) as $resource) {
        $this->actingAs($super)->get($resource::getUrl('index'))->assertOk();
    }

    $this->actingAs($super)->get('/menu/hpp-comparison-report')->assertOk();
});

it('menyembunyikan menu navigasi modul yang izinnya dicabut dari peran', function () {
    // Pemilik mencabut izin resep dari produksi lewat database (bukan kode):
    // menunya ikut hilang dan halamannya tertutup. Navigasi Filament dipasang
    // sekali per proses, jadi pencabutan dilakukan sebelum render pertama.
    Role::findOrCreate('production', 'web')->revokePermissionTo('recipe.view');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $produksi = penggunaBerperan('production');

    $this->actingAs($produksi)
        ->get('/inventory/production-orders')
        ->assertOk()
        ->assertSee('SPK Produksi')
        ->assertDontSee('Bahan Belum Cocok');

    $this->actingAs($produksi)->get('/menu/recipe-mismatches')->assertForbidden();

    // Positive control: peran yang izinnya utuh tetap bisa membuka halamannya.
    $this->actingAs(penggunaBerperan('admin'))->get('/menu/recipe-mismatches')->assertOk();
});

it('menyimpan bahan baru hanya lewat peran yang berizin kelola inventory', function () {
    // Melengkapi tes 403 di atas dengan data yang benar-benar tersimpan.
    $this->actingAs(penggunaBerperan('accounting'));

    Livewire::test(CreateInventoryItem::class)
        ->fillForm(['name' => 'Garam Uji Akses', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InventoryItem::query()->where('name', 'Garam Uji Akses')->exists())->toBeTrue();
});
