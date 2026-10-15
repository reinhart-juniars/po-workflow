<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi v3.1 -- nama menu di Excel rencana masak klien yang dicocokkan manual
 * ke produk Master Menu. Klien memakai nama yang sama tiap minggu ("Ricebowl
 * Dory Cabe Garam" 15.000), jadi pilihan sekali dipakai lagi di impor berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_name_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('name_norm', 191);
            $table->decimal('price', 12, 2)->nullable();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['name_norm', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_name_aliases');
    }
};
