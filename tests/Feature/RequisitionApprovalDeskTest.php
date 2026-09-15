<?php

use App\Filament\Resources\ProductionOrderResource\Pages\RequisitionForm;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Models\Requisition;
use App\Models\User;
use App\Services\ProductionOrderService;
use App\Services\RequisitionService;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Meja terpisah di Form Kebutuhan: produksi MENGAJUKAN, supervisor gudang
 * MENYETUJUI atau MENOLAK (form kembali ke produksi dengan alasan), gudang
 * menerima barang. Tiap perpindahan meja mengirim lonceng ke meja berikutnya.
 */
function penggunaMeja(string $role): User
{
    Role::findOrCreate($role, 'web');
    $u = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $u->assignRole($role);

    return $u;
}

function formTerisi(): array
{
    $d = siapkanProduksi();
    $order = app(ProductionOrderService::class)->generateFromSpk($d['spk']);
    $service = app(RequisitionService::class);
    $requisition = $service->build($order)['requisition'];

    foreach ($requisition->lines as $line) {
        $service->fillOpeningStock($line, 0);
    }

    return $d + ['order' => $order, 'requisition' => $requisition->fresh(), 'service' => $service];
}

it('menjalankan meja: diajukan produksi, ditolak supervisor dengan alasan, diperbaiki, diajukan lagi, disetujui', function () {
    $d = formTerisi();
    $service = $d['service'];
    $produksi = penggunaMeja('production');
    $supervisor = penggunaMeja('inventory-supervisor');
    $gudang = penggunaMeja('inventory');

    // Sebelum diajukan: tidak bisa disetujui maupun ditolak.
    expect(fn () => $service->approve($d['requisition']->fresh(), $supervisor->id))->toThrow(RuntimeException::class, 'belum diajukan');
    expect(fn () => $service->reject($d['requisition']->fresh(), 'x', $supervisor->id))->toThrow(RuntimeException::class, 'tidak sedang menunggu');

    $service->submit($d['requisition']->fresh(), $produksi->id);
    $r = $d['requisition']->fresh();
    expect($r->status)->toBe(Requisition::STATUS_SUBMITTED)
        ->and($r->submitted_by)->toBe($produksi->id)
        ->and($r->statusLabel())->toBe('Diajukan');

    // Lonceng ke supervisor gudang (punya requisition.approve), bukan ke staf gudang.
    expect(DatabaseNotification::query()->where('notifiable_id', $supervisor->id)->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $gudang->id)->count())->toBe(0)
        ->and(DatabaseNotification::query()->where('notifiable_id', $supervisor->id)->first()->data['title'])->toContain('menunggu persetujuan');

    // Saat diajukan, isian terkunci: stok awal tidak bisa diubah.
    expect(fn () => $service->fillOpeningStock($r->lines[0], 1))->toThrow(RuntimeException::class);

    // Tolak tanpa alasan ditolak; dengan alasan -> kembali ke draft, jejak tersimpan, produksi diberi tahu.
    expect(fn () => $service->reject($r->fresh(), '  ', $supervisor->id))->toThrow(RuntimeException::class, 'Alasan');
    $service->reject($r->fresh(), 'Stok awal tepung tidak masuk akal, hitung ulang', $supervisor->id);
    $r = $r->fresh();
    expect($r->status)->toBe(Requisition::STATUS_DRAFT)
        ->and($r->wasRejected())->toBeTrue()
        ->and($r->rejected_by)->toBe($supervisor->id)
        ->and($r->rejection_reason)->toBe('Stok awal tepung tidak masuk akal, hitung ulang')
        ->and($r->submitted_at)->toBeNull()
        ->and(DatabaseNotification::query()->where('notifiable_id', $produksi->id)->first()->data['title'])->toContain('ditolak supervisor gudang');

    // Produksi memperbaiki (form terbuka lagi), mengajukan ulang; supervisor menyetujui.
    $service->fillOpeningStock($r->lines[0], 0.5);
    $service->submit($r->fresh(), $produksi->id);
    $service->approve($r->fresh(), $supervisor->id);
    $r = $r->fresh();
    expect($r->status)->toBe(Requisition::STATUS_APPROVED)
        ->and($r->approved_by)->toBe($supervisor->id)
        ->and($r->wasRejected())->toBeFalse()
        // Gudang (requisition.check) diberi tahu barang siap dibelanjakan; supervisor sendiri tidak menerima lonceng atas aksinya.
        ->and(DatabaseNotification::query()->where('notifiable_id', $gudang->id)->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $gudang->id)->first()->data['title'])->toContain('siap dibelanjakan');
});

