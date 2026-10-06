<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ExpenseLocation;

trait ResolvesCentralExpenseLocation
{
    protected function centralExpenseLocationId(): int
    {
        $location = ExpenseLocation::query()->firstOrCreate(
            ['name' => 'Pusat'],
            [
                'type' => 'center',
                'description' => 'Lokasi default terpusat untuk seluruh pengeluaran.',
                'is_active' => true,
            ]
        );

        if (! $location->is_active) {
            $location->forceFill(['is_active' => true])->save();
        }

        return (int) $location->id;
    }
}
