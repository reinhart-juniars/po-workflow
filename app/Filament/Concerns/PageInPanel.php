<?php

namespace App\Filament\Concerns;

/**
 * Halaman (bukan resource) yang disematkan ke satu panel. Kelas pemakai
 * menulis `protected static string $ownerPanel = 'menu' | 'admin';`.
 */
trait PageInPanel
{
    public static function getRouteName(?string $panel = null): string
    {
        return parent::getRouteName($panel ?? static::$ownerPanel);
    }
}
