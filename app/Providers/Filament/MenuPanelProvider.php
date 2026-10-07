<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * Panel Filament aplikasi Menu (`/menu`): resep menu utama & sub menu, HPP,
 * OHC, pencocokan menu ke produk, pekerjaan menu, konversi satuan.
 *
 * Dipisah dari Inventory atas revisi klien (7 Okt 2026): tim menu yang
 * mengatur resep dan biayanya, gudang hanya membaca. Harga jual & profit ke
 * customer tetap di Admin › Master Menu.
 */
class MenuPanelProvider extends PanelProvider
{
    use ConfiguresShellPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->shell($panel->id('menu')->path('menu'), 'menu')
            ->discoverResources(in: app_path('Filament/Menu/Resources'), for: 'App\Filament\Menu\Resources')
            ->discoverPages(in: app_path('Filament/Menu/Pages'), for: 'App\Filament\Menu\Pages')
            ->discoverWidgets(in: app_path('Filament/Menu/Widgets'), for: 'App\Filament\Menu\Widgets');
    }
}
