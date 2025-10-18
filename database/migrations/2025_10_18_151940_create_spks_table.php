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
        Schema::create('spks', function (Blueprint $table) {
            $table->id();
            $table->string('spk_code')->unique();
            $table->dateTime('scheduled_at')->index();
            $table->enum('slot_type', ['fixed_03','fixed_07','fixed_11','custom'])->index();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft','in_process','completed'])->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spks');
    }
};
