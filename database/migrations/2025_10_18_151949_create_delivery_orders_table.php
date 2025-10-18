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
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('do_code')->unique();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->dateTime('scheduled_at')->index();
            $table->foreignId('driver_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['ready','on_delivery','delivered'])->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};
