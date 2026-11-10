<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agent a maintenance was recorded through (the name of its token, kept
 * after the token is revoked); null: by hand in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('recorded_via')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('recorded_via');
        });
    }
};
