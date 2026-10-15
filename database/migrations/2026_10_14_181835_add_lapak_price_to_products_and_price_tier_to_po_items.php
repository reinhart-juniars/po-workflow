<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi v3.1: menu punya dua harga jual -- normal dan lapak (lapak milik
 * owner yang pesanannya pasti). Admin memilih per item PO harga mana yang
 * dipakai. Harga lapak kosong = menu itu tidak punya harga khusus lapak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('lapak_price', 12, 2)->nullable()->after('base_price');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Item lama semuanya memakai harga normal.
            $table->string('price_tier', 10)->default('normal')->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('price_tier');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('lapak_price');
        });
    }
};
