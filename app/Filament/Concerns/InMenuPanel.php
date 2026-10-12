<?php

namespace App\Filament\Concerns;

/**
 * Resource milik panel Menu (`/menu`).
 *
 * Filament membangun URL resource dari panel yang *sedang dibuka*. Dengan dua
 * panel, tautan ke resep dari halaman Inventory (mis. Breakdown Bahan) akan
 * menunjuk route filament.admin.resources.recipes.* yang tidak ada. Panel
 * pemiliknya disematkan di sini supaya getUrl() selalu benar dari mana pun.
 */
trait InMenuPanel
{
    public static function getRouteBaseName(?string $panel = null): string
    {
        return parent::getRouteBaseName($panel ?? 'menu');
    }
}