it('menampilkan meja yang tepat di halaman: produksi mengajukan, supervisor menolak lewat form alasan, banner tampil ke produksi', function () {
    $d = formTerisi();
    $produksi = penggunaMeja('production');
    $supervisor = penggunaMeja('inventory-supervisor');

    Livewire::actingAs($produksi)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Meja produksi')
        ->assertActionVisible('ajukan')
        ->assertActionHidden('setujui')
        ->assertActionHidden('tolak')
        ->callAction('ajukan')
        ->assertHasNoActionErrors()
        ->assertSee('menunggu persetujuan supervisor gudang')
        ->assertActionHidden('ajukan')
        ->assertActionHidden('simpan');

    expect($d['requisition']->fresh()->status)->toBe(Requisition::STATUS_SUBMITTED);

    // Staf gudang biasa tidak punya tombol keputusan.
    Livewire::actingAs(penggunaMeja('inventory'))
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertActionHidden('setujui')
        ->assertActionHidden('tolak');

    Livewire::actingAs($supervisor)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Meja supervisor gudang')
        ->assertActionVisible('setujui')
        ->assertActionVisible('tolak')
        ->callAction('tolak', ['reason' => 'Beli beras kebanyakan'])
        ->assertHasNoActionErrors()
        ->assertNotified('Form dikembalikan ke produksi');

    Livewire::actingAs($produksi)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Ditolak supervisor gudang')
        ->assertSee('Beli beras kebanyakan')
        ->assertActionVisible('ajukan')
        ->assertActionVisible('simpan');
});

it('menghitung antrean per meja di dashboard inventory', function () {
    $d = formTerisi();
    $d['service']->submit($d['requisition']->fresh());

    // Supervisor gudang melihat antrean persetujuan; staf gudang melihat antrean penerimaan (kosong).
    Livewire::actingAs(penggunaMeja('inventory-supervisor'))
        ->test(InventoryOverviewWidget::class)
        ->assertSee('Form menunggu persetujuan')
        ->assertSee('Diajukan produksi; setujui atau tolak');

    Livewire::actingAs(penggunaMeja('inventory'))
        ->test(InventoryOverviewWidget::class)
        ->assertDontSee('Form menunggu persetujuan')
        ->assertSee('Form menunggu penerimaan barang');
});

it('memberi peran supervisor gudang izin menyetujui, dan peran gudang tidak', function () {
    $supervisor = penggunaMeja('inventory-supervisor');
    $gudang = penggunaMeja('inventory');

    expect($supervisor->can('requisition.approve'))->toBeTrue()
        ->and($supervisor->can('requisition.check'))->toBeTrue()
        ->and($supervisor->can('production.manage'))->toBeFalse()
        ->and($gudang->can('requisition.approve'))->toBeFalse()
        ->and(in_array('inventory-supervisor', User::manageableRoles(), true))->toBeTrue()
        ->and(User::roleLabel('inventory-supervisor'))->toBe('Supervisor Gudang');

    $this->actingAs($supervisor)->get('/dashboard')->assertRedirect(route('filament.admin.pages.dashboard'));
    $this->actingAs($supervisor)->get('/inventory/requisitions')->assertOk();
});
