<?php

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/**
 * Cutover ulang 9 Nov 2026. Tim tetap input di V2 selama 1–7 Nov, sementara
 * tim menu/inventory/produksi bekerja di V3. Database tujuan dibuat dari dump V2
 * final; pekerjaan V3 ditempel ke atasnya.
 *
 * Yang paling dijaga: transaksi V2 utuh, resep/bahan/SPK Produksi V3 pindah
 * dengan ID yang sama, menu yang dibuat di kedua sistem tidak dobel, PO yang
 * diinput dua kali memakai versi V2, dan PO draft yang hanya ada di V3 ikut
 * dengan nomor baru.
 *
 * Tujuan (V2 final) = koneksi tes bawaan (SQLite :memory:); V3 = berkas SQLite
 * terpisah yang dimigrasi dengan skema yang sama.
 */
const GABUNG_BASE = '2026-10-20 08:00:00';

function gabungDaftarkanV3(string $path): void
{
    config(['database.connections.v3_uji' => array_merge(config('database.connections.sqlite'), ['database' => $path])]);
    DB::purge('v3_uji');
}

function gabungDatabaseV3(): string
{
    static $template = null;

    if ($template === null || ! is_file($template)) {
        $template = tempnam(sys_get_temp_dir(), 'v3_tpl_').'.sqlite';
        gabungDaftarkanV3($template);
        Artisan::call('migrate', ['--database' => 'v3_uji', '--force' => true]);
        DB::purge('v3_uji');
    }

    $path = tempnam(sys_get_temp_dir(), 'v3_').'.sqlite';
    copy($template, $path);
    gabungDaftarkanV3($path);

    return $path;
}

function v3(): Connection
{
    return DB::connection('v3_uji');
}

/** Baris dari dump V2 31 Okt: identik di kedua database. */
function gabungKeDuanya(string $table, array $rows): void
{
    DB::table($table)->insert($rows);
    v3()->table($table)->insert($rows);
}

function gabungPo(int $id, string $number, int $customerId, string $delivery, string $status, string $createdAt): array
{
    return ['id' => $id, 'po_number' => $number, 'customer_id' => $customerId, 'recipient_name' => 'x', 'shipping_address' => 'x',
        'area_id' => 1, 'delivery_date' => $delivery, 'delivery_time' => '10:00:00', 'created_by' => 1, 'status' => $status,
        'total_amount' => 100000, 'created_at' => $createdAt, 'updated_at' => $createdAt];
}

/**
 * Keadaan 8 Nov: V2 dan V3 berjalan terpisah sejak 1 Nov.
 *
 * @return string path database V3
 */
