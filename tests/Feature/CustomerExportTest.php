<?php

use App\Exports\CustomersExport;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;

/**
 * Admin › Customer › Export Excel: daftar follow up untuk tim marketing.
 * Isi mengikuti tab status & pencarian; angka dari PO completed.
 * Berkas yang benar-benar diunduh dibaca ulang, bukan Excel::fake().
 */
function exportUser(string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function exportCustomer(array $attributes): Customer
{
    $area = App\Models\Area::query()->firstOrCreate(['name' => 'Area Barat']);

    return Customer::query()->create($attributes + ['area_id' => $area->id, 'active' => true]);
}

function exportPo(Customer $customer, string $date, float $total, string $status = 'completed'): void
{
    $po = PurchaseOrder::query()->create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'shipping_address' => 'Jl. Tes',
        'area_id' => $customer->area_id,
        'delivery_date' => $date,
        'delivery_time' => '09:00:00',
        'payment_type' => 'cash',
        'status' => $status,
        'completed_at' => $status === 'completed' ? $date.' 12:00:00' : null,
        'created_by' => User::factory()->create()->id,
    ]);
    PurchaseOrder::query()->whereKey($po->id)->update(['total_amount' => $total]);
}

/** Unduh lewat route asli lalu baca lembarnya. */
function downloadSheet($test, User $user, array $query = []): Worksheet
{
    $response = $test->actingAs($user)->get(route('adminapp.customers.export.excel', $query));
    $response->assertOk()->assertDownload();

    return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
}

/** @return array<string, array<string, mixed>> baris data dikunci per nama, kolom per judul */
function sheetRows(Worksheet $sheet): array
{
    $rows = $sheet->toArray(null, false, false, false);
    $headings = array_shift($rows);

    return collect($rows)->mapWithKeys(fn ($row) => [$row[0] => array_combine($headings, $row)])->all();
}

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 11, 25)->startOfDay()));

it('mengekspor semua customer dengan kontak, status, dan omzet dari PO selesai saja', function () {
    $lama = exportCustomer(['name' => 'Kantin Lama', 'phone' => '0812-3456-7890', 'address' => 'Jl. Melati 1', 'is_lapak' => true]);
    $aktif = exportCustomer(['name' => 'Kantin Aktif', 'phone' => '+62 813 1111 2222']);
    exportCustomer(['name' => 'Kantin Belum', 'phone' => '021-555']);
    exportCustomer(['name' => Customer::INTERNAL_READY_STOCK, 'is_internal' => true]);

    exportPo($lama, '2026-07-01', 250_000);
    exportPo($lama, '2026-08-15', 150_000);
    exportPo($aktif, '2026-06-01', 100_000);
    exportPo($aktif, '2026-11-20', 175_000);
    exportPo($aktif, '2026-11-24', 999_000, 'draft'); // draft: bukan penjualan

    $sheet = downloadSheet($this, exportUser());
    $rows = sheetRows($sheet);

    // Customer internal (pembawa Barang Sisa) bukan pembeli: tidak ikut.
    expect(array_keys($rows))->toBe(['Kantin Aktif', 'Kantin Belum', 'Kantin Lama'])
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:O4');

    expect($rows['Kantin Lama'])->toMatchArray([
        'Telepon' => '0812-3456-7890',
        'Link WhatsApp' => 'https://wa.me/6281234567890',
        'Alamat' => 'Jl. Melati 1',
        'Area' => 'Area Barat',
        'Lapak' => 'Ya',
        'Status' => 'Lama tidak order',
        'Hari Sejak Order Terakhir' => 102, // 15 Agu → 25 Nov
        'Total PO' => 2,
        'Total Omzet' => 400_000,
        'PO 90 Hari' => 0,
    ]);
    expect(Date::excelToDateTimeObject($rows['Kantin Lama']['Order Pertama'])->format('Y-m-d'))->toBe('2026-07-01')
        ->and(Date::excelToDateTimeObject($rows['Kantin Lama']['Order Terakhir'])->format('Y-m-d'))->toBe('2026-08-15');

    expect($rows['Kantin Aktif'])->toMatchArray([
        'Link WhatsApp' => 'https://wa.me/6281311112222',
        'Status' => 'Aktif',
        'Total PO' => 2,
        'Total Omzet' => 275_000,
        'PO 90 Hari' => 1,
        'Omzet 90 Hari' => 175_000,
    ]);

    // Belum pernah order + nomor kantor: tanpa link WA, tanggal kosong.
    expect($rows['Kantin Belum'])->toMatchArray([
        'Link WhatsApp' => null,
        'Status' => 'Belum pernah order',
        'Order Terakhir' => null,
        'Total PO' => 0,
    ]);
});

