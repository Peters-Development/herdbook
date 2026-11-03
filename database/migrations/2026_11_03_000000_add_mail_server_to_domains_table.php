<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Where its mail runs (a hosting package, a mail server): an outage
            // there reaches the environments of its hostnames.
            $table->foreignId('mail_server_id')->nullable()->after('dns_provider_id')->constrained('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_server_id');
        });
    }
};
