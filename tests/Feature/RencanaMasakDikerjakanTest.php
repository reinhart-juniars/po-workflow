<?php

use App\Filament\Pages\ReadyStockPlan;
use App\Filament\Resources\ProductionOrderResource\Pages\CreateProductionOrder;
use App\Models\ProductionOrder;
use App\Models\ProductionTask;
use App\Models\User;
use App\Services\WorkSheetRecapService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * "Dikerjakan Tanggal" Rencana Masak: rencana tanggal 5 dimasak tanggal 4.
 *
 * Tanggal rencana tetap mengikuti tanggal kirim PO (distribusi stok siap membacanya);
 * tanggal dikerjakan hanya memberi tahu dapur kapan memasak, dan menentukan hari
 * baris Lembar Kerja di Rekap Lembar Kerja.
 */
function penggunaDikerjakan(string $role): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole($role);

    return $user;
}

function rencanaDikerjakan(string $date, array $extra = []): ProductionOrder
{
    return ProductionOrder::query()->create($extra + ['title' => 'Rencana '.$date, 'is_ready_stock' => true, 'production_date' => $date]);
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-03 09:00')));

it('membuat rencana dengan tanggal dikerjakan, bawaannya H-1', function () {
    // Jalur impor Excel & lainnya: tanpa isian = sehari sebelum tanggal rencana.
    expect(rencanaDikerjakan('2026-11-05')->work_date->toDateString())->toBe('2026-11-04');

    $this->actingAs(penggunaDikerjakan('inventory'));

    Livewire::test(CreateProductionOrder::class)
        ->fillForm(['production_date' => '2026-11-06', 'work_date' => '2026-11-04', 'lines' => []])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = ProductionOrder::query()->whereDate('production_date', '2026-11-06')->firstOrFail();
    expect($plan->is_ready_stock)->toBeTrue()
        ->and($plan->work_date->toDateString())->toBe('2026-11-04')
        // Tanggal rencana tidak bergeser: distribusi ke PO membacanya.
        ->and($plan->production_date->toDateString())->toBe('2026-11-06')
        ->and($plan->defaultTaskDaysBefore())->toBe(2);
});

it('menolak tanggal dikerjakan sesudah tanggal rencana atau lebih awal dari H-7', function () {
    $this->actingAs(penggunaDikerjakan('inventory'));

    $isi = fn (string $work) => Livewire::test(CreateProductionOrder::class)
        ->fillForm(['production_date' => '2026-11-10', 'work_date' => $work, 'lines' => []])
        ->call('create');

    $isi('2026-11-11')->assertHasFormErrors(['work_date']);
    $isi('2026-11-02')->assertHasFormErrors(['work_date']);
    expect(ProductionOrder::query()->count())->toBe(0);

    // Kontrol positif: batas H-7 diterima.
    $isi('2026-11-03')->assertHasNoFormErrors();
    expect(ProductionOrder::query()->count())->toBe(1);
});

it('membaca rencana lama tanpa isian sebagai H-1', function () {
    $lama = rencanaDikerjakan('2026-11-05');
    ProductionOrder::query()->whereKey($lama->id)->update(['work_date' => null]);
    $lama = $lama->fresh();

    expect($lama->work_date)->toBeNull()
        ->and($lama->workDate()->toDateString())->toBe('2026-11-04')
        ->and($lama->defaultTaskDaysBefore())->toBe(ProductionTask::DEFAULT_DAYS_BEFORE);
});

it('mengubah tanggal dikerjakan dari halaman Rencana Masak dan menggeser baris Lembar Kerja yang mengikutinya', function () {
    $plan = rencanaDikerjakan('2026-11-05');
    $ikut = $plan->tasks()->create(['task' => 'masak', 'object' => 'ayam', 'days_before' => 1]);
    $sendiri = $plan->tasks()->create(['task' => 'potong', 'object' => 'bawang', 'days_before' => 3]);

    $this->actingAs(penggunaDikerjakan('inventory'));

    Livewire::test(ReadyStockPlan::class)
        ->assertSee('Dikerjakan')
        ->callAction('changeWorkDate', data: ['work_date' => '2026-11-03'], arguments: ['plan' => $plan->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Tanggal dikerjakan disimpan');

    expect($plan->fresh()->work_date->toDateString())->toBe('2026-11-03')
        ->and($plan->fresh()->production_date->toDateString())->toBe('2026-11-05')
        ->and($ikut->fresh()->days_before)->toBe(2)
        // Baris yang sengaja dimajukan sendiri tetap.
        ->and($sendiri->fresh()->days_before)->toBe(3);

    // Rekap dapur ikut pindah ke hari yang baru.
    $rekap = fn (string $tanggal) => collect(app(WorkSheetRecapService::class)->forDate(Carbon::parse($tanggal))['rows'])->pluck('object')->all();
    expect($rekap('2026-11-03'))->toBe(['ayam'])
        ->and($rekap('2026-11-04'))->toBe([]);
});

it('menolak tanggal di luar batas dan menyembunyikan aksinya dari peran yang hanya melihat', function () {
    $plan = rencanaDikerjakan('2026-11-05');
    $this->actingAs(penggunaDikerjakan('inventory'));

    Livewire::test(ReadyStockPlan::class)
        ->callAction('changeWorkDate', data: ['work_date' => '2026-11-06'], arguments: ['plan' => $plan->id])
        ->assertHasActionErrors(['work_date']);
    expect($plan->fresh()->work_date->toDateString())->toBe('2026-11-04');

    // Accounting boleh membaca halaman (production.view) tetapi tidak mengubah.
    $this->actingAs(penggunaDikerjakan('accounting'));
    Livewire::test(ReadyStockPlan::class)
        ->assertOk()
        ->assertActionHidden('changeWorkDate', ['plan' => $plan->id]);
});

it('memakai tanggal dikerjakan sebagai bawaan baris Lembar Kerja', function () {
    expect(rencanaDikerjakan('2026-11-05', ['work_date' => '2026-11-02'])->defaultTaskDaysBefore())->toBe(3);

    // SPK slot biasa tetap H-1 dan tidak menyimpan tanggal dikerjakan.
    $slot = ProductionOrder::query()->create(['title' => 'SPK slot', 'production_date' => '2026-11-05']);
    expect($slot->work_date)->toBeNull()
        ->and($slot->defaultTaskDaysBefore())->toBe(ProductionTask::DEFAULT_DAYS_BEFORE);
});
