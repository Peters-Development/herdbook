<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('notes')->nullable();

            // No unique index on (environment, server, role): swapping two roles
            // in one edit updates row by row and would collide halfway. The
            // form validates the end result instead (DistinctItems).
            $table->index(['environment_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_server');
    }
};
