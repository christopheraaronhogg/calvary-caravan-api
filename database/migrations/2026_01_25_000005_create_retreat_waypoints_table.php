<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retreat_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retreat_id')->constrained('retreats')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->integer('waypoint_order')->default(0);
            $table->dateTime('eta')->nullable();
            $table->dateTime('created_at');

            $table->index(['retreat_id', 'waypoint_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retreat_waypoints');
    }
};