function gabungSkenario(): string
{
    $path = gabungDatabaseV3();
    $t = ['created_at' => GABUNG_BASE, 'updated_at' => GABUNG_BASE];

    // --- Dump V2 31 Okt (kedua database) ---
    gabungKeDuanya('users', [
        ['id' => 1, 'name' => 'fia', 'email' => '', 'password' => 'hash-v2-fia'] + $t,
    ]);
    gabungKeDuanya('areas', [['id' => 1, 'name' => 'Surabaya'] + $t]);
    gabungKeDuanya('customers', [['id' => 7, 'name' => 'MELYANA WBM', 'area_id' => 1] + $t]);
    gabungKeDuanya('inventory_items', [['id' => 22, 'name' => 'Bahan Baku', 'unit' => 'All', 'category' => 'bahan_baku', 'is_active' => 1] + $t]);
    gabungKeDuanya('products', [
        ['id' => 10, 'name' => 'NASI GORENG MERAH 10K', 'sku' => 'NASGOR-MERAH', 'base_price' => 10000, 'raw_material_cost' => 5000] + $t,
        ['id' => 11, 'name' => 'ES TEH', 'sku' => 'ES-TEH', 'base_price' => 5000, 'raw_material_cost' => 1000] + $t,
        ['id' => 12, 'name' => 'NASI CAMPUR 12K', 'sku' => 'NASI-CAMPUR', 'base_price' => 12000, 'raw_material_cost' => 6000] + $t,
    ]);

    // --- Hanya di V3 sejak Oktober (dibawa carry 1 Nov) ---
    v3()->table('users')->insert(['id' => 2, 'name' => 'sisil', 'email' => '', 'password' => 'hash-v3-sisil'] + $t);
    $inventoryRole = v3()->table('roles')->insertGetId(['name' => 'inventory', 'guard_name' => 'web'] + $t);
    v3()->table('model_has_roles')->insert(['role_id' => $inventoryRole, 'model_type' => User::class, 'model_id' => 2]);

    // --- Dikerjakan V2 selama 1–7 Nov (hanya tujuan) ---
    $nov = fn (string $day) => ['created_at' => "2026-11-0{$day} 10:00:00", 'updated_at' => "2026-11-0{$day} 10:00:00"];
    DB::table('products')->where('id', 11)->update(['base_price' => 6000, 'updated_at' => '2026-11-04 10:00:00']);
    DB::table('products')->where('id', 12)->update(['base_price' => 13000, 'updated_at' => '2026-11-04 10:00:00']);
    DB::table('products')->insert([
        ['id' => 13, 'name' => 'NASI KATSU SAOS SAMBEL', 'sku' => 'KATSU', 'base_price' => 20000] + $nov('4'),
        ['id' => 14, 'name' => 'BISTIK LIDAH 125K', 'sku' => 'BISTIK-V2', 'base_price' => 125000] + $nov('5'),
        ['id' => 15, 'name' => 'NASI AYAM GEPREK FILLET MAYO', 'sku' => null, 'base_price' => 22000] + $nov('7'),
    ]);
    DB::table('customers')->insert([
        ['id' => 8, 'name' => 'DESY', 'area_id' => 1] + $nov('5'),
        ['id' => 9, 'name' => 'GANI', 'area_id' => 1] + $nov('5'),
    ]);
    DB::table('purchase_orders')->insert([
        gabungPo(50, 'PO-20261101-0001', 7, '2026-11-02', 'completed', '2026-11-01 16:00:00'),
        gabungPo(51, 'PO-20261107-0001', 8, '2026-11-07', 'completed', '2026-11-07 09:00:00'),
    ]);

    // --- Dikerjakan V3 selama 1–7 Nov (hanya sumber) ---
    v3()->table('inventory_items')->insert([
        ['id' => 100, 'parent_id' => 22, 'name' => 'Telur Ayam', 'unit' => 'kg', 'unit_price' => 30000, 'category' => 'bahan_baku', 'is_active' => 1] + $nov('2'),
        ['id' => 101, 'parent_id' => 22, 'name' => 'Beras', 'unit' => 'kg', 'unit_price' => 14000, 'category' => 'bahan_baku', 'is_active' => 1] + $nov('2'),
    ]);
    v3()->table('suppliers')->insert(['id' => 3, 'name' => 'CV Telur Jaya', 'is_active' => 1] + $nov('2'));
    v3()->table('recipes')->insert(['id' => 5, 'name' => 'Nasi Goreng Merah', 'name_norm' => 'nasi goreng merah', 'jenis' => 'utama', 'yield_qty' => 1, 'yield_unit' => 'porsi', 'ohc_pct' => 0.4, 'profit_pct' => 0.25, 'created_by' => 2] + $nov('3'));
    v3()->table('recipe_items')->insert([
        ['recipe_id' => 5, 'sort_order' => 1, 'inventory_item_id' => 100, 'raw_name' => 'telur', 'qty' => 0.06, 'unit' => 'kg'] + $nov('3'),
        ['recipe_id' => 5, 'sort_order' => 2, 'inventory_item_id' => 101, 'raw_name' => 'beras', 'qty' => 0.15, 'unit' => 'kg'] + $nov('3'),
    ]);
    v3()->table('production_orders')->insert(['id' => 155, 'number' => 'SPKP-20261102-0001', 'production_date' => '2026-11-02', 'status' => 'completed', 'created_by' => 2] + $nov('2'));
    v3()->table('production_order_lines')->insert(['production_order_id' => 155, 'recipe_id' => 5, 'label' => 'Nasi Goreng Merah', 'qty' => 40] + $nov('2'));
    v3()->table('requisitions')->insert(['id' => 1, 'number' => 'FKB-20261102-0001', 'production_order_id' => 155, 'prepared_by' => 2] + $nov('2'));
    v3()->table('requisition_lines')->insert(['id' => 1, 'requisition_id' => 1, 'inventory_item_id' => 100, 'name' => 'Telur Ayam', 'unit' => 'kg'] + $nov('2'));
    v3()->table('inventory_movements')->insert([
        ['inventory_item_id' => 100, 'moved_at' => '2026-11-02', 'type' => 'opening', 'qty' => 10, 'unit' => 'kg', 'production_order_id' => null, 'requisition_line_id' => null, 'created_by' => 2] + $nov('2'),
        ['inventory_item_id' => 100, 'moved_at' => '2026-11-02', 'type' => 'usage', 'qty' => -2.4, 'unit' => 'kg', 'production_order_id' => 155, 'requisition_line_id' => 1, 'created_by' => 2] + $nov('2'),
    ]);

    // Menu: harga & resep diubah tim menu (V3 menang), ES TEH tidak disentuh V3 (V2 tetap).
    v3()->table('products')->where('id', 10)->update(['base_price' => 12000, 'raw_material_cost' => 5687.84, 'recipe_id' => 5, 'updated_at' => '2026-11-03 10:00:00']);
    v3()->table('products')->where('id', 12)->update(['base_price' => 14000, 'updated_at' => '2026-11-05 10:00:00']);
    // Menu baru V3: dua sama dengan menu baru V2 (ID bentrok), satu benar-benar baru.
    v3()->table('products')->insert([
        ['id' => 13, 'name' => 'BISTIK LIDAH 125K', 'sku' => 'BISTIK-LIDAH-125K', 'base_price' => 125000, 'raw_material_cost' => 60000] + $nov('5'),
        ['id' => 14, 'name' => 'NASI AYAM FILLET GEPREK MAYO', 'sku' => 'GEPREK-MAYO', 'base_price' => 23000, 'raw_material_cost' => 11000] + $nov('7'),
        ['id' => 15, 'name' => 'MENU BARU V3', 'sku' => 'KATSU', 'base_price' => 30000, 'raw_material_cost' => 15000] + $nov('7'),
    ]);
    v3()->table('product_price_histories')->insert(['product_id' => 13, 'base_price' => 125000, 'effective_from' => '2026-11-05 10:00:00'] + $nov('5'));

    // Customer & PO yang diinput Fia di V3.
    v3()->table('customers')->insert(['id' => 8, 'name' => 'GANI', 'area_id' => 1] + $nov('5'));
    v3()->table('purchase_orders')->insert([
        gabungPo(50, 'PO-20261101-0001', 7, '2026-11-02', 'draft', '2026-11-01 16:00:00'),   // juga diinput di V2
        gabungPo(51, 'PO-20261107-0001', 8, '2026-11-12', 'draft', '2026-11-07 13:00:00'),   // hanya di V3
    ]);
    v3()->table('purchase_order_items')->insert([
        ['purchase_order_id' => 50, 'product_id' => 10, 'qty' => 10, 'unit_price' => 10000, 'subtotal' => 100000] + $nov('1'),
        ['purchase_order_id' => 51, 'product_id' => 13, 'qty' => 1, 'unit_price' => 125000, 'subtotal' => 125000] + $nov('7'),
    ]);

    return $path;
}

