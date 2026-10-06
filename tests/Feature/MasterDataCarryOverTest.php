<?php

use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeCostService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/**
 * Membawa data master dari database latihan Oktober ke database resmi November.
 *
 * Database resmi dibuat dari dump v2 akhir Oktober, jadi baris v2 yang dibuat
 * selama Oktober bisa memakai ID yang di database latihan dipakai bahan. Yang
 * paling dijaga di sini: setelah dipindah, resep tetap menunjuk bahan yang
 * benar dan HPP-nya sama persis, baris v2 tidak tertimpa, dan transaksi latihan
 * tidak ikut.
 *
 * Tujuan = koneksi tes bawaan (SQLite :memory:); latihan = berkas SQLite
 * terpisah yang dimigrasi dengan skema yang sama.
 */
const BAWA_T0 = '2026-09-01 08:00:00';

function bawaDaftarkanLatihan(string $path): void
{
    config(['database.connections.latihan_uji' => array_merge(config('database.connections.sqlite'), ['database' => $path])]);
    DB::purge('latihan_uji');
}

/** Berkas SQLite latihan yang sudah dimigrasi (templat dimigrasi sekali per proses). */
function bawaDatabaseLatihan(): string
{
    static $template = null;

    if ($template === null || ! is_file($template)) {
        $template = tempnam(sys_get_temp_dir(), 'latihan_tpl_').'.sqlite';
        bawaDaftarkanLatihan($template);
        Artisan::call('migrate', ['--database' => 'latihan_uji', '--force' => true]);
        DB::purge('latihan_uji');
    }

    $path = tempnam(sys_get_temp_dir(), 'latihan_').'.sqlite';
    copy($template, $path);
    bawaDaftarkanLatihan($path);

    return $path;
}

function latihan(): Connection
{
    return DB::connection('latihan_uji');
}

/** Baris v2 yang sudah ada sebelum Oktober: identik di kedua database. */
function bawaKeDuanya(string $table, array $rows): void
{
    DB::table($table)->insert($rows);
    latihan()->table($table)->insert($rows);
}

function bawaPeranLatihan(string $name): int
{
    return latihan()->table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => BAWA_T0, 'updated_at' => BAWA_T0]);
}

/**
 * Keadaan 31 Oktober: v2 berjalan sendiri sebulan, latihan dipakai memetakan.
 *
 * @return string path database latihan
 */
