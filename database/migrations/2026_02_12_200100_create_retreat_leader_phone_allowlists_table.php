<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retreat_leader_phone_allowlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retreat_id')->constrained('retreats')->cascadeOnDelete();
            $table->string('phone_e164', 20);
            $table->timestamps();

            $table->index('phone_e164');
            $table->unique(['retreat_id', 'phone_e164'], 'retreat_leader_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retreat_leader_phone_allowlists');
    }
};
