<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal dikerjakan Rencana Masak.
 *
 * production_date mengikuti tanggal kirim PO yang mengambil porsinya, bukan hari
 * dapur memasak: rencana tanggal 5 dikerjakan tanggal 4. Kosong pada rencana lama
 * dibaca sebagai H-1 (ProductionOrder::workDate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->date('work_date')->nullable()->after('production_date');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('work_date');
        });
    }
};