function bawaSkenario(): string
{
    $path = bawaDatabaseLatihan();
    $t = ['created_at' => BAWA_T0, 'updated_at' => BAWA_T0];

    // --- Sudah ada di dump v2 awal Oktober (kedua database) ---
    // Akun dapur umumnya tanpa email: pencocokan tidak boleh bergantung pada email saja.
    bawaKeDuanya('users', [
        ['id' => 1, 'name' => 'owner', 'email' => 'owner@w3s.test', 'password' => 'hash-v2-owner'] + $t,
        ['id' => 2, 'name' => 'arum', 'email' => '', 'password' => 'hash-v2-arum'] + $t,
        ['id' => 3, 'name' => 'fia', 'email' => '', 'password' => 'hash-v2-fia'] + $t,
    ]);
    bawaKeDuanya('inventory_items', [['id' => 1, 'name' => 'Bahan Baku', 'unit' => 'All', 'category' => 'bahan_baku', 'is_active' => 1] + $t]);
    bawaKeDuanya('products', [
        ['id' => 1, 'name' => 'NASI TELUR 12K', 'sku' => 'ALACARTE-NASI-TELUR', 'base_price' => 12000] + $t,
        ['id' => 2, 'name' => 'ES TEH', 'sku' => 'ES-TEH', 'base_price' => 5000] + $t,
    ]);
    User::find(1)->assignRole('owner');

    // --- Ditambahkan v2 selama Oktober (hanya tujuan): ID-nya bentrok dengan latihan ---
    DB::table('inventory_items')->insert(['id' => 2, 'name' => 'Gas LPG', 'unit' => 'tabung', 'category' => 'inventaris', 'is_active' => 1, 'created_at' => '2026-10-10 09:00:00', 'updated_at' => '2026-10-10 09:00:00']);
    DB::table('products')->insert(['id' => 3, 'name' => 'MENU BARU OKTOBER', 'sku' => 'MENU-BARU', 'base_price' => 15000, 'created_at' => '2026-10-15 09:00:00', 'updated_at' => '2026-10-15 09:00:00']);
    DB::table('suppliers')->insert(['id' => 1, 'name' => 'Pak Budi', 'is_active' => 1] + $t);
    DB::table('users')->insert(['id' => 4, 'name' => 'budi', 'email' => '', 'password' => 'hash-v2-budi', 'created_at' => '2026-10-20 09:00:00', 'updated_at' => '2026-10-20 09:00:00']);

    // --- Dikerjakan tim di database latihan selama Oktober ---
    $l = latihan();
    // ID 4 di latihan = dedi, di v2 = budi (dibuat terpisah): bukan orang yang sama.
    $l->table('users')->insert(['id' => 4, 'name' => 'dedi', 'email' => '', 'password' => 'hash-latihan-dedi'] + $t);
    $owner = bawaPeranLatihan('owner');
    $production = bawaPeranLatihan('production');
    $marketing = bawaPeranLatihan('marketing');
    $inventory = bawaPeranLatihan('inventory');
    $l->table('model_has_roles')->insert([
        ['role_id' => $owner, 'model_type' => User::class, 'model_id' => 1],
        ['role_id' => $marketing, 'model_type' => User::class, 'model_id' => 1],
        ['role_id' => $production, 'model_type' => User::class, 'model_id' => 4],
        ['role_id' => $inventory, 'model_type' => User::class, 'model_id' => 2],
    ]);

    $l->table('inventory_items')->insert([
        ['id' => 2, 'parent_id' => 1, 'name' => 'Telur Ayam', 'unit' => 'kg', 'unit_price' => 30000, 'category' => 'bahan_baku', 'is_active' => 1] + $t,
        ['id' => 3, 'parent_id' => 1, 'name' => 'Garam', 'unit' => 'kg', 'unit_price' => 10000, 'category' => 'bahan_baku', 'is_active' => 1] + $t,
    ]);
    $l->table('inventory_item_price_histories')->insert([
        ['inventory_item_id' => 2, 'old_unit_price' => 28000, 'new_unit_price' => 30000, 'action' => 'naik', 'source' => 'panel', 'created_by' => 4] + $t,
        ['inventory_item_id' => 2, 'old_unit_price' => 30000, 'new_unit_price' => 99000, 'action' => 'naik', 'source' => 'Form FKB-20261012-0001', 'created_by' => 4] + $t,
    ]);
    $l->table('suppliers')->insert([
        ['id' => 1, 'name' => 'Pak Budi', 'phone' => '0812-0000-1111', 'payment_term_days' => 14, 'is_active' => 1] + $t,
        ['id' => 2, 'name' => 'CV Telur Jaya', 'phone' => null, 'payment_term_days' => null, 'is_active' => 1] + $t,
    ]);
    $l->table('inventory_item_supplier')->insert([
        ['supplier_id' => 1, 'inventory_item_id' => 3] + $t,
        ['supplier_id' => 2, 'inventory_item_id' => 2] + $t,
    ]);

    $l->table('recipes')->insert(['id' => 10, 'name' => 'Nasi Telur', 'name_norm' => 'nasi telur', 'jenis' => 'utama', 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'created_by' => 4] + $t);
    $l->table('recipe_items')->insert([
        ['recipe_id' => 10, 'sort_order' => 1, 'inventory_item_id' => 2, 'raw_name' => 'telur', 'qty' => 2, 'unit' => 'butir'] + $t,
        ['recipe_id' => 10, 'sort_order' => 2, 'inventory_item_id' => 3, 'raw_name' => 'garam', 'qty' => 5, 'unit' => 'gram'] + $t,
    ]);
    $l->table('recipe_tasks')->insert(['recipe_id' => 10, 'sort_order' => 1, 'task' => 'goreng', 'object' => 'telur'] + $t);
    $l->table('recipe_mismatches')->insert(['raw_name' => 'micin', 'raw_name_norm' => 'micin', 'status' => 'resolved', 'resolved_inventory_item_id' => 3, 'resolved_by' => 4] + $t);
    $l->table('inventory_unit_conversions')->insert([
        ['inventory_item_id' => 2, 'from_unit' => 'butir', 'to_unit' => 'gram', 'factor' => 62.5] + $t,
        ['inventory_item_id' => null, 'from_unit' => 'sdm', 'to_unit' => 'gram', 'factor' => 15] + $t,
    ]);
    $l->table('production_workers')->insert(['name' => 'Bu Sri', 'is_active' => 1] + $t);
    $l->table('app_settings')->insert(['key' => 'requisition.update_master_price', 'value' => 'false', 'updated_by' => 1] + $t);

    $l->table('products')->where('id', 1)->update(['recipe_id' => 10, 'sku' => 'NASI-TELUR', 'show_on_website' => 1]);
    $l->table('products')->where('id', 2)->update(['needs_recipe' => 0]);
    $l->table('products')->insert(['id' => 3, 'name' => 'MENU COBA LATIHAN', 'base_price' => 1000, 'created_at' => '2026-10-12 09:00:00', 'updated_at' => '2026-10-12 09:00:00']);

    // Arsip produksi dari Master Menu (dibawa) dan SPK Produksi latihan (ditinggal).
    $l->table('production_orders')->insert([
        ['id' => 1, 'number' => 'MM-SPK-7', 'production_date' => '2026-08-20', 'status' => 'completed', 'source_spk_id' => 7, 'created_by' => 4] + $t,
        ['id' => 2, 'number' => 'SPKP-20261014-0001', 'production_date' => '2026-10-14', 'status' => 'draft', 'source_spk_id' => null, 'created_by' => 4] + $t,
    ]);
    $l->table('production_order_lines')->insert([
        ['production_order_id' => 1, 'recipe_id' => 10, 'label' => 'Nasi Telur', 'qty' => 40] + $t,
        ['production_order_id' => 2, 'recipe_id' => 10, 'label' => 'Nasi Telur', 'qty' => 25] + $t,
    ]);
    $l->table('production_tasks')->insert(['production_order_id' => 1, 'recipe_id' => 10, 'worker_name' => 'Bu Sri', 'task' => 'goreng'] + $t);

    // Transaksi latihan yang harus ditinggal.
    $l->table('notifications')->insert(['id' => 'b0a1c2d3-0000-4000-8000-000000000001', 'type' => 'latihan', 'notifiable_type' => User::class, 'notifiable_id' => 4, 'data' => '{}'] + $t);

    return $path;
}

