<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('retreat_participants')->cascadeOnDelete();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 6, 2)->nullable();
            $table->decimal('speed', 5, 2)->nullable();
            $table->decimal('heading', 5, 2)->nullable();
            $table->decimal('altitude', 7, 2)->nullable();
            $table->dateTime('recorded_at');
            $table->dateTime('created_at');

            $table->index('participant_id');
            $table->index('recorded_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_locations');
    }
};

