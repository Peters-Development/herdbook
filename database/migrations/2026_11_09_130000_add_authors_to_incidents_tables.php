<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote an incident down (null: monitoring opened it, or it was
 * imported), and the agent an incident or a timeline entry was recorded
 * through (the name of its token, kept after the token is revoked; null:
 * by hand in the panel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('imported_from')->constrained()->nullOnDelete();
            $table->string('recorded_via')->nullable()->after('user_id');
        });

        Schema::table('incident_timeline_entries', function (Blueprint $table) {
            $table->string('recorded_via')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_timeline_entries', function (Blueprint $table) {
            $table->dropColumn('recorded_via');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('recorded_via');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
