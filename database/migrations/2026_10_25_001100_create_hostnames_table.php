<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fqdn')->unique();
            $table->string('purpose')->nullable();
            // "www.example.com" that only sends visitors on to "example.com":
            // its HTTP check checks the redirect instead of following it.
            $table->string('redirects_to')->nullable();
            $table->boolean('behind_proxy')->default(false);
            // A name in the local DNS only (e.g. "app.home.arpa"), usually with a
            // certificate from a local CA: monitored by its HTTP check alone.
            $table->boolean('local_only')->default(false);
            $table->json('expected_ips')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostnames');
    }
};
