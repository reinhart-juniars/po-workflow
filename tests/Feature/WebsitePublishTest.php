<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsitePublish;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/**
 * Bagian C: tombol "Terbitkan ke Website" di aplikasi Marketing memanggil
 * Deploy Hook hosting; website lalu membangun ulang katalog dari API 3S ONE.
 */
const HOOK_URL = 'https://hosting.test/deploy-hook/rahasia';

beforeEach(function () {
    config(['website.deploy_hook_url' => HOOK_URL, 'website.api_token' => 'token-uji']);
});

function penerbit(string $role = 'marketing'): User
{
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false, 'name' => 'Mita Marketing']);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

function menuTerbit(array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'NASI AYAM TERIYAKI 12K',
        'sku' => 'Nasi Ayam Teriyaki',
        'unit' => 'porsi',
        'base_price' => 12000,
        'active' => true,
        'show_on_website' => true,
        'website_category' => 'ayam',
    ], $attributes));
}

it('memanggil Deploy Hook dan mencatat terbitan', function () {
    Http::fake([HOOK_URL => Http::response(['ok' => true])]);
    $marketing = penerbit();
    menuTerbit();
    menuTerbit(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo']);

    $this->actingAs($marketing)->get(route('marketingapp.dashboard'))
        ->assertOk()->assertSee('Terbitkan ke Website')->assertSee('Ada perubahan belum diterbitkan');

    $this->actingAs($marketing)->from(route('marketingapp.dashboard'))
        ->post(route('marketingapp.website.publish'))
        ->assertRedirect(route('marketingapp.dashboard'))
        ->assertSessionHas('success', '2 menu dikirim ke website. Website diperbarui dalam ±1–2 menit.');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === HOOK_URL && $request->method() === 'POST');

    $publish = WebsitePublish::query()->sole();
    expect($publish)->status->toBe(WebsitePublish::STATUS_SENT)->menu_count->toBe(2)->user_id->toBe($marketing->id)
        ->and(AuditLog::query()->where('action', 'website_published')->value('message'))->toBe('Menerbitkan 2 menu ke website');

    // Setelah terbit: sudah terbaru, sampai ada menu yang berubah.
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))
        ->assertOk()->assertSee('Sudah terbaru')->assertSee('oleh Mita Marketing');
    Product::query()->where('sku', 'Nasi Jinggo')->update(['website_category' => 'nasi-box']);
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))->assertOk()->assertSee('Ada perubahan belum diterbitkan');
});

it('menahan terbit ulang selama website masih diperbarui', function () {
    Http::fake([HOOK_URL => Http::response()]);
    $marketing = penerbit();
    menuTerbit();

    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))->assertSessionHas('success');
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'baru saja diterbitkan'));
    Http::assertSentCount(1);

    // Kontrol positif: lewat jeda, boleh terbit lagi.
    $this->travel(\App\Services\WebsitePublisher::COOLDOWN_SECONDS + 1)->seconds();
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))->assertSessionHas('success');
    Http::assertSentCount(2);
});

it('menampilkan pesan yang jelas saat hosting gagal, tanpa membocorkan URL hook', function () {
    Http::fake([HOOK_URL => Http::sequence()->push('internal error at '.HOOK_URL, 500)->push('', 200)]);
    $marketing = penerbit();
    menuTerbit();

    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'Hosting website menolak permintaan (kode 500).')
            && ! str_contains($message, 'rahasia'));

    expect(WebsitePublish::query()->sole()->status)->toBe(WebsitePublish::STATUS_FAILED);

    // Terbit yang gagal tidak memicu jeda: boleh langsung dicoba lagi.
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))->assertSessionHas('success');
});

it('menolak terbit bila website belum tersambung', function () {
    config(['website.deploy_hook_url' => null]);
    Http::fake();
    $marketing = penerbit();

    $this->actingAs($marketing)->get(route('marketingapp.dashboard'))->assertOk()->assertSee('Belum tersambung');
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'belum tersambung'));

    Http::assertNothingSent();
    expect(WebsitePublish::query()->count())->toBe(0);
});

it('hanya marketing, owner, dan superadmin yang bisa menerbitkan', function () {
    Http::fake([HOOK_URL => Http::response()]);
    menuTerbit();

    foreach (['admin', 'sales', 'inventory'] as $role) {
        $this->actingAs(penerbit($role))->post(route('marketingapp.website.publish'))->assertForbidden();
    }
    Http::assertNothingSent();

    // Kontrol positif: owner boleh.
    $this->actingAs(penerbit('owner'))->post(route('marketingapp.website.publish'))->assertSessionHas('success');
    Http::assertSentCount(1);
});
