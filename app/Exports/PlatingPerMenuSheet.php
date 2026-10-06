<?php

namespace App\Exports;

use App\Services\PlatingService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Sheet "Komponen Per Menu" lembar plating: blok per menu dengan slot bernomor. */
class PlatingPerMenuSheet implements FromArray, ShouldAutoSize, WithTitle
{
    /** @param array{rows: array<int, array<string, mixed>>, total_qty: float} $sheet */
    public function __construct(protected array $sheet) {}

    public function title(): string
    {
        return 'Komponen Per Menu';
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->sheet['rows'] as $menu) {
            $rows[] = [(float) $menu['qty'].' '.$menu['unit'], $menu['name'].($menu['remark'] ? ' · '.$menu['remark'] : '')];

            for ($slot = 0; $slot < max(count($menu['components']), PlatingService::MIN_SLOTS); $slot++) {
                $rows[] = [$slot + 1, $menu['components'][$slot] ?? ''];
            }

            $rows[] = ['', ''];
        }

        return $rows;
    }
}
