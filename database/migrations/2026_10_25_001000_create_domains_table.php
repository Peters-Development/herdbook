<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registrar_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('dns_provider_id')->nullable()->constrained('providers')->nullOnDelete();
            // A domain in the local DNS only (e.g. "home.arpa"), registered
            // nowhere: nothing to renew or pay, and every hostname under it is local.
            $table->boolean('local_only')->default(false);

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
        Schema::dropIfExists('domains');
    }
};
