<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance on servers and projects: planned ahead or written down
 * afterwards. Linked like incidents ("Related to"), through its own
 * pivot with the morph aliases from AppServiceProvider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->json('tasks');
            $table->string('status', 20);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->longText('plan')->nullable();
            $table->longText('report')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('maintainables', function (Blueprint $table) {
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->morphs('maintainable');

            $table->unique(['maintenance_id', 'maintainable_type', 'maintainable_id'], 'maintainables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintainables');
        Schema::dropIfExists('maintenances');
    }
};
