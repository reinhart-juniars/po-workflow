<?php

use App\Filament\Pages\WorkSheetRecap;
use App\Filament\Resources\ProductionOrderResource\Pages\ProductionTasks;
use App\Models\DailyChore;
use App\Models\ProductionOrder;
use App\Models\ProductionTask;
use App\Models\User;
use App\Services\WorkSheetRecapService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Lembar Kerja per hari kerja (H-n) dan Rekap Lembar Kerja.
 *
 * Dapur bekerja per hari, bukan per SPK: satu hari berisi H-1 dari SPK besok
 * (beberapa slot) dan persiapan H-2 / H-3 dari SPK sesudahnya. Yang dijaga:
 * tiap baris masuk ke hari kerja yang benar, baris yang sama digabung seperti
 * rekap manual dapur ("160+90 gr"), dan SPK batal tidak ikut.
 */
function penggunaProduksi(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function spkProduksi(string $date, ?string $time = null, string $status = ProductionOrder::STATUS_DRAFT): ProductionOrder
{
    return ProductionOrder::query()->create([
        'title' => 'SPK '.$date.' '.$time, 'production_date' => $date, 'production_time' => $time, 'status' => $status,
    ]);
}

function tugas(ProductionOrder $order, string $worker, string $task, string $object, ?string $qty, string $menu, int $daysBefore = 1): ProductionTask
{
    return $order->tasks()->create([
        'worker_name' => $worker, 'task' => $task, 'object' => $object, 'quantity_text' => $qty,
        'menu_label' => $menu, 'days_before' => $daysBefore, 'sort_order' => $order->tasks()->count() + 1,
    ]);
}

beforeEach(function () {
    // SPK tanggal 16: slot 03.00 dan 07.00 -> dikerjakan H-1 = tanggal 15.
    $this->slot03 = spkProduksi('2026-10-16', '03.00');
    $this->slot07 = spkProduksi('2026-10-16', '07.00');

    tugas($this->slot03, 'yuni', 'potong', 'daun bawang', '160 gr', 'Nasi Goreng Hongkong');
    tugas($this->slot03, 'mia', 'buat', 'acar nasgor', '40 porsi', 'Nasi Goreng Hongkong');
    tugas($this->slot03, 'mia', 'buat', 'sambal matah', '30 porsi', 'Nasi Ayam Geprek', 3); // H-3 = tanggal 13
    tugas($this->slot07, 'Mia ', 'buat', 'Acar  Nasgor', '30 porsi', 'Nasi Goreng Hongkong');
    tugas($this->slot07, 'yuni', 'potong', 'daun bawang', '90 gram', 'Bakmi Goreng Telur');

    // SPK tanggal 17, persiapan H-2 = tanggal 15.
    tugas(spkProduksi('2026-10-17', '07.00'), 'yuni', 'potong', 'wortel', '200 gr', 'Nasi Fuyunghai', 2);

    // SPK batal tidak ikut, walau tanggal kerjanya cocok.
    tugas(spkProduksi('2026-10-16', '11.00', ProductionOrder::STATUS_CANCELLED), 'tono', 'potong', 'ayam', '30 ptng', 'Ayam Geprek');
});

it('mengumpulkan pekerjaan semua SPK ke tanggal kerjanya dan menggabungkan baris yang sama', function () {
    $recap = app(WorkSheetRecapService::class)->forDate(Carbon::parse('2026-10-15'));
    $rows = collect($recap['rows']);

    expect($rows->map(fn ($r) => [$r['worker'], $r['task'], $r['object'], $r['quantity'], $r['menus']])->all())->toBe([
        ['yuni', 'potong', 'daun bawang', '160+90 gram', ['Nasi Goreng Hongkong', 'Bakmi Goreng Telur']],
        ['mia', 'buat', 'acar nasgor', '40+30 porsi', ['Nasi Goreng Hongkong']],
        ['yuni', 'potong', 'wortel', '200 gram', ['Nasi Fuyunghai']],
    ])
        ->and($rows->first()['count'])->toBe(2)
        ->and($rows->first()['orders'])->toBe([$this->slot03->number, $this->slot07->number])
        ->and(collect($recap['orders'])->pluck('days')->all())->toBe([1, 1, 2]);

    // Persiapan H-3 masuk ke hari kerjanya sendiri, bukan ke tanggal 15.
    $tanggal13 = app(WorkSheetRecapService::class)->forDate(Carbon::parse('2026-10-13'));
    expect(collect($tanggal13['rows'])->pluck('object')->all())->toBe(['sambal matah']);

    // Diurutkan per pelaksana: mia dulu, lalu yuni (urutan menu di dalamnya tetap).
    $perPelaksana = app(WorkSheetRecapService::class)->forDate(Carbon::parse('2026-10-15'), WorkSheetRecapService::SORT_WORKER);
    expect(collect($perPelaksana['rows'])->pluck('object')->all())->toBe(['acar nasgor', 'daun bawang', 'wortel']);
});

it('menulis jumlah gabungan tanpa menjumlahkan satuan yang berbeda', function () {
    $service = app(WorkSheetRecapService::class);

    expect($service->joinQuantities(collect(['120 gr', '90 gram', '50 g'])))->toBe('120+90+50 gram')
        ->and($service->joinQuantities(collect(['4+3 kg', '2 kg'])))->toBe('4+3+2 kg')
        ->and($service->joinQuantities(collect(['2 kg', '300 gr'])))->toBe('2 kg + 300 gr')
        ->and($service->joinQuantities(collect(['secukupnya', '1 ikat'])))->toBe('secukupnya + 1 ikat')
        ->and($service->joinQuantities(collect([null, ''])))->toBeNull();
});

it('menyimpan hari kerja tiap baris dari Lembar Kerja dengan bawaan H-1', function () {
    $this->actingAs(penggunaProduksi('production'));

    $order = spkProduksi('2026-10-20', '07.00');

    $page = Livewire::test(ProductionTasks::class, ['record' => $order->getRouteKey()]);
    $page->set('data.tasks', [
        'baru-1' => ['task' => 'potong', 'object' => 'bawang', 'quantity_text' => '200 gr', 'worker_name' => null, 'recipe_id' => null, 'menu_label' => null, 'days_before' => 3, 'is_done' => false],
        'baru-2' => ['task' => 'goreng', 'object' => 'ayam', 'quantity_text' => '30 ptng', 'worker_name' => null, 'recipe_id' => null, 'menu_label' => null, 'days_before' => 1, 'is_done' => false],
    ])->call('save')->assertHasNoErrors();

    $tasks = $order->tasks()->get();
    expect($tasks->pluck('days_before', 'object')->all())->toBe(['bawang' => 3, 'ayam' => 1])
        ->and($tasks->firstWhere('object', 'bawang')->workDate()->toDateString())->toBe('2026-10-17');

    // Baris yang dibuat tanpa menyebut hari kerja jatuh ke H-1.
    $lama = $order->tasks()->create(['task' => 'rebus', 'object' => 'mie'])->fresh();
    expect($lama->days_before)->toBe(ProductionTask::DEFAULT_DAYS_BEFORE);
});

it('menampilkan rekap dan tugas umum harian di halaman rekap', function () {
    $this->actingAs(penggunaProduksi('production'));

    Livewire::withQueryParams(['tanggal' => '2026-10-15'])
        ->test(WorkSheetRecap::class)
        ->assertOk()
        ->assertSee('160+90 gram')
        ->assertSee('40+30 porsi')
        ->assertDontSee('sambal matah')
        ->callAction('tugasUmum', data: ['chores' => [
            ['id' => null, 'task' => 'cek stock gudang dan kemasan', 'worker_name' => 'dimas & aldo', 'is_active' => true],
            ['id' => null, 'task' => 'doa syukur + pulang', 'worker_name' => 'semua tim', 'is_active' => true],
        ]])
        ->assertHasNoActionErrors()
        ->assertSee('cek stock gudang dan kemasan')
        ->assertSee('doa syukur + pulang');

    expect(DailyChore::query()->orderBy('sort_order')->pluck('task')->all())
        ->toBe(['cek stock gudang dan kemasan', 'doa syukur + pulang']);

    // Tugas yang dihapus dari daftar ikut hilang.
    $doa = DailyChore::query()->firstWhere('task', 'doa syukur + pulang');
    // Array repeater diganti utuh (callAction mengisi per kunci, jadi baris
    // hasil pra-isi akan tetap ada).
    Livewire::test(WorkSheetRecap::class)
        ->mountAction('tugasUmum')
        ->set('mountedActionsData.0.chores', [
            'a' => ['id' => $doa->id, 'task' => 'doa syukur + pulang', 'worker_name' => 'semua tim', 'is_active' => true],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DailyChore::query()->pluck('task')->all())->toBe(['doa syukur + pulang']);
});

it('membuka rekap untuk gudang tetapi hanya produksi yang mengubah tugas umum', function () {
    // Gudang (production.view) boleh melihat dan mencetak rekap.
    $this->actingAs(penggunaProduksi('inventory'));

    Livewire::withQueryParams(['tanggal' => '2026-10-15'])
        ->test(WorkSheetRecap::class)
        ->assertOk()
        ->assertSee('160+90 gram')
        ->assertActionVisible('excel')
        ->assertActionHidden('tugasUmum');

    // Peran tanpa izin produksi sama sekali tidak bisa membuka.
    $this->actingAs(penggunaProduksi('sales'));
    Livewire::test(WorkSheetRecap::class)->assertForbidden();
});

it('mengunduh rekap sebagai Excel dan PDF', function () {
    $this->actingAs(penggunaProduksi('production'));
    DailyChore::query()->create(['task' => 'buang sampah', 'worker_name' => 'aldo', 'sort_order' => 1]);

    Livewire::withQueryParams(['tanggal' => '2026-10-15'])
        ->test(WorkSheetRecap::class)
        ->callAction('excel')
        ->assertFileDownloaded('rekap-lembar-kerja-20261015.xlsx');

    Livewire::withQueryParams(['tanggal' => '2026-10-15'])
        ->test(WorkSheetRecap::class)
        ->callAction('cetak')
        ->assertFileDownloaded('rekap-lembar-kerja-20261015.pdf');

    $export = new \App\Exports\WorkSheetRecapExport(app(WorkSheetRecapService::class)->forDate(Carbon::parse('2026-10-15')));
    $rows = $export->array();

    expect($rows[0])->toBe([1, 'yuni', 'Nasi Goreng Hongkong + Bakmi Goreng Telur', 'potong', 'daun bawang', '160+90 gram', ''])
        ->and(end($rows))->toBe(['', 'aldo', '', 'buang sampah']);
});

it('memuat baris tersimpan di Lembar Kerja dan tidak menghapusnya saat disimpan ulang', function () {
    $this->actingAs(penggunaProduksi('production'));

    // Regresi: form diisi dengan fill() tanpa state, repeater berelasi tidak
    // dimuat -- form tampil kosong dan Simpan menghapus semua baris yang ada.
    $page = Livewire::test(ProductionTasks::class, ['record' => $this->slot03->getRouteKey()]);

    $state = collect($page->get('data.tasks'));
    expect($state)->toHaveCount(3)
        ->and($state->pluck('object')->all())->toBe(['daun bawang', 'acar nasgor', 'sambal matah'])
        ->and($state->pluck('days_before')->all())->toBe([1, 1, 3]);

    $page->call('save')->assertHasNoErrors();

    expect($this->slot03->tasks()->count())->toBe(3)
        ->and($this->slot03->tasks()->firstWhere('object', 'sambal matah')->days_before)->toBe(3);
});
