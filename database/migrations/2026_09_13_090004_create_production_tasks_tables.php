<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pekerjaan dapur.
 *
 * recipe_tasks     -> template kerja paten per menu ("potong ayam 25 gr, PIC
 *                     Yuni"); di Master Menu bernama menu_tasks.
 * production_tasks -> lembar kerja sebuah SPK Produksi: siapa mengerjakan apa
 *                     untuk menu mana; disalin dari template lalu disunting.
 * production_workers -> nama pelaksana untuk pilihan dropdown. Bukan users:
 *                     tim dapur tidak punya akun dan tidak perlu punya.
 *
 * Kuantitas tugas disimpan sebagai teks ("25 gr", "8+3,4 kg") apa adanya,
 * seperti di form kertas -- angka ini instruksi kerja, bukan bahan hitungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_workers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('recipe_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('task')->nullable()->comment('Apa yang dikerjakan: potong, goreng, ...');
            $table->string('object')->nullable()->comment('Objeknya: ayam, wortel, ...');
            $table->string('quantity_text', 100)->nullable()->comment('Takaran sebagai teks: "25 gr", "per 2 kg".');
            $table->string('pic')->nullable()->comment('Pelaksana bawaan.');
            $table->timestamps();

            $table->index(['recipe_id', 'sort_order']);
        });

        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->string('menu_label')->nullable();
            $table->string('worker_name')->nullable();
            $table->string('task')->nullable();
            $table->string('object')->nullable();
            $table->string('quantity_text', 100)->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_tasks');
        Schema::dropIfExists('recipe_tasks');
        Schema::dropIfExists('production_workers');
    }
};
