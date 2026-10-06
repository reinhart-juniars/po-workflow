<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kelompok bahan bawaan Master Menu (sayur, protein, bumbu, karbo, kemasan).
 *
 * Kolom `category` sudah dipakai untuk jenis bucket akuntansi (bahan baku,
 * kemasan, inventaris) dan tidak bisa merangkap. Tanpa kolom sendiri,
 * pengelompokan 305 bahan itu hilang saat migrasi -- padahal itulah yang
 * dipakai menyaring bahan saat menyusun resep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('ingredient_group', 60)
                ->nullable()
                ->after('category')
                ->comment('Kelompok bahan dari Master Menu, mis. sayur/protein/bumbu.');

            $table->index('ingredient_group', 'inventory_items_group_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex('inventory_items_group_index');
            $table->dropColumn('ingredient_group');
        });
    }
};
