<?php

use App\Models\InventoryMovement;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use App\Services\InventoryLedgerService;
use App\Services\MaterialBreakdownService;
use App\Services\ProductionOrderService;
use Spatie\Permission\Models\Role;

/**
 * Breakdown menu -> bahan mentah per pesanan / SPK Produksi, dicocokkan
 * dengan stok Kartu Stok (padanan "Pra SPK" Master Menu).
 *
 * Fixture siapkanProduksi(): resep Gorengan 10 porsi = 250 gr tepung (bahan
 * dalam kg) + 150 ml minyak (bahan dalam liter); PO#1 = 30 porsi + 20 cup
 * Es Teh tanpa resep, PO#2 = 50 porsi, keduanya di satu slot SPK.
 */
function penggunaBreakdown(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function barisRekap(array $breakdown, string $nama): array
{
    return collect($breakdown['recap'])->firstWhere('name', $nama);
}

it('memecah menu di satu PO sampai bahan mentah dengan satuan bahan dan mencatat menu tanpa resep', function () {
    $data = siapkanProduksi();

    $breakdown = app(MaterialBreakdownService::class)->forPurchaseOrder($data['pos'][0]);

    // 30 porsi = 3x resep: 750 gr -> 0,75 kg tepung; 450 ml -> 0,45 liter minyak.
    expect(barisRekap($breakdown, 'Tepung Terigu'))->toMatchArray(['qty' => 0.75, 'unit' => 'kg', 'menus' => ['GORENGAN 10K']])
        ->and(barisRekap($breakdown, 'Minyak Goreng'))->toMatchArray(['qty' => 0.45, 'unit' => 'liter']);

    // Rincian per menu, dan Es Teh tidak diam-diam dianggap nol.
    expect($breakdown['menus'])->toHaveCount(1)
        ->and($breakdown['menus'][0]['label'])->toBe('GORENGAN 10K')
        ->and($breakdown['menus'][0]['total_cost'])->toBe(18000.0) // 0,75 x 12.000 + 0,45 x 20.000
        ->and($breakdown['missing'])->toBe([['label' => 'ES TEH', 'qty' => 20.0, 'unit' => 'cup']])
        ->and($breakdown['summary'])->toMatchArray(['menu_count' => 2, 'menu_with_recipe' => 1, 'coverage_pct' => 60.0, 'ingredient_count' => 2]);
});

it('mencocokkan kebutuhan dengan stok kartu stok: perlu beli = kebutuhan - stok, stok belum tercatat bukan nol', function () {
    $data = siapkanProduksi();
    $ledger = app(InventoryLedgerService::class);

    // Tepung punya stok 0,5 kg; minyak belum pernah punya gerakan sama sekali.
    $ledger->post($data['tepung']->id, InventoryMovement::TYPE_OPENING, 0.5, 'kg', now()->subDay()->toDateString(), 12000);

    $breakdown = app(MaterialBreakdownService::class)->forPurchaseOrder($data['pos'][0]);

    expect(barisRekap($breakdown, 'Tepung Terigu'))->toMatchArray(['stock' => 0.5, 'to_buy' => 0.25, 'to_buy_cost' => 3000.0])
        ->and(barisRekap($breakdown, 'Minyak Goreng'))->toMatchArray(['stock' => null, 'to_buy' => 0.45])
        ->and($breakdown['summary'])->toMatchArray(['to_buy_count' => 2, 'unknown_stock_count' => 1]);

    // Stok melebihi kebutuhan -> tidak perlu beli, tidak negatif.
    $ledger->post($data['tepung']->id, InventoryMovement::TYPE_PURCHASE, 2, 'kg', now()->toDateString(), 12000);
    $breakdown = app(MaterialBreakdownService::class)->forPurchaseOrder($data['pos'][0]);
    expect(barisRekap($breakdown, 'Tepung Terigu'))->toMatchArray(['stock' => 2.5, 'to_buy' => 0.0, 'to_buy_cost' => 0.0])
        ->and($breakdown['summary']['to_buy_count'])->toBe(1);
});

it('menggabungkan menu yang sama dari beberapa PO di satu SPK Produksi', function () {
    $data = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);

    $breakdown = app(MaterialBreakdownService::class)->forProductionOrder($order);

    // 30 + 50 = 80 porsi dalam satu kartu menu: 2 kg tepung, 1,2 liter minyak.
    expect($breakdown['menus'])->toHaveCount(1)
        ->and($breakdown['menus'][0]['qty'])->toBe(80.0)
        ->and(barisRekap($breakdown, 'Tepung Terigu')['qty'])->toBe(2.0)
        ->and(barisRekap($breakdown, 'Minyak Goreng')['qty'])->toBe(1.2)
        ->and(collect($breakdown['missing'])->pluck('label')->all())->toBe(['ES TEH']);

    // Rekapnya sama dengan angka kebutuhan yang dipakai Form Kebutuhan.
    $formKebutuhan = app(ProductionOrderService::class)->requirements($order->fresh('lines'))['rows']->pluck('qty', 'name')->all();
    expect(collect($breakdown['recap'])->pluck('qty', 'name')->all())->toEqual($formKebutuhan);
});

it('mengurai sub-resep sampai bahan mentahnya', function () {
    $data = siapkanProduksi();

    // Sub-menu Adonan (10 porsi = 100 gr tepung) dipakai 10 porsi per resep Gorengan.
    $adonan = Recipe::query()->create(['name' => 'Adonan', 'jenis' => Recipe::JENIS_SUB, 'yield_qty' => 10, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0]);
    RecipeItem::query()->create(['recipe_id' => $adonan->id, 'inventory_item_id' => $data['tepung']->id, 'raw_name' => 'tepung', 'qty' => 100, 'unit' => 'gr']);
    RecipeItem::query()->create(['recipe_id' => $data['recipe']->id, 'ref_recipe_id' => $adonan->id, 'raw_name' => 'Adonan', 'qty' => 10, 'unit' => 'porsi']);

    $breakdown = app(MaterialBreakdownService::class)->forPurchaseOrder($data['pos'][1]);

    // 50 porsi = 5x resep: (250 + 100) gr x 5 = 1,75 kg tepung.
    expect(barisRekap($breakdown, 'Tepung Terigu')['qty'])->toBe(1.75)
        ->and(collect($breakdown['recap'])->pluck('name')->all())->not->toContain('Adonan');
});

it('menampilkan kebutuhan bahan di Detail PO admin dan di SPK Produksi', function () {
    $data = siapkanProduksi();
    $admin = penggunaBreakdown('admin');

    $this->actingAs($admin)->get(route('adminapp.orders.show', $data['pos'][0]))
        ->assertOk()
        ->assertSee('Kebutuhan Bahan')
        ->assertSee('Tepung Terigu')
        ->assertSee('1 menu belum punya resep')
        ->assertSee('ES TEH (20 cup)')
        ->assertSee(route('filament.admin.pages.pencocokan-menu'), false);

    $order = app(ProductionOrderService::class)->generateFromSpk($data['spk']);
    $this->actingAs($admin)->get('/inventory/production-orders/'.$order->id.'/bahan')
        ->assertOk()
        ->assertSee('Breakdown Bahan')
        ->assertSee('Minyak Goreng')
        ->assertSee('Rincian per menu');

    // Peran tanpa production.view (sales) tidak bisa membuka halaman SPK-nya.
    $this->actingAs(penggunaBreakdown('sales'))->get('/inventory/production-orders/'.$order->id.'/bahan')->assertForbidden();
});
