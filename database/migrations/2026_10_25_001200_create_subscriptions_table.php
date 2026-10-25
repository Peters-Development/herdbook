<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan')->nullable();
            // Who it is for: a project, a client, or neither (your own).
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // HasCost (excluding VAT)
            $table->decimal('cost_amount', 10, 2)->nullable();
            $table->string('cost_period', 10)->nullable();

            // HasRenewal
            $table->date('expires_on')->nullable();
            $table->boolean('auto_renews')->default(true);
            $table->unsignedTinyInteger('renewal_period_months')->default(12);
            $table->unsignedSmallInteger('renewal_lead_days')->nullable();
            $table->timestamp('renewal_stopped_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
