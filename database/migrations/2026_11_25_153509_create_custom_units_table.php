<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Satuan: satuan hitung tambahan yang diketik sendiri oleh gudang
 * (roll, karung, ...) di samping satuan bawaan sistem (App\Support\Units\Unit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_units', function (Blueprint $table) {
            $table->id();
            // Disimpan huruf kecil, persis seperti yang tertulis di kolom unit bahan/resep.
            $table->string('name', 30)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_units');
    }
};
