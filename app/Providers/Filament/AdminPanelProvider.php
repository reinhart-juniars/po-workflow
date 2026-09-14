<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ForcePasswordChange;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default() // tandai ini panel default
            ->id('admin')
            ->path('admin')
            // Login memakai halaman sendiri: bawaan Filament hanya menerima
            // email, sementara sebagian besar pengguna di sini masuk dengan nama.
            ->login(\App\Filament\Pages\Auth\Login::class)

            // Satu panel terpadu: urutan grup mengikuti alur kerja (pesanan ->
            // produksi -> pengiriman), lalu inventory & master, terakhir sistem.
            // Grup yang tidak punya item untuk peran tertentu otomatis hilang.
            ->brandName('PO-workflow')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Pesanan')->icon('heroicon-o-clipboard-document-list'),
                NavigationGroup::make('Produksi')->icon('heroicon-o-fire'),
                NavigationGroup::make('Pengiriman')->icon('heroicon-o-truck'),
                NavigationGroup::make('Inventory')->icon('heroicon-o-cube'),
                NavigationGroup::make('Master Data')->icon('heroicon-o-archive-box'),
                NavigationGroup::make('Sistem')->icon('heroicon-o-cog-6-tooth')->collapsed(),
            ])

            // Panel memakai grup middleware 'web' milik aplikasi ini, bukan
            // daftar sendiri, supaya cookie, sesi, dan CSRF-nya persis sama
            // dengan aplikasi Blade -- keduanya berbagi guard dan sesi yang sama.
            // Dua middleware terakhir adalah kebutuhan Filament sendiri.
            ->middleware([
                'web',
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

            // Tanpa Authenticate, seluruh halaman panel terbuka untuk siapa pun
            // tanpa login. ForcePasswordChange menyusul supaya panel tidak bisa
            // dipakai memutari kewajiban ganti password di aplikasi Blade.
            ->authMiddleware([
                Authenticate::class,
                ForcePasswordChange::class,
            ])

            // ⬇️ INI YANG PENTING: suruh Filament muat semua Resource, Page, Widget
            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\\Filament\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\\Filament\\Widgets',
            );
    }
}
