<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
                if (!Schema::hasColumn('purchase_orders','created_by')) {
                    $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('purchase_orders','updated_by')) {
                    $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                }
        });

        Schema::table('spks', function (Blueprint $t) {
                if (!Schema::hasColumn('spks','created_by')) {
                    $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('spks','updated_by')) {
                    $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                }
        });

        Schema::table('delivery_orders', function (Blueprint $t) {
                if (!Schema::hasColumn('delivery_orders','created_by')) {
                    $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('delivery_orders','updated_by')) {
                    $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
                if (Schema::hasColumn('purchase_orders','created_by')) $t->dropConstrainedForeignId('created_by');
                if (Schema::hasColumn('purchase_orders','updated_by')) $t->dropConstrainedForeignId('updated_by');
        });

        Schema::table('spks', function (Blueprint $t) {
                if (Schema::hasColumn('spks','created_by')) $t->dropConstrainedForeignId('created_by');
                if (Schema::hasColumn('spks','updated_by')) $t->dropConstrainedForeignId('updated_by');
        });

        Schema::table('delivery_orders', function (Blueprint $t) {
                if (Schema::hasColumn('delivery_orders','created_by')) $t->dropConstrainedForeignId('created_by');
                if (Schema::hasColumn('delivery_orders','updated_by')) $t->dropConstrainedForeignId('updated_by');
        });
    }
};
