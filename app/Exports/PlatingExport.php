<?php

namespace App\Exports;

use App\Models\ProductionOrder;
use App\Services\PlatingService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lembar plating dalam dua sheet, seperti export Menu Plating Master Menu:
 * "Komponen Global" (satu tabel) dan "Komponen Per Menu" (blok per menu
 * dengan slot bernomor, sisa slot kosong untuk catatan tangan).
 */
class PlatingExport implements WithMultipleSheets
{
    public function __construct(
        protected ProductionOrder $order,
    ) {}

    public function sheets(): array
    {
        $sheet = app(PlatingService::class)->sheet($this->order);

        return [
            new PlatingGlobalSheet($sheet),
            new PlatingPerMenuSheet($sheet),
        ];
    }
}
