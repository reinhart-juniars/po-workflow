<?php

namespace App\Filament\Menu\Widgets;

use App\Filament\Menu\Pages\IdleMenuReport;
use App\Filament\Menu\Pages\MenuMatching;
use App\Filament\Menu\Resources\InventoryUnitConversionResource;
use App\Filament\Menu\Resources\RecipeMismatchResource;
use App\Filament\Menu\Resources\RecipeResource;
use App\Filament\Pages\ModuleSettings;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeMismatch;
use App\Services\IdleMenuReportService;
use App\Services\MissingUnitConversionScanner;
use App\Services\ProfitGuardService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan aplikasi Menu: berapa menu yang ada, dan apa yang masih menahan
 * HPP berbasis resep dari bisa dipercaya (bahan belum tertaut, satuan tanpa
 * aturan konversi, produk dijual tanpa resep). Setiap angka menautkan ke
 * halaman tempat menanganinya.
 */
class MenuOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        $utama = Recipe::query()->utama()->where('is_active', true)->count();
        $sub = Recipe::query()->sub()->where('is_active', true)->count();

        $stats[] = Stat::make('Menu utama aktif', $utama)
            ->description($sub.' sub menu dipakai di dalamnya')
            ->descriptionIcon('heroicon-m-book-open')
            ->color('gray')
            ->url(RecipeResource::getUrl('index', ['activeTab' => 'utama']));

        $tanpaResep = Product::query()->awaitingRecipe()->where('active', true)->count();

        $stats[] = Stat::make('Produk dijual tanpa resep', $tanpaResep)
            ->description($tanpaResep > 0 ? 'HPP-nya masih diisi manual di Admin' : 'Semua produk aktif punya resep')
            ->descriptionIcon($tanpaResep > 0 ? 'heroicon-m-link-slash' : 'heroicon-m-check-circle')
            ->color($tanpaResep > 0 ? 'warning' : 'success')
            ->url(MenuMatching::getUrl());

        $resepAktif = Recipe::query()->where('is_active', true)->count();
        $resepYatim = Recipe::query()->where('is_active', true)->whereHas('items', fn ($q) => $q->unmatched())->count();
        $mismatch = RecipeMismatch::query()->where('status', RecipeMismatch::STATUS_OPEN)->count();

        $stats[] = Stat::make('Resep dengan bahan belum tertaut', $resepYatim.' / '.$resepAktif)
            ->description($mismatch.' nama bahan menunggu keputusan')
            ->descriptionIcon('heroicon-m-question-mark-circle')
            ->color($resepYatim > 0 ? 'warning' : 'success')
            ->url($mismatch > 0 ? RecipeMismatchResource::getUrl('index') : RecipeResource::getUrl('index'));

        $konversi = Cache::remember('inventory.dashboard.konversi', now()->addMinutes(10), fn () => app(MissingUnitConversionScanner::class)->summary());

        $stats[] = Stat::make('Pasangan satuan tanpa aturan konversi', $konversi['pasangan'])
            ->description($konversi['baris'].' baris resep belum terhitung')
            ->descriptionIcon('heroicon-m-scale')
            ->color($konversi['pasangan'] > 0 ? 'warning' : 'success')
            ->url(InventoryUnitConversionResource::getUrl('missing'));

        // Bagian B.2 & B.3: profit menu keseluruhan terhadap batas, dan menu
        // yang tidak diproduksi dalam rentang bawaan.
        if ($user?->can('notification.profit') || $user?->can('recipe.view')) {
            $agg = app(ProfitGuardService::class)->aggregate();
            $rentang = number_format($agg['lower'], 0, ',', '.').'–'.number_format($agg['upper'], 0, ',', '.').'%';

            $stats[] = Stat::make('Profit menu keseluruhan', $agg['count'] > 0 ? number_format($agg['profit_pct'], 1, ',', '.').'%' : '–')
                ->description($agg['count'] > 0
                    ? $agg['count'].' menu terhitung · batas '.$rentang.($agg['skipped'] > 0 ? ' · '.$agg['skipped'].' dilewati' : '')
                    : 'Belum ada resep yang bersih untuk dihitung ('.$agg['skipped'].' dilewati)')
                ->descriptionIcon(match ($agg['state']) {
                    'below' => 'heroicon-m-arrow-trending-down', 'above' => 'heroicon-m-arrow-trending-up', default => 'heroicon-m-check-circle'
                })
                ->color(match ($agg['state']) {
                    'below' => 'danger', 'above' => 'warning', 'none' => 'gray', default => 'success'
                })
                ->url($user?->can('settings.manage') ? ModuleSettings::getUrl() : null);
        }

        $service = app(IdleMenuReportService::class);
        $idle = Cache::remember('inventory.dashboard.idle_menu', now()->addMinutes(10), fn () => $service->count());

        $stats[] = Stat::make('Menu tidak diproduksi '.$service->defaultMonths().' bulan terakhir', $idle)
            ->description('Menu aktif yang tidak muncul di SPK Produksi')
            ->descriptionIcon('heroicon-m-eye-slash')
            ->color($idle > 0 ? 'warning' : 'success')
            ->url(IdleMenuReport::getUrl());

        return $stats;
    }
}
