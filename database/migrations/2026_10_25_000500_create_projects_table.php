<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 30);
            $table->string('status', 30)->default('active')->index();
            // Shown on the status screen (StatusScreenPayload).
            $table->boolean('on_status_screen')->default(false);
            // Deliberately without checks (e.g. something on the local network),
            // so its missing checks are not a gap (HealthState::Unmonitored).
            $table->boolean('unmonitored')->default(false);
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->json('tags')->nullable();
            $table->longText('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
