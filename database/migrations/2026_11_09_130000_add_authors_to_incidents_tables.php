<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote an incident or an action item down (null: monitoring opened it,
 * it was imported, or it predates this), and the agent an incident, a
 * timeline entry or an action item was recorded through (the name of its
 * token, kept after the token is revoked; null: by hand in the panel); and
 * who completed an action item, through which agent.
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

        Schema::table('incident_actions', function (Blueprint $table) {
            $table->foreignId('completed_by_id')->nullable()->after('done_at')->constrained('users')->nullOnDelete();
            $table->string('completed_via')->nullable()->after('completed_by_id');
            $table->foreignId('user_id')->nullable()->after('completed_via')->constrained()->nullOnDelete();
            $table->string('recorded_via')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_actions', function (Blueprint $table) {
            $table->dropColumn(['recorded_via', 'completed_via']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('completed_by_id');
        });

        Schema::table('incident_timeline_entries', function (Blueprint $table) {
            $table->dropColumn('recorded_via');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('recorded_via');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
