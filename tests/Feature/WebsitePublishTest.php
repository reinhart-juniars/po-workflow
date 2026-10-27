<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsitePublish;
use App\Services\WebsiteMenuFeed;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/**
 * Bagian C: marketing membuka Pratinjau Website, lalu menekan "Terbitkan ke
 * Website" dari sana. 3S ONE menyimpan snapshot menu dan memanggil Deploy
 * Hook; website lalu dibangun ulang dari snapshot itu.
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

/** Versi menu yang sedang tampil di pratinjau. */
function versiPratinjau(): string
{
    return app(WebsiteMenuFeed::class)->build()['version'];
}

it('memanggil Deploy Hook, mencatat terbitan, dan menyimpan snapshot yang dipratinjau', function () {
    Http::fake([HOOK_URL => Http::response(['ok' => true])]);
    $marketing = penerbit();
    menuTerbit();
    menuTerbit(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo']);

    $this->actingAs($marketing)->get(route('marketingapp.dashboard'))
        ->assertOk()->assertSee('Pratinjau & Terbitkan')->assertSee('Ada perubahan belum diterbitkan');

    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])
        ->assertRedirect(route('marketingapp.website.preview'))
        ->assertSessionHas('success', '2 menu dikirim ke website. Website diperbarui dalam ±1–2 menit.');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === HOOK_URL && $request->method() === 'POST');

    $publish = WebsitePublish::query()->sole();
    expect($publish)->status->toBe(WebsitePublish::STATUS_SENT)->menu_count->toBe(2)->user_id->toBe($marketing->id)
        ->and($publish->snapshot['total'])->toBe(2)
        ->and($publish->snapshot['version'])->toBe($publish->feed_version)
        ->and(AuditLog::query()->where('action', 'website_published')->value('message'))->toBe('Menerbitkan 2 menu ke website');

    // Setelah terbit: sudah terbaru, sampai ada menu yang berubah.
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))
        ->assertOk()->assertSee('Sudah terbaru')->assertSee('oleh Mita Marketing');
    Product::query()->where('sku', 'Nasi Jinggo')->update(['website_category' => 'nasi-box']);
    $this->actingAs($marketing)->get(route('marketingapp.catalog.index'))->assertOk()->assertSee('Ada perubahan belum diterbitkan');
});

it('hanya menerbitkan lewat pratinjau, dan menolak versi yang sudah basi', function () {
    Http::fake([HOOK_URL => Http::response()]);
    $marketing = penerbit();
    $menu = menuTerbit();

    // Tanpa versi pratinjau: ditolak, hook tidak dipanggil.
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'))->assertSessionHasErrors('feed_version');

    // Pratinjau dibuka, lalu menu diubah orang lain sebelum tombol ditekan.
    $seen = versiPratinjau();
    $menu->update(['sku' => 'Nasi Ayam Teriyaki Pedas']);
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => $seen])
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'berubah sejak pratinjau dibuka'));

    Http::assertNothingSent();
    expect(WebsitePublish::query()->count())->toBe(0);

    // Kontrol positif: versi terkini diterima.
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertSessionHas('success');
    Http::assertSentCount(1);
});

it('menampilkan pratinjau beserta perubahan dari terbitan terakhir dan menu yang tidak ikut terbit', function () {
    Http::fake([HOOK_URL => Http::response()]);
    $marketing = penerbit();
    $teriyaki = menuTerbit();
    $jinggo = menuTerbit(['name' => 'NASI JINGGO 12K', 'sku' => 'Nasi Jinggo']);
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertSessionHas('success');

    // Sesudah terbit: satu baru, satu ganti nama, satu dilepas, satu tidak lengkap.
    menuTerbit(['name' => 'NASI KULIT 10K', 'sku' => 'Nasi Kulit Sambal Matah']);
    $teriyaki->update(['sku' => 'Nasi Ayam Teriyaki Wijen']);
    $jinggo->update(['show_on_website' => false]);
    menuTerbit(['name' => 'BUBUR AYAM 12K', 'sku' => 'Bubur Ayam', 'website_category' => null]);

    $this->actingAs($marketing)->get(route('marketingapp.website.preview'))
        ->assertOk()
        ->assertSee('2 menu akan tampil')
        ->assertSee('1 baru')->assertSee('1 ganti nama')->assertSee('1 hilang dari website')
        ->assertSee('Nasi Kulit Sambal Matah')
        ->assertSee('Nasi Ayam Teriyaki Wijen')
        ->assertSee('Akan hilang dari website')->assertSee('Nasi Jinggo')
        ->assertSee('Dicentang tetapi tidak ikut terbit')->assertSee('Belum diberi kategori website')
        ->assertSee('name="feed_version" value="'.versiPratinjau().'"', false);
});

it('menahan terbit ulang selama website masih diperbarui', function () {
    Http::fake([HOOK_URL => Http::response()]);
    $marketing = penerbit();
    menuTerbit();

    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertSessionHas('success');
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'baru saja diterbitkan'));
    Http::assertSentCount(1);

    // Kontrol positif: lewat jeda, boleh terbit lagi.
    $this->travel(\App\Services\WebsitePublisher::COOLDOWN_SECONDS + 1)->seconds();
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertSessionHas('success');
    Http::assertSentCount(2);
});

it('menampilkan pesan yang jelas saat hosting gagal, tanpa membocorkan URL hook', function () {
    Http::fake([HOOK_URL => Http::sequence()->push('internal error at '.HOOK_URL, 500)->push('', 200)]);
    $marketing = penerbit();
    menuTerbit();

    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'Hosting website menolak permintaan (kode 500).')
            && ! str_contains($message, 'rahasia'));

    // Terbit gagal tidak menyimpan snapshot: website tetap memakai terbitan sebelumnya.
    expect(WebsitePublish::query()->sole())->status->toBe(WebsitePublish::STATUS_FAILED)->snapshot->toBeNull();

    // Terbit yang gagal tidak memicu jeda: boleh langsung dicoba lagi.
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertSessionHas('success');
});

it('menolak terbit bila website belum tersambung', function () {
    config(['website.deploy_hook_url' => null]);
    Http::fake();
    $marketing = penerbit();

    $this->actingAs($marketing)->get(route('marketingapp.dashboard'))->assertOk()->assertSee('Belum tersambung');
    $this->actingAs($marketing)->get(route('marketingapp.website.preview'))->assertOk()->assertSee('Website belum tersambung ke 3S ONE.');
    $this->actingAs($marketing)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'belum tersambung'));

    Http::assertNothingSent();
    expect(WebsitePublish::query()->count())->toBe(0);
});

it('hanya marketing, owner, dan superadmin yang bisa melihat pratinjau dan menerbitkan', function () {
    Http::fake([HOOK_URL => Http::response()]);
    menuTerbit();

    foreach (['admin', 'sales', 'inventory'] as $role) {
        $user = penerbit($role);
        $this->actingAs($user)->get(route('marketingapp.website.preview'))->assertForbidden();
        $this->actingAs($user)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertForbidden();
    }
    Http::assertNothingSent();

    // Kontrol positif: owner boleh.
    $owner = penerbit('owner');
    $this->actingAs($owner)->get(route('marketingapp.website.preview'))->assertOk();
    $this->actingAs($owner)->post(route('marketingapp.website.publish'), ['feed_version' => versiPratinjau()])->assertSessionHas('success');
    Http::assertSentCount(1);
});