function bawaJalankan(string $path, array $options = [])
{
    return artisan('inventory:carry-master-data', array_merge(['--from-database' => $path, '--force' => true], $options));
}

it('membawa data master dengan ID bahan baru tanpa menimpa item v2, dan HPP resep tetap sama', function () {
    $path = bawaSkenario();

    bawaJalankan($path)->expectsOutputToContain('Data master terbawa')->assertSuccessful();

    // Item v2 yang ID-nya bentrok tetap utuh; telur mendapat ID baru di bawah bucket yang sama.
    expect(DB::table('inventory_items')->find(2)->name)->toBe('Gas LPG');
    $telur = DB::table('inventory_items')->where('name', 'Telur Ayam')->first();
    expect($telur->id)->not->toBe(2)->and($telur->parent_id)->toBe(1);

    // Resep menunjuk telur yang benar, dan aturan konversi butir->gram ikut:
    // 2 butir x 62,5 g = 125 g x Rp 30.000/kg = 3.750 ; 5 g garam x Rp 10.000/kg = 50.
    $recipe = Recipe::findOrFail(10);
    expect($recipe->items->firstWhere('raw_name', 'telur')->inventory_item_id)->toBe($telur->id);
    $cost = app(RecipeCostService::class)->cost($recipe);
    expect($cost['total_raw'])->toBe(3800.0)->and($cost['issues'])->toBe([]);

    // Aturan umum (tanpa bahan) ikut, histori dari Form Kebutuhan latihan tidak.
    expect(DB::table('inventory_unit_conversions')->whereNull('inventory_item_id')->value('from_unit'))->toBe('sdm');
    expect(DB::table('inventory_item_price_histories')->where('inventory_item_id', $telur->id)->pluck('source')->all())->toBe(['panel']);

    // Akun latihan dibuat dengan password-nya, rujukan created_by dipetakan ke akun itu.
    $dedi = User::where('name', 'dedi')->firstOrFail();
    expect($dedi->id)->not->toBe(4)
        ->and($dedi->getAuthPassword())->toBe('hash-latihan-dedi')
        ->and($dedi->hasRole('production'))->toBeTrue()
        ->and($recipe->created_by)->toBe($dedi->id)
        ->and(DB::table('recipe_mismatches')->where('raw_name', 'micin')->value('resolved_by'))->toBe($dedi->id);
    $garamId = DB::table('inventory_items')->where('name', 'Garam')->value('id');
    expect(DB::table('recipe_mismatches')->where('raw_name', 'micin')->value('resolved_inventory_item_id'))->toBe($garamId);

    // Akun tanpa email dicocokkan ke orangnya sendiri: peran arum tidak nyasar ke fia/budi.
    expect(User::find(2)->hasRole('inventory'))->toBeTrue()
        ->and(User::find(3)->hasRole('inventory'))->toBeFalse()
        ->and(User::find(4)->name)->toBe('budi')
        ->and(User::find(4)->hasRole('production'))->toBeFalse();

    // Akun v2 tidak berubah passwordnya, peran latihan ditambahkan.
    $owner = User::findOrFail(1);
    expect($owner->getAuthPassword())->toBe('hash-v2-owner')
        ->and($owner->hasRole('owner'))->toBeTrue()
        ->and($owner->hasRole('marketing'))->toBeTrue();

    // Supplier backfill v2 dilengkapi, supplier baru ditambah, tautan bahan dipetakan.
    expect(DB::table('suppliers')->find(1)->phone)->toBe('0812-0000-1111');
    $telurJaya = DB::table('suppliers')->where('name', 'CV Telur Jaya')->value('id');
    expect(DB::table('inventory_item_supplier')->where('supplier_id', $telurJaya)->value('inventory_item_id'))->toBe($telur->id);

    // Menu jual: kolom 3S ONE dibawa, nama/harga v2 dan menu Oktober v2 tidak disentuh.
    $nasi = DB::table('products')->find(1);
    expect($nasi->recipe_id)->toBe(10)->and($nasi->sku)->toBe('NASI-TELUR')->and((int) $nasi->show_on_website)->toBe(1)
        ->and((int) DB::table('products')->find(2)->needs_recipe)->toBe(0)
        ->and(DB::table('products')->find(3)->name)->toBe('MENU BARU OKTOBER');

    expect(DB::table('production_workers')->value('name'))->toBe('Bu Sri')
        ->and(DB::table('recipe_tasks')->where('recipe_id', 10)->value('task'))->toBe('goreng')
        ->and(DB::table('app_settings')->where('key', 'requisition.update_master_price')->value('value'))->toBe('false');

    // Arsip Master Menu ikut; SPK Produksi latihan dan notifikasi latihan tertinggal.
    expect(DB::table('production_orders')->pluck('number')->all())->toBe(['MM-SPK-7'])
        ->and(DB::table('production_order_lines')->where('production_order_id', 1)->value('qty'))->toEqual(40)
        ->and(DB::table('production_tasks')->where('production_order_id', 1)->value('worker_name'))->toBe('Bu Sri')
        ->and(DB::table('production_orders')->find(1)->created_by)->toBe($dedi->id)
        ->and(DB::table('notifications')->count())->toBe(0);
});

