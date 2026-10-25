<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('severity', 10);
            $table->string('status', 20)->index();
            $table->date('occurred_on')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('impact')->nullable();
            $table->longText('root_cause')->nullable();
            $table->longText('body')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('imported_from')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('incident_timeline_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->text('note');
            $table->timestamps();
        });

        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_actions');
        Schema::dropIfExists('incident_timeline_entries');
        Schema::dropIfExists('incidents');
    }
};
