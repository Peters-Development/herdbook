<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_id')->constrained('servers')->cascadeOnDelete();
            $table->string('kind', 50);
            $table->string('notes')->nullable();

            // No unique index (see create_environment_server_table): DistinctItems validates instead.
            $table->index(['server_id', 'depends_on_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_dependencies');
    }
};
