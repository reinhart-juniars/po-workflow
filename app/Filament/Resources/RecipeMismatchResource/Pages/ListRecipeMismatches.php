<?php

namespace App\Filament\Resources\RecipeMismatchResource\Pages;

use App\Filament\Resources\RecipeMismatchResource;
use App\Models\RecipeMismatch;
use Filament\Resources\Pages\ListRecords;

class ListRecipeMismatches extends ListRecords
{
    protected static string $resource = RecipeMismatchResource::class;

    /**
     * Daftar ini disusun oleh perpindahan data, bukan diisi tangan.
     *
     * Karena itu tidak ada tombol tambah: baris baru hanya muncul ketika ada
     * bahan resep yang benar-benar belum punya padanan.
     */
    public function getSubheading(): ?string
    {
        $open = RecipeMismatch::query()->open()->count();
        $rows = (int) RecipeMismatch::query()->open()->sum('occurrence_count');

        if ($open === 0) {
            return 'Seluruh bahan resep sudah punya padanan.';
        }

        return number_format($open, 0, ',', '.').' nama belum diputuskan, menahan '
            .number_format($rows, 0, ',', '.').' baris resep.';
    }
}
