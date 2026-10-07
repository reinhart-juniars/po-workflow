<?php

namespace App\Providers\Filament;

use App\Http\Middleware\FilamentAuthenticate;
use App\Http\Middleware\ForcePasswordChange;
use App\Support\Navigation;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Vite;

/**
 * Cangkang 3S ONE untuk setiap panel Filament (Inventory, Menu).
 *
 * Panel bukan aplikasi terpisah: login satu pintu (/login), sesi yang sama,
 * bilah aplikasi dan gaya yang sama dengan layout Blade. Semua yang membuat
 * sebuah panel "terasa satu sistem" dikumpulkan di sini supaya panel kedua
 * tidak menjadi salinan yang pelan-pelan menyimpang.
 */
trait ConfiguresShellPanel
{
    /**
     * @param  string  $appKey  Kunci aplikasi di App\Support\Navigation::apps().
     */
    protected function shell(Panel $panel, string $appKey): Panel
    {
        return $panel
            // Tanpa halaman login sendiri: pintu masuknya /login aplikasi
            // (lihat FilamentAuthenticate). Beranda panel = dashboard peran.
            ->homeUrl(fn () => auth()->user() ? Navigation::dashboardUrl(auth()->user()) : url('/'))

            // Identitas visual mengikuti aplikasi Blade (resources/css/app.css).
            ->brandName('3S ONE')
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
            ->maxContentWidth('full')
            ->globalSearch(false)
            // Lonceng dirender bersama halaman, bukan dimuat susulan lewat
            // request Livewire -- kalau susulan, ia "muncul" belakangan di
            // setiap perpindahan menu.
            ->databaseNotifications()
            ->lazyLoadedDatabaseNotifications(false)

            // Sidebar = menu aplikasi ini: nama & urutan grupnya dari
            // Navigation::menus()[$appKey]; resource/page mendaftar sendiri
            // ke grup itu. Grup tanpa item untuk peran tertentu otomatis hilang.
            ->navigationGroups(array_map(
                fn (string $label) => NavigationGroup::make($label),
                array_keys(Navigation::menus()[$appKey]),
            ))

            // Bilah aplikasi (Owner / Admin / ...) dan gaya cangkang yang sama
            // dengan layout Blade, disuntik ke topbar & head panel.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Vite::withEntryPoints(['resources/css/shell.css'])->toHtml())
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn () => view('partials.app-bar', ['currentApp' => $appKey]))
            // Pencarian global di atas sidebar, sama dengan layout Blade.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('partials.global-search'))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => '<p class="sh-app-name">'.e(Navigation::appLabel($appKey)).'</p>')
            // Menu pengguna yang sama dengan header Blade.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('partials.user-menu'))
            // Posisi gulir sidebar diingat, halaman menu di-prefetch.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('partials.sidebar-scroll-memory', ['app' => $appKey]))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.nav-prefetch'))
            // Animasi geser antar menu, sama dengan layout Blade.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.page-transition'))
            // Frame pertama tidak kosong menunggu Alpine.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.first-paint'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => '<span id="sh-page-end" hidden></span>')

            // Grup middleware 'web' milik aplikasi ini, supaya cookie, sesi,
            // dan CSRF-nya sama persis dengan aplikasi Blade.
            ->middleware([
                'web',
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

            // Tanpa Authenticate, halaman panel terbuka tanpa login.
            // ForcePasswordChange: panel tidak boleh dipakai memutari
            // kewajiban ganti password di aplikasi Blade.
            ->authMiddleware([
                FilamentAuthenticate::class,
                ForcePasswordChange::class,
            ]);
    }
}
