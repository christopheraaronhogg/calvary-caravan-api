<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retreat_participants')) {
            return;
        }

        Schema::table('retreat_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('retreat_participants', 'location_sharing_enabled')) {
                $table->boolean('location_sharing_enabled')->default(true)->after('is_leader');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('retreat_participants')) {
            return;
        }

        if (Schema::hasColumn('retreat_participants', 'location_sharing_enabled')) {
            Schema::table('retreat_participants', function (Blueprint $table) {
                $table->dropColumn('location_sharing_enabled');
            });
        }
    }
};
