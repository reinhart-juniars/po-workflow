<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;

/**
 * Panel Filament aplikasi Inventory (bahan, pembelian, opname, SPK Produksi,
 * Form Kebutuhan, Kartu Stok). Cangkangnya dari ConfiguresShellPanel, sama
 * dengan panel Menu dan layout Blade.
 *
 * Id panel tetap 'admin' supaya nama route (filament.admin.*) tidak berubah;
 * path-nya /inventory. Path lama /admin diarahkan ke sana di routes/web.php.
 */
class AdminPanelProvider extends PanelProvider
{
    use ConfiguresShellPanel;

    /**
     * Aksi Edit/Hapus di setiap baris tabel tampil sebagai ikon (label jadi
     * tooltip), sama dengan layout Blade (components/row-action). Berlaku
     * untuk semua panel.
     */
    public function boot(): void
    {
        foreach ([EditAction::class, DeleteAction::class] as $action) {
            $action::configureUsing(fn ($action) => $action
                ->iconButton()
                ->tooltip(fn ($action) => $action->getLabel()));
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $this->shell($panel->default()->id('admin')->path('inventory'), 'inventory')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets');
    }
}
