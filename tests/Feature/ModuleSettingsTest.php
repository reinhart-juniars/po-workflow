<?php

use App\Filament\Pages\HppComparisonReport;
use App\Filament\Pages\ModuleSettings;
use App\Filament\Resources\RecipeResource\Pages\CreateRecipe;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Models\User;
use App\Services\ProductionCompletionService;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Modul Pengaturan: nilai bawaan berlaku tanpa baris tersimpan, halaman
 * menyimpan & mencatat audit, dan setiap pengaturan benar-benar mengubah
 * perilaku kode yang membacanya -- pengaturan yang tidak dibaca siapa pun
 * hanya menipu pemilik.
 */
function penggunaPengaturan(string $role = 'owner'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

it('memakai nilai bawaan registry selama belum ada yang disimpan', function () {
    $settings = app(Settings::class);

    expect(AppSetting::query()->count())->toBe(0)
        ->and($settings->get('recipe.default_ohc_pct'))->toBe(40.0)
        ->and($settings->bool('requisition.round_purchase_up'))->toBeFalse()
        ->and($settings->get('document.production_prefix'))->toBe('SPKP');

    // Semua kunci terdaftar punya definisi lengkap.
    foreach (SettingRegistry::all() as $key => $definition) {
        expect($definition)->toHaveKeys(['group', 'label', 'type', 'default'], "Definisi {$key} tidak lengkap.");
        expect(fn () => $settings->get($key))->not->toThrow(Throwable::class);
    }
});

it('menyimpan lewat halaman pengaturan dan mencatatnya ke audit log', function () {
    $owner = penggunaPengaturan('owner');
    $this->actingAs($owner);

    Livewire::test(ModuleSettings::class)
        ->assertOk()
        ->assertFormSet([ModuleSettings::stateKey('recipe.default_ohc_pct') => 40])
        ->fillForm([
            ModuleSettings::stateKey('recipe.default_ohc_pct') => 35,
            ModuleSettings::stateKey('requisition.round_purchase_up') => true,
            ModuleSettings::stateKey('document.requisition_prefix') => 'FK',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Pengaturan tersimpan');

    $settings = app(Settings::class);

    expect((float) $settings->get('recipe.default_ohc_pct'))->toBe(35.0)
        ->and($settings->bool('requisition.round_purchase_up'))->toBeTrue()
        ->and($settings->get('document.requisition_prefix'))->toBe('FK')
        ->and(AppSetting::query()->where('key', 'recipe.default_ohc_pct')->value('updated_by'))->toBe($owner->id);

    // Yang tidak berubah tidak menghasilkan baris audit.
    $audit = AuditLog::query()->where('entity', 'app_setting')->get();
    expect($audit)->toHaveCount(3)
        ->and($audit->firstWhere('after_json.key', 'recipe.default_ohc_pct')->before_json['value'])->toEqual(40)
        ->and($audit->firstWhere('after_json.key', 'recipe.default_ohc_pct')->user_id)->toBe($owner->id);
});

it('menolak nilai di luar batas dan pilihan yang tidak dikenal', function () {
    $settings = app(Settings::class);

    expect(fn () => $settings->set('recipe.default_ohc_pct', 999))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->set('hpp.comparison_default_range', 'tahun_lalu'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->set('document.production_prefix', '  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->set('tidak.ada', 1))->toThrow(InvalidArgumentException::class);

    // Positive control: nilai sah tersimpan.
    $settings->set('hpp.comparison_default_range', 'bulan_lalu');
    expect($settings->get('hpp.comparison_default_range'))->toBe('bulan_lalu');
});

it('hanya membuka halaman pengaturan untuk peran berizin settings.manage', function () {
    $this->actingAs(penggunaPengaturan('admin'))->get('/admin/pengaturan')->assertForbidden();

    // Tombolnya tersembunyi, metodenya pun ditolak.
    Livewire::actingAs(penggunaPengaturan('admin'))->test(ModuleSettings::class)->assertForbidden();

    $this->actingAs(penggunaPengaturan('owner'))->get('/admin/pengaturan')->assertOk()->assertSee('Pengaturan Modul');
});

it('memakai OHC dan profit bawaan dari pengaturan saat membuat resep baru', function () {
    app(Settings::class)->setMany(['recipe.default_ohc_pct' => 30, 'recipe.default_profit_pct' => 15]);

    $this->actingAs(penggunaPengaturan('admin'));

    Livewire::test(CreateRecipe::class)
        ->assertFormSet(['ohc_pct' => 30.0, 'profit_pct' => 15.0]);
});

it('membulatkan usulan beli ke atas bila pengaturannya aktif', function () {
    $line = new RequisitionLine(['required_qty' => 2.3, 'opening_stock_qty' => 1]);

    expect($line->suggestedPurchaseQty())->toBe(1.3);

    app(Settings::class)->set('requisition.round_purchase_up', true);

    expect($line->suggestedPurchaseQty())->toBe(2.0);

    // Kebutuhan yang sudah tertutup stok tetap 0, bukan 1.
    $cukup = new RequisitionLine(['required_qty' => 1, 'opening_stock_qty' => 5]);
    expect($cukup->suggestedPurchaseQty())->toBe(0.0);
});

it('mewajibkan sisa stok sebelum menutup SPK bila pengaturannya aktif', function () {
    $item = InventoryItem::query()->create(['name' => 'Beras', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $order = ProductionOrder::query()->create(['production_date' => now()->toDateString(), 'status' => ProductionOrder::STATUS_PLANNED]);
    $requisition = Requisition::query()->create(['production_order_id' => $order->id, 'status' => Requisition::STATUS_CHECKED]);
    $requisition->lines()->create(['inventory_item_id' => $item->id, 'name' => 'Beras', 'unit' => 'kg', 'required_qty' => 2, 'opening_stock_qty' => 5, 'purchase_qty' => 0, 'unit_price' => 12000]);

    app(Settings::class)->set('production.require_remaining_on_close', true);

    expect(fn () => app(ProductionCompletionService::class)->complete($order->fresh()))
        ->toThrow(RuntimeException::class, 'belum diisi Sisa Stok');
    expect($order->fresh()->isCompleted())->toBeFalse();

    // Positive control: setelah sisa diisi, SPK tertutup.
    $requisition->lines()->update(['remaining_qty' => 3]);
    app(ProductionCompletionService::class)->complete($order->fresh());

    expect($order->fresh()->isCompleted())->toBeTrue();
});

it('memakai awalan nomor dokumen dari pengaturan untuk dokumen baru', function () {
    app(Settings::class)->setMany(['document.production_prefix' => 'PRD', 'document.requisition_prefix' => 'FK']);

    $order = ProductionOrder::query()->create(['production_date' => now()->toDateString()]);
    $requisition = Requisition::query()->create(['production_order_id' => $order->id]);

    expect($order->number)->toStartWith('PRD-')
        ->and($requisition->number)->toStartWith('FK-');
});

it('membuka perbandingan hpp pada bulan lalu bila pengaturannya diubah', function () {
    $this->actingAs(penggunaPengaturan('admin'));

    $tanggal = fn ($component, string $field) => substr((string) $component->get("data.{$field}"), 0, 10);

    $bulanIni = Livewire::test(HppComparisonReport::class);
    expect($tanggal($bulanIni, 'date_from'))->toBe(now()->startOfMonth()->toDateString());

    app(Settings::class)->set('hpp.comparison_default_range', 'bulan_lalu');

    $bulanLalu = Livewire::test(HppComparisonReport::class);
    expect($tanggal($bulanLalu, 'date_from'))->toBe(now()->subMonthNoOverflow()->startOfMonth()->toDateString())
        ->and($tanggal($bulanLalu, 'date_to'))->toBe(now()->subMonthNoOverflow()->endOfMonth()->toDateString());
});
