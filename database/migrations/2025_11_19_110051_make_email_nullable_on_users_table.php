<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // kalau sebelumnya email unique, hapus dulu unique index-nya
            // default nama index dari $table->string('email')->unique() adalah 'users_email_unique'
            try {
                $table->dropUnique('users_email_unique');
            } catch (\Throwable $e) {
                // kalau index sudah hilang / beda nama, di-skip saja
            }

            // jadikan nullable
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // balikin ke NOT NULL + unique (kalau kamu mau)
            $table->string('email')->nullable(false)->unique()->change();
        });
    }
};

