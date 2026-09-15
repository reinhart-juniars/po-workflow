<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meja terpisah di Form Kebutuhan: produksi MENGAJUKAN (status submitted),
 * supervisor gudang MENYETUJUI atau MENOLAK (kembali ke draft dengan alasan).
 * Sebelumnya draft bisa langsung disetujui dari halaman yang sama, sehingga
 * "dibuat" dan "disetujui" terasa satu lembar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('prepared_at')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['submitted_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