it('menolak dijalankan dua kali ke tujuan yang sama', function () {
    $path = bawaSkenario();

    // Positive control: jalan pertama berhasil.
    bawaJalankan($path)->assertSuccessful();
    $bahan = DB::table('inventory_items')->count();

    bawaJalankan($path)->expectsOutputToContain('Tujuan sudah berisi')->assertFailed();

    expect(DB::table('inventory_items')->count())->toBe($bahan)
        ->and(DB::table('recipes')->count())->toBe(1);
});

it('tidak menulis apa pun saat uji-jalan', function () {
    $path = bawaSkenario();

    bawaJalankan($path, ['--dry-run' => true])->expectsOutputToContain('Uji-jalan selesai')->assertSuccessful();

    expect(DB::table('recipes')->count())->toBe(0)
        ->and(DB::table('inventory_items')->whereNotNull('parent_id')->count())->toBe(0)
        ->and(User::where('name', 'dedi')->exists())->toBeFalse();

    // Positive control: tanpa --dry-run, data yang sama benar-benar tertulis.
    bawaJalankan($path)->assertSuccessful();
    expect(DB::table('recipes')->count())->toBe(1);
});

it('menolak bila skema kedua database berbeda', function () {
    $path = bawaSkenario();
    $terakhir = latihan()->table('migrations')->orderByDesc('id')->first();
    latihan()->table('migrations')->where('id', $terakhir->id)->delete();

    bawaJalankan($path)->expectsOutputToContain('Skema kedua database berbeda')->assertFailed();
    expect(DB::table('recipes')->count())->toBe(0);

    // Positive control: setelah skemanya sama, perintah berjalan.
    latihan()->table('migrations')->insert((array) $terakhir);
    bawaJalankan($path)->assertSuccessful();
});