function gabungJalankan(string $path, array $options = [])
{
    return artisan('cutover:merge-v3', array_merge(['--from-database' => $path, '--force' => true], $options));
}

it('menempelkan pekerjaan V3 ke database V2 final tanpa menimpa transaksi V2', function () {
    $path = gabungSkenario();

    gabungJalankan($path)->expectsOutputToContain('Data V3 tergabung')->assertSuccessful();

    // Bahan, resep, SPK Produksi, form kebutuhan, dan pergerakan stok: ID sama.
    expect(DB::table('inventory_items')->find(100)->parent_id)->toBe(22)
        ->and(Recipe::findOrFail(5)->items->pluck('inventory_item_id')->all())->toBe([100, 101])
        ->and(DB::table('production_orders')->find(155)->number)->toBe('SPKP-20261102-0001')
        ->and(DB::table('requisition_lines')->find(1)->inventory_item_id)->toBe(100)
        ->and(DB::table('inventory_movements')->where('production_order_id', 155)->value('requisition_line_id'))->toBe(1)
        ->and(DB::table('inventory_movements')->count())->toBe(2)
        ->and(DB::table('suppliers')->find(3)->name)->toBe('CV Telur Jaya');

    // Menu: V3 menang untuk yang disentuh V3; ES TEH yang hanya diubah V2 tetap versi V2.
    $nasgor = DB::table('products')->find(10);
    expect((float) $nasgor->base_price)->toBe(12000.0)
        ->and((float) $nasgor->raw_material_cost)->toBe(5687.84)
        ->and($nasgor->recipe_id)->toBe(5)
        ->and((float) DB::table('products')->find(11)->base_price)->toBe(6000.0)
        ->and((float) DB::table('products')->find(12)->base_price)->toBe(14000.0);

    // Menu dobel digabung ke ID V2 (nama & harga dari V3); menu V2 yang ID-nya bentrok utuh.
    expect(DB::table('products')->find(13)->name)->toBe('NASI KATSU SAOS SAMBEL')
        ->and((float) DB::table('products')->find(14)->raw_material_cost)->toBe(60000.0)
        ->and(DB::table('products')->find(14)->sku)->toBe('BISTIK-LIDAH-125K')
        ->and(DB::table('products')->find(15)->name)->toBe('NASI AYAM FILLET GEPREK MAYO')
        ->and(DB::table('products')->where('name', 'like', '%BISTIK%')->count())->toBe(1);
    // Menu yang benar-benar baru dibuat dengan ID baru; SKU-nya bentrok dengan menu V2, jadi dilewati.
    $baru = DB::table('products')->where('name', 'MENU BARU V3')->first();
    expect($baru->id)->toBeGreaterThan(15)->and($baru->sku)->toBeNull()
        ->and(DB::table('products')->find(13)->sku)->toBe('KATSU');
    expect(DB::table('product_price_histories')->where('product_id', 14)->count())->toBe(1);

    // PO dobel memakai versi V2 (completed); PO yang hanya di V3 dibawa dengan nomor baru.
    expect(DB::table('purchase_orders')->find(50)->status)->toBe('completed')
        ->and(DB::table('purchase_orders')->find(51)->customer_id)->toBe(8)
        ->and(DB::table('purchase_orders')->count())->toBe(3);
    $dibawa = DB::table('purchase_orders')->where('status', 'draft')->first();
    expect($dibawa->po_number)->toBe('PO-20261107-0002')
        ->and($dibawa->customer_id)->toBe(9) // GANI V3 #8 = GANI V2 #9 (DESY memakai #8 di V2)
        ->and(DB::table('purchase_order_items')->where('purchase_order_id', $dibawa->id)->value('product_id'))->toBe(14);

    // Akun hanya-V3 ikut dengan ID & perannya; akun V2 tidak berubah.
    expect(User::findOrFail(2)->hasRole('inventory'))->toBeTrue()
        ->and(User::findOrFail(1)->getAuthPassword())->toBe('hash-v2-fia');
});

