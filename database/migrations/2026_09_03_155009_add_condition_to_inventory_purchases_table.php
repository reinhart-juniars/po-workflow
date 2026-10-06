<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengecekan kondisi barang saat kedatangan.
 *
 * Barang yang datang dalam kondisi tidak baik tidak boleh dihitung sebagai
 * stok tersedia. Uangnya tetap sudah keluar, jadi nilainya tidak dihapus dari
 * pembukuan melainkan dipindahkan dari Bahan Baku ke pos "Kerugian Barang
 * Rusak" di Laba Rugi -- total Laba tidak berubah, hanya komposisinya.
 *
 * Pembelian lama dianggap baik: sebelum kolom ini ada, tidak ada barang yang
 * pernah ditolak, sehingga seluruhnya memang sudah masuk sebagai stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->string('condition', 20)
                ->default('good')
                ->after('total_value')
                ->comment('good = barang baik dan menambah stok; damaged = tidak baik, tidak menambah stok');

            $table->text('condition_notes')
                ->nullable()
                ->after('condition');

            $table->timestamp('condition_checked_at')
                ->nullable()
                ->after('condition_notes');

            $table->foreignId('condition_checked_by')
                ->nullable()
                ->after('condition_checked_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Laporan pemakaian, neraca, dan laba rugi selalu menyaring kolom ini
        // bersama rentang tanggal, jadi indeksnya dipasangkan.
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->index(['condition', 'transaction_date'], 'inventory_purchases_condition_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->dropIndex('inventory_purchases_condition_date_index');
            $table->dropConstrainedForeignId('condition_checked_by');
            $table->dropColumn(['condition', 'condition_notes', 'condition_checked_at']);
        });
    }
};