it('berhenti tanpa menulis apa pun bila kategori induk bahan tidak ada di v2', function () {
    $path = bawaSkenario();
    latihan()->table('inventory_items')->insert([
        ['id' => 5, 'parent_id' => null, 'name' => 'Bumbu Racik', 'unit' => 'All', 'category' => 'bahan_baku', 'is_active' => 1, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00'],
        ['id' => 6, 'parent_id' => 5, 'name' => 'Lada', 'unit' => 'gram', 'category' => 'bahan_baku', 'is_active' => 1, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00'],
    ]);

    bawaJalankan($path)->expectsOutputToContain('Kategori induk tidak ada di v2: Bumbu Racik')->assertFailed();

    // Satu transaksi: akun dan bahan yang sempat tertulis sebelum kegagalan ikut batal.
    expect(DB::table('inventory_items')->whereNotNull('parent_id')->count())->toBe(0)
        ->and(User::where('name', 'dedi')->exists())->toBeFalse();

    // Positive control: setelah kategori yang sama dibuat di v2, bahannya ikut di bawahnya.
    $bumbu = DB::table('inventory_items')->insertGetId(['name' => 'Bumbu Racik', 'unit' => 'All', 'category' => 'bahan_baku', 'is_active' => 1, 'created_at' => '2026-10-20 09:00:00', 'updated_at' => '2026-10-20 09:00:00']);
    bawaJalankan($path)->assertSuccessful();
    expect(DB::table('inventory_items')->where('name', 'Lada')->value('parent_id'))->toBe($bumbu);
});

it('tidak memasang SKU yang di v2 sudah dipakai menu lain', function () {
    $path = bawaSkenario();
    // Di latihan, marketing memberi NASI TELUR SKU yang selama Oktober dipakai v2 untuk menu barunya.
    latihan()->table('products')->where('id', 1)->update(['sku' => 'MENU-BARU']);

    bawaJalankan($path)->expectsOutputToContain('SKU "MENU-BARU" tidak dipasang ke menu #1')->assertSuccessful();

    $nasi = DB::table('products')->find(1);
    expect($nasi->sku)->toBe('ALACARTE-NASI-TELUR')
        ->and(DB::table('products')->find(3)->sku)->toBe('MENU-BARU')
        // Positive control: kolom lain di menu yang sama tetap dibawa.
        ->and($nasi->recipe_id)->toBe(10);
});

it('mencocokkan baris yang sama walau waktu dibuatnya bergeser', function () {
    $path = bawaSkenario();
    // Salinan dengan zona waktu berbeda menggeser created_at; ID dan nama tetap sama.
    latihan()->table('products')->update(['created_at' => '2026-09-01 15:00:00']);
    latihan()->table('users')->where('id', 1)->update(['created_at' => '2026-09-01 15:00:00']);

    bawaJalankan($path)->assertSuccessful();

    expect(DB::table('products')->find(1)->recipe_id)->toBe(10)
        ->and(User::where('name', 'owner')->count())->toBe(1)
        // Positive control: menu latihan ber-ID 3 dengan nama lain tetap tidak menimpa menu v2.
        ->and(DB::table('products')->find(3)->name)->toBe('MENU BARU OKTOBER');
});

it('berhenti bila akun latihan cocok dengan lebih dari satu akun v2', function () {
    $path = bawaSkenario();
    // Dua akun v2 bernama sama tanpa email; akun latihan bernama itu tidak bisa dipastikan.
    DB::table('users')->insert([
        ['id' => 20, 'name' => 'sri', 'email' => '', 'password' => 'x', 'created_at' => BAWA_T0, 'updated_at' => BAWA_T0],
        ['id' => 21, 'name' => 'sri', 'email' => '', 'password' => 'y', 'created_at' => BAWA_T0, 'updated_at' => BAWA_T0],
    ]);
    latihan()->table('users')->insert(['id' => 30, 'name' => 'sri', 'email' => '', 'password' => 'z', 'created_at' => BAWA_T0, 'updated_at' => BAWA_T0]);

    bawaJalankan($path)->expectsOutputToContain('tidak bisa dicocokkan dengan pasti')->assertFailed();
    expect(DB::table('recipes')->count())->toBe(0);

    // Positive control: setelah salah satu akun v2 dibedakan namanya, perintah berjalan.
    DB::table('users')->where('id', 21)->update(['name' => 'sri dapur']);
    bawaJalankan($path)->assertSuccessful();
    expect(DB::table('users')->where('name', 'sri')->count())->toBe(1);
});

it('menolak bila database latihan sama dengan tujuan', function () {
    artisan('inventory:carry-master-data', ['--from-database' => DB::connection()->getDatabaseName(), '--force' => true])
        ->expectsOutputToContain('Database latihan sama dengan tujuan')
        ->assertFailed();
});

it('meminta nama database latihan', function () {
    artisan('inventory:carry-master-data', ['--force' => true])
        ->expectsOutputToContain('Isi --from-database')
        ->assertFailed();
});
