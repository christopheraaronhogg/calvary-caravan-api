<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retreat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retreat_id')->constrained('retreats')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('retreat_participants')->cascadeOnDelete();
            $table->enum('message_type', ['chat', 'alert', 'status'])->default('chat');
            $table->string('content', 500);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->dateTime('created_at');

            $table->index(['retreat_id', 'created_at']);
            $table->index(['retreat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retreat_messages');
    }
};

