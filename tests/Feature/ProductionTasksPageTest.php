<?php

use App\Filament\Resources\ProductionOrderResource\Pages\ProductionTasks;
use App\Models\ProductionOrder;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Halaman Lembar Kerja SPK Produksi.
 *
 * Regresi: form diisi dengan fill() tanpa state, sehingga repeater berelasi
 * tidak memuat baris tersimpan -- halaman tampil kosong, dan menekan Simpan di
 * form kosong itu menghapus seluruh lembar kerja SPK tersebut.
 */
it('memuat baris tersimpan dan tidak menghapusnya saat disimpan ulang', function () {
    Role::findOrCreate('production', 'web');
    $user = User::factory()->create(['is_active' => true, 'force_password_change' => false]);
    $user->assignRole('production');
    $this->actingAs($user);

    $order = ProductionOrder::query()->create(['title' => 'SPK uji', 'production_date' => '2026-10-16', 'status' => ProductionOrder::STATUS_DRAFT]);
    $order->tasks()->create(['task' => 'potong', 'object' => 'daun bawang', 'quantity_text' => '160 gr', 'worker_name' => 'yuni', 'sort_order' => 1]);
    $order->tasks()->create(['task' => 'buat', 'object' => 'acar', 'quantity_text' => '40 porsi', 'worker_name' => 'mia', 'sort_order' => 2]);

    $page = Livewire::test(ProductionTasks::class, ['record' => $order->getRouteKey()]);

    expect(collect($page->get('data.tasks'))->pluck('object')->all())->toBe(['daun bawang', 'acar']);

    $page->call('save')->assertHasNoErrors();

    expect($order->tasks()->pluck('object')->all())->toBe(['daun bawang', 'acar']);
});
