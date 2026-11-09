<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent that may use the MCP server (/mcp) for an account. Its token is
 * shown once (agents:create); only its sha256 hash is stored. expires_at is
 * a datetime: it lies ahead, and a timestamp column ends in 2038.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('access', 10);
            $table->dateTime('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tokens');
    }
};
