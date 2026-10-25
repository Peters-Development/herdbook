<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A display that may read the status screen. Its token is shown once
 * (screens:create); only its sha256 hash is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_screens', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_screens');
    }
};
