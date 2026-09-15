<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sumber perubahan harga kini bisa berupa nomor Form Kebutuhan
 * ("Form FKB-20260915-0002"), yang lebih panjang dari 20 karakter. Nomor form
 * paling panjang 40 karakter (requisitions.number), jadi 60 cukup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_item_price_histories', function (Blueprint $table) {
            $table->string('source', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_item_price_histories', function (Blueprint $table) {
            $table->string('source', 20)->change();
        });
    }
};
