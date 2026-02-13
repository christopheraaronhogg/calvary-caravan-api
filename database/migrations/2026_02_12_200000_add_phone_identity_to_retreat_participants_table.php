<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retreat_participants', function (Blueprint $table) {
            $table->string('phone_e164', 20)->nullable()->after('name');
            $table->index('phone_e164');
            $table->unique(['retreat_id', 'phone_e164'], 'retreat_participants_retreat_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('retreat_participants', function (Blueprint $table) {
            $table->dropUnique('retreat_participants_retreat_phone_unique');
            $table->dropIndex(['phone_e164']);
            $table->dropColumn('phone_e164');
        });
    }
};
