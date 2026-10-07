<?php

namespace App\Filament\Concerns;

/**
 * Resource milik panel Inventory (id 'admin', path `/inventory`). Lihat
 * InMenuPanel: panel pemilik disematkan supaya tautan dari panel Menu benar.
 */
trait InInventoryPanel
{
    public static function getRouteBaseName(?string $panel = null): string
    {
        return parent::getRouteBaseName($panel ?? 'admin');
    }
}
