<?php

namespace App\Filament\Menu\Resources\MasterMenuResource\Pages;

use App\Filament\Menu\Resources\MasterMenuResource;
use Filament\Resources\Pages\ListRecords;

class ListMasterMenus extends ListRecords
{
    protected static string $resource = MasterMenuResource::class;

    public function getSubheading(): ?string
    {
        return 'Menu yang dipesan di PO. Menu baru dibuat otomatis dari Menu Utama; admin hanya mengisi Harga Non Lapak.';
    }
}
