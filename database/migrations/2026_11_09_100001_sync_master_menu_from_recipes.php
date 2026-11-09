<?php

use App\Services\MasterMenuSync;
use App\Services\ProductRecipeCostSync;
use Illuminate\Database\Migrations\Migration;

/**
 * Revisi 9 Nov 2026: Harga Menu dan PO hanya berisi menu jual Menu Utama
 * (Product::scopeOrderable). Sinkron data lama dijalankan di sini, bukan
 * manual sesudah deploy, supaya Harga Menu dan PO tidak sempat kosong:
 * deploy.sh menjalankan migrate saat aplikasi masih mode perawatan.
 *
 * Isinya sama dengan `php artisan menu:sync-from-recipes` (aman diulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(MasterMenuSync::class)->syncAll();
        app(ProductRecipeCostSync::class)->flush();
    }

    /** Nama & status menu sebelum sinkron tidak disimpan; pulihkan dari backup deploy bila perlu. */
    public function down(): void {}
};
