<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian B.4 -- Katalog Foto Menu berbasis SKU: satu foto resmi per menu,
 * disimpan di disk `public` (storage/app/public/menu-photos), dipakai tim
 * marketing sebagai sumber foto tunggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('needs_recipe');
            $table->timestamp('photo_updated_at')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'photo_updated_at']);
        });
    }
};
