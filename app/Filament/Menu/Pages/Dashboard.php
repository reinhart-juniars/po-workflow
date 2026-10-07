<?php

namespace App\Filament\Menu\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard aplikasi Menu: jumlah menu, kesehatan HPP berbasis resep, dan
 * daftar kerja yang menahannya (lihat MenuOverviewWidget).
 */
class Dashboard extends BaseDashboard
{
    use \App\Filament\Concerns\PageInPanel;

    protected static string $ownerPanel = 'menu';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    // Akar /menu dialihkan ke dashboard peran, sama dengan /inventory.
    protected static string $routePath = 'dashboard';

    protected static ?string $navigationGroup = 'Ringkasan';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Dashboard Menu';

    public function getWidgets(): array
    {
        return [
            \App\Filament\Menu\Widgets\MenuOverviewWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }
}
