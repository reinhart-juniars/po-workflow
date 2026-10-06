<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('entity');
                $table->unsignedBigInteger('entity_id');
                $table->string('action');
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->string('ip_address')->nullable();
                $table->timestamps();
                $table->index(['entity','entity_id']);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