it('mengikuti tab status dan pencarian yang sedang dibuka', function () {
    $lama1 = exportCustomer(['name' => 'Warung Lama', 'phone' => '0811']);
    $lama2 = exportCustomer(['name' => 'Kantin Lama']);
    $aktif = exportCustomer(['name' => 'Warung Aktif']);
    exportPo($lama1, '2026-08-01', 100_000);
    exportPo($lama2, '2026-08-01', 100_000);
    exportPo($aktif, '2026-11-20', 100_000);
    $admin = exportUser();

    expect(array_keys(sheetRows(downloadSheet($this, $admin, ['status' => 'lama']))))->toBe(['Kantin Lama', 'Warung Lama'])
        ->and(array_keys(sheetRows(downloadSheet($this, $admin, ['status' => 'lama', 'q' => 'warung']))))->toBe(['Warung Lama'])
        // Status asal-asalan diabaikan = Semua (tidak error, tidak kosong).
        ->and(array_keys(sheetRows(downloadSheet($this, $admin, ['status' => 'ngawur']))))->toHaveCount(3);

    // Nama berkas menyebut tab-nya.
    $this->actingAs($admin)->get(route('adminapp.customers.export.excel', ['status' => 'lama']))
        ->assertDownload('customer_lama_20261125_000000.xlsx');
});

it('menyimpan telepon sebagai teks supaya angka 0 di depan dan 62xxx tidak rusak di Excel', function () {
    exportCustomer(['name' => 'A', 'phone' => '6281234567890']);
    exportCustomer(['name' => 'B', 'phone' => '081234567890']);

    $sheet = downloadSheet($this, exportUser());

    expect($sheet->getCell('B2')->getDataType())->toBe('s')
        ->and($sheet->getCell('B2')->getValue())->toBe('6281234567890')
        ->and($sheet->getCell('B3')->getValue())->toBe('081234567890');
});

it('membuat link WhatsApp hanya dari nomor HP Indonesia yang masuk akal', function (?string $phone, ?string $expected) {
    expect(CustomersExport::whatsappLink($phone))->toBe($expected);
})->with([
    'awalan 0' => ['0812 3456 7890', 'https://wa.me/6281234567890'],
    'awalan +62' => ['+62 812-3456-7890', 'https://wa.me/6281234567890'],
    'awalan 8' => ['81234567890', 'https://wa.me/6281234567890'],
    'dua nomor, ambil pertama' => ['0812345678 / 0899999999', 'https://wa.me/62812345678'],
    'terlalu pendek' => ['0811', null],
    'kosong' => [null, null],
    'tanda strip' => ['-', null],
]);

it('membatasi export customer untuk aplikasi Admin', function () {
    exportCustomer(['name' => 'Rahasia', 'phone' => '081234567890']);

    $this->get(route('adminapp.customers.export.excel'))->assertRedirect(route('login'));

    foreach (['sales', 'delivery', 'inventory', 'marketing'] as $role) {
        $this->actingAs(exportUser($role))->get(route('adminapp.customers.export.excel'))->assertForbidden();
    }

    // Kontrol positif: admin & owner bisa mengunduh, isinya ada.
    foreach (['admin', 'owner'] as $role) {
        expect(sheetRows(downloadSheet($this, exportUser($role))))->toHaveKey('Rahasia');
    }
});

it('menampilkan tombol export yang membawa tab dan pencarian aktif', function () {
    $this->actingAs(exportUser())->get(route('adminapp.customers.index', ['status' => 'lama', 'q' => 'warung']))
        ->assertOk()
        ->assertSee('Export Excel')
        ->assertSee(route('adminapp.customers.export.excel', ['status' => 'lama', 'q' => 'warung']));
});
