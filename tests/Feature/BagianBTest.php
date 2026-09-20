<?php

use App\Filament\Pages\IdleMenuReport;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Models\AppSetting;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use App\Services\IdleMenuReportService;
use App\Services\ProfitGuardService;
use App\Support\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Bagian B -- kebutuhan tambahan di atas Modul Inventory Terpadu:
 * B.1 notifikasi perubahan harga, B.2 notifikasi batas profit menu,
 * B.3 laporan menu tidak diproduksi, B.4 katalog foto menu berbasis SKU.
 */
function penggunaB(string $role): User
{
    Role::findOrCreate($role, 'web');
    $u = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $u->assignRole($role);

    return $u;
}

function lonceng(User $user): \Illuminate\Support\Collection
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->latest()->get()->map(fn ($n) => $n->data['title']);
}

// ---------------------------------------------------------------- B.1
it('mengirim lonceng saat harga beli bahan berubah, ke pemegang izin notifikasi harga, bukan ke pelakunya', function () {
    $owner = penggunaB('owner');
    $accounting = penggunaB('accounting');
    $sales = penggunaB('sales');

    $bahan = InventoryItem::query()->create(['name' => 'Tepung Terigu', 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => 12000, 'is_active' => true]);
    expect(lonceng($owner))->toHaveCount(0);

    // Owner yang mengubah: accounting diberi tahu, owner (pelaku) dan sales (tanpa izin) tidak.
    $this->actingAs($owner);
    $bahan->update(['unit_price' => 13500]);

    expect(lonceng($accounting)->first())->toBe('Harga bahan Tepung Terigu naik 12.5%')
        ->and(DatabaseNotification::query()->where('notifiable_id', $accounting->id)->first()->data['body'])->toContain('Rp 12.000 → Rp 13.500 per kg')->toContain('sumber: panel')
        ->and(lonceng($owner))->toHaveCount(0)
        ->and(lonceng($sales))->toHaveCount(0);

    // Harga turun: judulnya "turun". Perubahan tanpa pelaku (proses sistem) tetap memberi tahu semua pemegang izin.
    auth()->logout();
    $bahan->fresh()->update(['unit_price' => 9000]);
    expect(lonceng($owner)->first())->toContain('turun 33.3%')
        ->and(lonceng($accounting))->toHaveCount(2);

    // Kolom lain berubah: tidak ada lonceng (kontrol negatif).
    $bahan->fresh()->update(['description' => 'catatan']);
    expect(lonceng($accounting))->toHaveCount(2);
});

it('mengirim lonceng saat harga jual menu berubah, dan lonceng itu terbaca di header Blade', function () {
    $owner = penggunaB('owner');
    $admin = penggunaB('admin');
    $product = Product::query()->create(['name' => 'Nasi Kuning', 'sku' => 'NASI-KUNING', 'unit' => 'porsi', 'base_price' => 15000, 'active' => true]);

    $this->actingAs($admin);
    $product->update(['base_price' => 17000]);

    expect(lonceng($owner)->first())->toBe('Harga jual NASI KUNING naik 13.3%')
        ->and(lonceng($admin))->toHaveCount(0);

    // Lonceng di header Blade (Owner app): badge 1, judul tampil, "Buka" menandai dibaca lalu ke halaman menu.
    $this->actingAs($owner)->get('/owner-app')
        ->assertOk()
        ->assertSee('class="sh-bell"', false)
        ->assertSee('sh-bell-badge', false)
        ->assertSee('Harga jual NASI KUNING naik 13.3%');

    $id = DatabaseNotification::query()->where('notifiable_id', $owner->id)->value('id');
    $this->actingAs($owner)->get(route('notifications.open', $id))->assertRedirect(route('adminapp.products.edit', $product));
    expect(DatabaseNotification::query()->whereKey($id)->first()->read_at)->not->toBeNull();

    // Tidak bisa membuka lonceng milik orang lain (kontrol negatif).
    $this->actingAs($admin)->get(route('notifications.open', $id))->assertNotFound();

    // Tandai semua dibaca.
    $product->fresh()->update(['base_price' => 18000]);
    $this->actingAs($owner)->post(route('notifications.read-all'))->assertRedirect();
    expect(DatabaseNotification::query()->where('notifiable_id', $owner->id)->whereNull('read_at')->count())->toBe(0);
});

