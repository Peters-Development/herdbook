<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // The internet provider of the line (ProviderKind::Internet).
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location')->nullable();
            // CIDR: an IPv6 address (45) plus "/128".
            $table->string('subnet', 49)->nullable();
            $table->string('gateway', 45)->nullable();
            $table->string('public_ip', 45)->nullable();

            // HasCost: the internet line (excluding VAT)
            $table->decimal('cost_amount', 10, 2)->nullable();
            $table->string('cost_period', 10)->nullable();

            // HasRenewal: the contract of the line
            $table->date('expires_on')->nullable();
            $table->boolean('auto_renews')->default(true);
            $table->unsignedTinyInteger('renewal_period_months')->default(12);
            $table->unsignedSmallInteger('renewal_lead_days')->nullable();
            $table->timestamp('renewal_stopped_at')->nullable();

            $table->longText('notes')->nullable();
            $table->timestamps();
        });

        // Other devices on the network, for reference. Servers are linked
        // through servers.network_id and keep their own addresses.
        Schema::create('network_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45);
            $table->string('name');
            $table->string('mac_address', 17)->nullable();
            $table->string('notes')->nullable();

            // No unique index (see create_environment_server_table): DistinctItems validates instead.
            $table->index(['network_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_addresses');
        Schema::dropIfExists('networks');
    }
};
