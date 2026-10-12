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
 * Meja terpisah di Form Kebutuhan: produksi MENGAJUKAN, gudang (peran
 * inventory, dipegang satu orang) MENYETUJUI atau MENOLAK (form kembali ke
 * produksi dengan alasan) lalu menerima barang. Tiap perpindahan meja
 * mengirim lonceng ke meja berikutnya.
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

it('menjalankan meja: diajukan produksi, ditolak gudang dengan alasan, diperbaiki, diajukan lagi, disetujui', function () {
    $d = formTerisi();
    $service = $d['service'];
    $produksi = penggunaMeja('production');
    $gudang = penggunaMeja('inventory');
    $accounting = penggunaMeja('accounting');

    // Sebelum diajukan: tidak bisa disetujui maupun ditolak.
    expect(fn () => $service->approve($d['requisition']->fresh(), $gudang->id))->toThrow(RuntimeException::class, 'belum diajukan');
    expect(fn () => $service->reject($d['requisition']->fresh(), 'x', $gudang->id))->toThrow(RuntimeException::class, 'tidak sedang menunggu');

    $service->submit($d['requisition']->fresh(), $produksi->id);
    $r = $d['requisition']->fresh();
    expect($r->status)->toBe(Requisition::STATUS_SUBMITTED)
        ->and($r->submitted_by)->toBe($produksi->id)
        ->and($r->statusLabel())->toBe('Diajukan');

    // Lonceng ke gudang (punya requisition.approve), bukan ke accounting yang hanya memeriksa.
    expect(DatabaseNotification::query()->where('notifiable_id', $gudang->id)->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $accounting->id)->count())->toBe(0)
        ->and(DatabaseNotification::query()->where('notifiable_id', $gudang->id)->first()->data['title'])->toContain('menunggu persetujuan');

    // Saat diajukan, isian terkunci: stok awal tidak bisa diubah.
    expect(fn () => $service->fillOpeningStock($r->lines[0], 1))->toThrow(RuntimeException::class);

    // Tolak tanpa alasan ditolak; dengan alasan -> kembali ke draft, jejak tersimpan, produksi diberi tahu.
    expect(fn () => $service->reject($r->fresh(), '  ', $gudang->id))->toThrow(RuntimeException::class, 'Alasan');
    $service->reject($r->fresh(), 'Stok awal tepung tidak masuk akal, hitung ulang', $gudang->id);
    $r = $r->fresh();
    expect($r->status)->toBe(Requisition::STATUS_DRAFT)
        ->and($r->wasRejected())->toBeTrue()
        ->and($r->rejected_by)->toBe($gudang->id)
        ->and($r->rejection_reason)->toBe('Stok awal tepung tidak masuk akal, hitung ulang')
        ->and($r->submitted_at)->toBeNull()
        ->and(DatabaseNotification::query()->where('notifiable_id', $produksi->id)->first()->data['title'])->toContain('ditolak gudang');

    // Produksi memperbaiki (form terbuka lagi), mengajukan ulang; gudang menyetujui.
    $service->fillOpeningStock($r->lines[0], 0.5);
    $service->submit($r->fresh(), $produksi->id);
    $service->approve($r->fresh(), $gudang->id);
    $r = $r->fresh();
    expect($r->status)->toBe(Requisition::STATUS_APPROVED)
        ->and($r->approved_by)->toBe($gudang->id)
        ->and($r->wasRejected())->toBeFalse()
        // Pemegang requisition.check lain diberi tahu barang siap dibelanjakan;
        // gudang sendiri tidak menerima lonceng atas aksinya (2 = dua kali diajukan).
        ->and(DatabaseNotification::query()->where('notifiable_id', $accounting->id)->first()?->data['title'])->toContain('siap dibelanjakan')
        ->and(DatabaseNotification::query()->where('notifiable_id', $gudang->id)->count())->toBe(2);
});

it('menampilkan meja yang tepat di halaman: produksi mengajukan, gudang menolak lewat form alasan, banner tampil ke produksi', function () {
    $d = formTerisi();
    $produksi = penggunaMeja('production');
    $gudang = penggunaMeja('inventory');

    Livewire::actingAs($produksi)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Meja produksi')
        ->assertActionVisible('ajukan')
        ->assertActionHidden('setujui')
        ->assertActionHidden('tolak')
        ->callAction('ajukan')
        ->assertHasNoActionErrors()
        ->assertSee('menunggu persetujuan gudang')
        ->assertActionHidden('ajukan')
        ->assertActionHidden('simpan');

    expect($d['requisition']->fresh()->status)->toBe(Requisition::STATUS_SUBMITTED);

    // Accounting ikut memeriksa barang, tapi tidak punya tombol keputusan.
    Livewire::actingAs(penggunaMeja('accounting'))
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertActionHidden('setujui')
        ->assertActionHidden('tolak');

    Livewire::actingAs($gudang)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Meja gudang')
        ->assertActionVisible('setujui')
        ->assertActionVisible('tolak')
        ->callAction('tolak', ['reason' => 'Beli beras kebanyakan'])
        ->assertHasNoActionErrors()
        ->assertNotified('Form dikembalikan ke produksi');

    Livewire::actingAs($produksi)
        ->test(RequisitionForm::class, ['record' => $d['order']->id])
        ->assertSee('Ditolak gudang')
        ->assertSee('Beli beras kebanyakan')
        ->assertActionVisible('ajukan')
        ->assertActionVisible('simpan');
});

it('menghitung antrean per meja di dashboard inventory', function () {
    $d = formTerisi();
    $d['service']->submit($d['requisition']->fresh());

    // Gudang melihat kedua antrean: persetujuan dan penerimaan barang.
    Livewire::actingAs(penggunaMeja('inventory'))
        ->test(InventoryOverviewWidget::class)
        ->assertSee('Form menunggu persetujuan')
        ->assertSee('Diajukan produksi; setujui atau tolak')
        ->assertSee('Form menunggu penerimaan barang');

    // Accounting hanya memeriksa: tanpa antrean persetujuan.
    Livewire::actingAs(penggunaMeja('accounting'))
        ->test(InventoryOverviewWidget::class)
        ->assertDontSee('Form menunggu persetujuan')
        ->assertSee('Form menunggu penerimaan barang');
});

it('memberi peran gudang izin menyetujui dan memeriksa, tanpa menyusun SPK produksi', function () {
    $gudang = penggunaMeja('inventory');
    $produksi = penggunaMeja('production');

    expect($gudang->can('requisition.approve'))->toBeTrue()
        ->and($gudang->can('requisition.check'))->toBeTrue()
        ->and($gudang->can('notification.price'))->toBeTrue()
        ->and($gudang->can('production.manage'))->toBeFalse()
        ->and($produksi->can('requisition.approve'))->toBeFalse()
        ->and(in_array('inventory', User::manageableRoles(), true))->toBeTrue()
        ->and(in_array('inventory-supervisor', User::manageableRoles(), true))->toBeFalse()
        ->and(User::roleLabel('inventory'))->toBe('Inventory (gudang)');

    $this->actingAs($gudang)->get('/dashboard')->assertRedirect(route('filament.admin.pages.dashboard'));
    $this->actingAs($gudang)->get('/inventory/requisitions')->assertOk();
});
