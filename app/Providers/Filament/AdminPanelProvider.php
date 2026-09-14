<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ForcePasswordChange;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;

/**
 * "Inventory App": panel Filament yang tampil sebagai salah satu aplikasi
 * 3S BCS di samping Owner/Admin/Accounting/Production App -- header, warna,
 * font, dan pengalih aplikasi yang sama, sesi login yang sama.
 *
 * Id panel tetap 'admin' supaya nama route (filament.admin.*) tidak berubah;
 * yang berganti hanya path dan tampilannya. Path lama /admin diarahkan ke
 * /inventory-app di routes/web.php.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default() // tandai ini panel default
            ->id('admin')
            ->path('inventory-app')
            // Login memakai halaman sendiri: bawaan Filament hanya menerima
            // email, sementara sebagian besar pengguna di sini masuk dengan nama.
            ->login(\App\Filament\Pages\Auth\Login::class)

            // Identitas visual mengikuti aplikasi Blade (resources/css/app.css):
            // biru brand, abu slate, Public Sans, tanpa mode gelap.
            ->brandName('Inventory App')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('3rem')
            ->colors([
                'primary' => Color::hex('#3455db'),
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->font('Public Sans')
            ->darkMode(false)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')

            // Pengalih aplikasi (Owner / Admin / Accounting / ...) di topbar,
            // persis seperti header aplikasi Blade.
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('filament.app-switcher'))
            ->userMenuItems([
                MenuItem::make()
                    ->label('Profil Akun')
                    ->url(fn () => route('profile.edit'))
                    ->icon('heroicon-m-user-circle'),
            ])

            // Satu panel terpadu: urutan grup mengikuti alur kerja (pesanan ->
            // produksi -> pengiriman), lalu inventory & master, terakhir sistem.
            // Grup yang tidak punya item untuk peran tertentu otomatis hilang.
            ->navigationGroups([
                NavigationGroup::make('Inventory')->icon('heroicon-o-cube'),
                NavigationGroup::make('Produksi')->icon('heroicon-o-fire'),
                NavigationGroup::make('Master Data')->icon('heroicon-o-archive-box'),
                NavigationGroup::make('Pesanan')->icon('heroicon-o-clipboard-document-list'),
                NavigationGroup::make('Pengiriman')->icon('heroicon-o-truck'),
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
