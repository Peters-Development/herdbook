<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostname_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name');
            // Room for https:// + a 253-character name + a path.
            $table->string('target', 500);
            $table->json('settings')->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedTinyInteger('failure_threshold')->default(3);
            $table->unsignedTinyInteger('success_threshold')->default(2);
            $table->string('alert_level', 20)->default('normal');
            $table->boolean('enabled')->default(true);
            $table->string('state', 20)->default('unknown');
            // Capped (CheckStateMachine::MAX_COUNT): a check that is up for months
            // must not outgrow the column.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_successes')->default(0);
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_state_changed_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        // Raw results, pruned after herdbook.checks.keep_results_days.
        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 500)->nullable();

            $table->index(['check_id', 'checked_at']);
            // Aggregation and pruning select on time alone.
            $table->index('checked_at');
        });

        // Roll-ups of the raw results: hours are pruned after
        // herdbook.checks.keep_hour_stats_days, days are kept.
        Schema::create('check_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->string('granularity', 10);
            $table->timestamp('period_start');
            $table->unsignedInteger('up_count')->default(0);
            $table->unsignedInteger('down_count')->default(0);
            $table->unsignedInteger('avg_ms')->nullable();
            $table->unsignedInteger('min_ms')->nullable();
            $table->unsignedInteger('max_ms')->nullable();

            $table->unique(['check_id', 'granularity', 'period_start']);
            // checks:aggregate looks up the latest hour rolled up, across all checks.
            $table->index(['granularity', 'period_start']);
        });

        // Only the switches between up and down.
        Schema::create('check_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->string('from_state', 20);
            $table->string('to_state', 20);
            $table->timestamp('occurred_at');
            $table->string('error', 500)->nullable();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            // Set once CheckIncidents decided about this event (an incident, or
            // none needed): a deleted draft incident must not come back.
            $table->timestamp('incident_evaluated_at')->nullable();
            $table->timestamp('notified_at')->nullable()->index();
            // Failed sends, and whether the notification was given up on
            // (CheckAlerts::GIVE_UP_AFTER_HOURS) or had no channel at all.
            $table->unsignedSmallInteger('notify_attempts')->default(0);
            $table->boolean('notify_failed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_events');
        Schema::dropIfExists('check_stats');
        Schema::dropIfExists('check_results');
        Schema::dropIfExists('checks');
    }
};