// ---------------------------------------------------------------- B.2
function menuDenganResep(string $nama, float $hargaJual, float $biayaBahan): Product
{
    $bucket = InventoryItem::query()->firstOrCreate(['name' => 'Bahan Baku', 'unit' => 'All'], ['category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'is_active' => true]);
    $bahan = InventoryItem::query()->create(['parent_id' => $bucket->id, 'name' => 'Bahan '.$nama, 'unit' => 'kg', 'category' => InventoryItem::CATEGORY_RAW_MATERIAL, 'unit_price' => $biayaBahan, 'is_active' => true]);
    // 1 porsi = 1 kg bahan, OHC 0 -> biaya = harga bahan.
    $recipe = Recipe::query()->create(['name' => $nama, 'jenis' => Recipe::JENIS_UTAMA, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0, 'profit_pct' => 0.25, 'is_active' => true]);
    RecipeItem::query()->create(['recipe_id' => $recipe->id, 'inventory_item_id' => $bahan->id, 'raw_name' => 'bahan', 'qty' => 1, 'unit' => 'kg']);

    return Product::query()->create(['name' => $nama, 'sku' => strtoupper(str_replace(' ', '-', $nama)), 'unit' => 'porsi', 'base_price' => $hargaJual, 'active' => true, 'recipe_id' => $recipe->id]);
}

it('menghitung profit menu keseluruhan dan mengirim lonceng hanya saat keadaan berubah terhadap batas', function () {
    $owner = penggunaB('owner');
    $gudang = penggunaB('inventory');
    app(Settings::class)->setMany(['profit.lower_bound_pct' => 20, 'profit.upper_bound_pct' => 60]);
    $guard = app(ProfitGuardService::class);

    // Belum ada resep bersih: keadaan 'none', tidak ada lonceng.
    expect($guard->check())->toMatchArray(['state' => 'none', 'announced' => false]);

    // Dua menu: jual 15.000 biaya 10.000 (50%) dan jual 12.000 biaya 10.000 (20%) -> keseluruhan (27.000-20.000)/20.000 = 35%: normal.
    $a = menuDenganResep('Menu A', 15000, 10000);
    $b = menuDenganResep('Menu B', 12000, 10000);
    $r = $guard->check();
    expect($r['state'])->toBe('ok')->and($r['profit_pct'])->toBe(35.0)
        // pertama kali normal: tidak perlu diumumkan
        ->and($r['announced'])->toBeFalse()
        ->and(lonceng($owner))->toHaveCount(0);

    // Harga jual B dipangkas: keseluruhan (15.000+9.000-20.000)/20.000 = 20% -> masih di batas; 8.000 -> 15% di bawah batas.
    $b->update(['base_price' => 8000]);
    $r = $guard->check();
    expect($r['state'])->toBe('below')->and($r['announced'])->toBeTrue()
        ->and(lonceng($owner)->filter(fn ($t) => str_contains($t, 'Profit'))->first())->toBe('Profit menu keseluruhan di BAWAH batas: 15,0%')
        ->and(DatabaseNotification::query()->where('notifiable_id', $owner->id)->where('data->title', 'like', 'Profit%')->first()->data['body'])->toContain('Terendah: MENU B (-20%)')
        // staf gudang tidak punya izin notification.profit
        ->and(lonceng($gudang)->filter(fn ($t) => str_contains($t, 'Profit')))->toHaveCount(0);

    // Diperiksa lagi tanpa perubahan: tetap 'below', tidak ada lonceng ganda.
    expect($guard->check()['announced'])->toBeFalse();

    // Kembali normal -> diumumkan sekali; di atas batas -> diumumkan.
    $b->fresh()->update(['base_price' => 14000]);
    expect($guard->check()['state'])->toBe('ok');
    expect(lonceng($owner)->filter(fn ($t) => str_contains($t, 'kembali normal')))->toHaveCount(1);

    $a->fresh()->update(['base_price' => 40000]);
    expect($guard->check()['state'])->toBe('above');
    expect(lonceng($owner)->filter(fn ($t) => str_contains($t, 'di ATAS batas')))->toHaveCount(1)
        ->and(AppSetting::query()->where('key', ProfitGuardService::STATE_KEY)->first()->value)->toBe('above');

    // Perintah terjadwal berjalan.
    $this->artisan('profit:check')->assertSuccessful()->expectsOutputToContain('above');
});

it('menampilkan profit keseluruhan dan batasnya di dashboard inventory', function () {
    menuDenganResep('Menu C', 15000, 10000);

    Livewire::actingAs(penggunaB('owner'))
        ->test(InventoryOverviewWidget::class)
        ->assertSee('Profit menu keseluruhan')
        ->assertSee('50,0%')
        ->assertSee('batas 20–60%');
});

// ---------------------------------------------------------------- B.3
it('mendaftar menu aktif yang tidak diproduksi dalam rentang, dengan tanggal produksi & penjualan terakhir', function () {
    $owner = penggunaB('owner');
    $laku = menuDenganResep('Menu Laku', 15000, 10000);
    $idle = menuDenganResep('Menu Idle', 15000, 10000);
    $nonaktif = Product::query()->create(['name' => 'Menu Nonaktif', 'sku' => 'NONAKTIF', 'unit' => 'porsi', 'base_price' => 1, 'active' => false]);
    $tanpaResep = Product::query()->create(['name' => 'Menu Tanpa Resep', 'sku' => 'TANPA-RESEP', 'unit' => 'porsi', 'base_price' => 1, 'active' => true]);

    // Menu Laku diproduksi 10 hari lalu; Menu Idle terakhir 4 bulan lalu (di luar rentang 3 bulan); SPK batal tidak dihitung.
    $baru = ProductionOrder::query()->create(['production_date' => now()->subDays(10)->toDateString(), 'status' => ProductionOrder::STATUS_COMPLETED]);
    ProductionOrderLine::query()->create(['production_order_id' => $baru->id, 'kind' => ProductionOrderLine::KIND_MENU, 'recipe_id' => $laku->recipe_id, 'label' => 'x', 'qty' => 10, 'unit' => 'porsi']);
    $lama = ProductionOrder::query()->create(['production_date' => now()->subMonths(4)->toDateString(), 'status' => ProductionOrder::STATUS_COMPLETED]);
    ProductionOrderLine::query()->create(['production_order_id' => $lama->id, 'kind' => ProductionOrderLine::KIND_MENU, 'recipe_id' => $idle->recipe_id, 'label' => 'x', 'qty' => 10, 'unit' => 'porsi']);
    $batal = ProductionOrder::query()->create(['production_date' => now()->subDays(3)->toDateString(), 'status' => ProductionOrder::STATUS_CANCELLED]);
    ProductionOrderLine::query()->create(['production_order_id' => $batal->id, 'kind' => ProductionOrderLine::KIND_MENU, 'recipe_id' => $idle->recipe_id, 'label' => 'x', 'qty' => 10, 'unit' => 'porsi']);

    $rows = app(IdleMenuReportService::class)->rows(now()->subMonths(3));
    expect($rows->pluck('name')->all())->toBe(['MENU IDLE', 'MENU TANPA RESEP'])
        ->and($rows->firstWhere('name', 'MENU IDLE')['last_produced'])->toBe(now()->subMonths(4)->toDateString())
        ->and($rows->firstWhere('name', 'MENU TANPA RESEP')['has_recipe'])->toBeFalse();

    // Rentang lebih lebar (6 bulan) -> Menu Idle tidak lagi dianggap idle; nonaktif ikut bila diminta.
    expect(app(IdleMenuReportService::class)->rows(now()->subMonths(6))->pluck('name')->all())->toBe(['MENU TANPA RESEP'])
        ->and(app(IdleMenuReportService::class)->rows(now()->subMonths(3), null, false)->pluck('name')->all())->toContain('MENU NONAKTIF');

    // Halaman & export; rentang bawaan dari pengaturan.
    app(Settings::class)->set('report.idle_menu_months', 3);
    Livewire::actingAs($owner)
        ->test(IdleMenuReport::class)
        ->assertSee('MENU IDLE')
        ->assertSee('MENU TANPA RESEP')
        ->assertDontSee('MENU LAKU')
        ->callAction('export')
        ->assertFileDownloaded();

    // Peran tanpa recipe.view (sales) tidak bisa membuka; owner bisa (kontrol positif).
    $this->actingAs(penggunaB('sales'))->get('/inventory/menu-tidak-diproduksi')->assertForbidden();
    $this->actingAs($owner)->get('/inventory/menu-tidak-diproduksi')->assertOk();
});

// ---------------------------------------------------------------- B.4
it('menyediakan katalog foto menu berbasis SKU: admin mengunggah/mengganti/menghapus, sales hanya melihat', function () {
    Storage::fake('public');
    $admin = penggunaB('admin');
    $sales = penggunaB('sales');
    $product = Product::query()->create(['name' => 'Nasi Kuning', 'sku' => 'NASI-KUNING-15K', 'unit' => 'porsi', 'base_price' => 15000, 'active' => true]);

    // Admin: halaman kelola dengan form unggah.
    $this->actingAs($admin)->get(route('adminapp.catalog.index'))
        ->assertOk()->assertSee('NASI-KUNING-15K')->assertSee('Belum ada foto')->assertSee('Unggah');

    // Bukan gambar -> ditolak; gambar -> tersimpan dengan nama berawalan SKU.
    $this->actingAs($admin)->post(route('adminapp.catalog.upload', $product), ['photo' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors('photo');
    expect($product->fresh()->photo_path)->toBeNull();

    $this->actingAs($admin)->post(route('adminapp.catalog.upload', $product), ['photo' => UploadedFile::fake()->image('menu.jpg', 800, 600)])
        ->assertSessionHasNoErrors();
    $path = $product->fresh()->photo_path;
    expect($path)->toStartWith('menu-photos/NASI-KUNING-15K-');
    Storage::disk('public')->assertExists($path);

    // Ganti foto: berkas lama dibuang.
    $this->actingAs($admin)->post(route('adminapp.catalog.upload', $product), ['photo' => UploadedFile::fake()->image('baru.png', 800, 600)]);
    $path2 = $product->fresh()->photo_path;
    expect($path2)->not->toBe($path);
    Storage::disk('public')->assertMissing($path);
    Storage::disk('public')->assertExists($path2);

    // Sales: melihat foto & SKU, tanpa form unggah; tidak bisa mengunggah (route admin-app ditolak).
    $this->actingAs($sales)->get(route('salesapp.catalog.index'))
        ->assertOk()->assertSee('NASI-KUNING-15K')->assertSee($product->fresh()->photoUrl())->assertDontSee('catalog.upload');
    $this->actingAs($sales)->post(route('adminapp.catalog.upload', $product), ['photo' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();

    // Filter "belum ada foto" & pencarian.
    Product::query()->create(['name' => 'Es Teh', 'sku' => 'ES-TEH', 'unit' => 'cup', 'base_price' => 5000, 'active' => true]);
    $this->actingAs($admin)->get(route('adminapp.catalog.index', ['foto' => 'belum']))->assertSee('ES-TEH')->assertDontSee('NASI-KUNING-15K');
    $this->actingAs($admin)->get(route('adminapp.catalog.index', ['q' => 'kuning']))->assertSee('NASI-KUNING-15K')->assertDontSee('ES-TEH');

    // Hapus foto.
    $this->actingAs($admin)->delete(route('adminapp.catalog.photo.destroy', $product))->assertRedirect();
    expect($product->fresh()->photo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path2);
});
