<?php

namespace App\Providers\Filament;

use App\Http\Middleware\FilamentAuthenticate;
use App\Http\Middleware\ForcePasswordChange;
use App\Support\Navigation;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Vite;

/**
 * Panel Filament untuk modul inventory / resep / produksi -- bagian dari
 * 3S ONE (Business Control System), bukan aplikasi terpisah: sidebar & menunya
 * dibaca dari App\Support\Navigation yang sama dengan layout Blade, login
 * satu pintu (/login), sesi yang sama, tampilan yang sama.
 *
 * Id panel tetap 'admin' supaya nama route (filament.admin.*) tidak berubah;
 * path-nya /inventory. Path lama /admin diarahkan ke sana di routes/web.php.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Aksi Edit/Hapus di setiap baris tabel tampil sebagai ikon (label jadi
     * tooltip), sama dengan layout Blade (components/row-action). Tombol
     * berteks membuat kolom aksi melebar sampai tabel harus digeser.
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
        return $panel
            ->default() // tandai ini panel default
            ->id('admin')
            ->path('inventory')
            // Tanpa halaman login sendiri: pintu masuknya /login aplikasi
            // (lihat FilamentAuthenticate). Beranda panel = dashboard peran.
            ->homeUrl(fn () => auth()->user() ? Navigation::dashboardUrl(auth()->user()) : url('/'))

            // Identitas visual mengikuti aplikasi Blade (resources/css/app.css):
            // biru brand, abu slate, Public Sans, tanpa mode gelap.
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
            // Lonceng: form diajukan -> supervisor gudang; disetujui -> gudang;
            // ditolak/diperiksa -> produksi (RequisitionService::notify).
            ->databaseNotifications() // layout Blade tidak punya pencarian global; disamakan
            // Lonceng dirender bersama halaman, bukan dimuat susulan lewat
            // request Livewire -- kalau susulan, ia "muncul" belakangan di
            // setiap perpindahan menu.
            ->lazyLoadedDatabaseNotifications(false)

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
            // Pencarian global di atas sidebar, sama dengan layout Blade --
            // bukan di topbar, yang sudah penuh oleh bilah aplikasi.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('partials.global-search'))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => '<p class="sh-app-name">Inventory</p>')
            // Menu pengguna yang sama dengan header Blade (avatar inisial + nama);
            // menu bawaan Filament (avatar dari ui-avatars.com) disembunyikan di shell.css.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('partials.user-menu'))
            // Perpindahan menu semulus layout Blade: posisi gulir sidebar
            // diingat (bukan digulir ke tengah oleh Filament), dan halaman
            // menu di-prefetch saat kursor diarahkan ke sana.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('partials.sidebar-scroll-memory', ['app' => 'inventory']))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.nav-prefetch'))
            // Animasi geser antar menu, sama dengan layout Blade. Penanda
            // akhir halaman menahan transisi sampai konten selesai terbaca.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.page-transition'))
            // Frame pertama tidak kosong menunggu Alpine (lihat view-nya).
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.first-paint'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => '<span id="sh-page-end" hidden></span>')

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
