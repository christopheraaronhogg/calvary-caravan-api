<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retreat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retreat_id')->constrained('retreats')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('device_token', 64)->unique()->nullable();
            $table->string('vehicle_color', 30)->nullable();
            $table->string('vehicle_description', 50)->nullable();
            $table->boolean('is_leader')->default(false);
            $table->string('expo_push_token')->nullable();
            $table->dateTime('joined_at');
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('retreat_id');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retreat_participants');
    }
};