it('melaporkan menu yang diubah di kedua sistem dan PO yang diinput dua kali', function () {
    $path = gabungSkenario();

    gabungJalankan($path)
        ->expectsOutputToContain('Menu diubah di V2 dan V3')
        ->expectsOutputToContain('PO-20261101-0001 = V2 PO-20261101-0001')
        ->assertSuccessful();
});

it('berhenti tanpa menulis apa pun bila V3 punya transaksi sejak 1 Nov yang belum ada aturannya', function () {
    $path = gabungSkenario();
    v3()->table('sales_actuals')->insert(['sales_date' => '2026-11-05', 'customer_id' => 7, 'status' => 'submitted', 'created_at' => '2026-11-05 12:00:00', 'updated_at' => '2026-11-05 12:00:00']);

    gabungJalankan($path)->expectsOutputToContain('sales_actuals')->assertFailed();
    expect(DB::table('recipes')->count())->toBe(0);

    // Positive control: tanpa transaksi itu, penggabungan berjalan.
    v3()->table('sales_actuals')->delete();
    gabungJalankan($path)->assertSuccessful();
    expect(DB::table('recipes')->count())->toBe(1);
});

it('menolak dijalankan dua kali dan tidak menulis apa pun saat uji-jalan', function () {
    $path = gabungSkenario();

    gabungJalankan($path, ['--dry-run' => true])->expectsOutputToContain('Uji-jalan selesai')->assertSuccessful();
    expect(DB::table('recipes')->count())->toBe(0)
        ->and(DB::table('purchase_orders')->count())->toBe(2)
        ->and(User::find(2))->toBeNull();

    // Positive control: jalan sungguhan menulis; jalan kedua ditolak tanpa menggandakan.
    gabungJalankan($path)->assertSuccessful();
    expect(DB::table('purchase_orders')->count())->toBe(3);

    gabungJalankan($path)->expectsOutputToContain('Tujuan sudah berisi')->assertFailed();
    expect(DB::table('purchase_orders')->count())->toBe(3);
});

it('berhenti bila V2 ikut mengubah item inventory sejak tanggal pisah', function () {
    $path = gabungSkenario();
    DB::table('inventory_items')->where('id', 22)->update(['updated_at' => '2026-11-03 09:00:00']);

    gabungJalankan($path)->expectsOutputToContain('Item inventory diubah di V2')->assertFailed();
    expect(DB::table('inventory_items')->count())->toBe(1);

    // Positive control: tanpa perubahan V2 itu, bahan V3 ikut.
    DB::table('inventory_items')->where('id', 22)->update(['updated_at' => GABUNG_BASE]);
    gabungJalankan($path)->assertSuccessful();
    expect(DB::table('inventory_items')->count())->toBe(3);
});

it('berhenti bila PO V3 sudah diproses', function () {
    $path = gabungSkenario();
    v3()->table('purchase_orders')->where('id', 51)->update(['status' => 'in_progress']);

    gabungJalankan($path)->expectsOutputToContain('hanya PO draft')->assertFailed();
    expect(DB::table('purchase_orders')->count())->toBe(2);
});
