<?php

namespace App\Providers\Filament;

use App\Http\Middleware\FilamentAuthenticate;
use App\Http\Middleware\ForcePasswordChange;
use App\Support\Navigation;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Vite;

/**
 * Panel Filament untuk modul inventory / resep / produksi -- bagian dari
 * 3S Business Control System, bukan aplikasi terpisah: sidebar & menunya
 * dibaca dari App\Support\Navigation yang sama dengan layout Blade, login
 * satu pintu (/login), sesi yang sama, tampilan yang sama.
 *
 * Id panel tetap 'admin' supaya nama route (filament.admin.*) tidak berubah;
 * path-nya /inventory. Path lama /admin diarahkan ke sana di routes/web.php.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default() // tandai ini panel default
            ->id('admin')
            ->path('inventory')
            // Tanpa halaman login sendiri: pintu masuknya /login aplikasi
            // (lihat FilamentAuthenticate). Beranda panel = dashboard peran.
            ->homeUrl(fn () => auth()->user() ? Navigation::dashboardUrl(auth()->user()) : url('/'))

            // Identitas visual mengikuti aplikasi Blade (resources/css/app.css):
            // biru brand, abu slate, Public Sans, tanpa mode gelap.
            ->brandName('3S BCS')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.75rem')
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
            ->globalSearch(false) // layout Blade tidak punya pencarian global; disamakan

            ->userMenuItems([
                MenuItem::make()
                    ->label('Profil Akun')
                    ->url(fn () => route('profile.edit'))
                    ->icon('heroicon-m-user-circle'),
            ])

            // Sidebar = menu aplikasi Inventory: nama & urutan grupnya dari
            // Navigation::menus()['inventory']; resource/page mendaftar sendiri
            // ke grup itu. Grup tanpa item untuk peran tertentu otomatis hilang.
            ->navigationGroups(array_map(
                fn (string $label) => NavigationGroup::make($label),
                array_keys(Navigation::menus()['inventory']),
            ))

            // Bilah aplikasi (Owner / Admin / ...) dan gaya cangkang yang sama
            // dengan layout Blade, disuntik ke topbar & head panel.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Vite::withEntryPoints(['resources/css/shell.css'])->toHtml())
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn () => view('partials.app-bar', ['currentApp' => 'inventory']))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => '<p class="sh-app-name">Inventory</p>')

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
                FilamentAuthenticate::class,
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
