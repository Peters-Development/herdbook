<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Where its DNS runs (a hosting package, a nameserver of your own):
            // an outage there reaches the environments of its hostnames.
            $table->foreignId('dns_server_id')->nullable()->after('mail_server_id')->constrained('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dns_server_id');
        });
    }
};
