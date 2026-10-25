<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->unique();
            $table->string('provider_hostname')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->string('plan')->nullable();
            $table->string('location')->nullable();
            $table->string('public_ipv4', 15)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->string('private_ip', 45)->nullable();
            $table->string('tailscale_ip', 45)->nullable();
            $table->unsignedTinyInteger('vcpu')->nullable();
            $table->unsignedSmallInteger('ram_gb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable();
            $table->string('os_name')->nullable();
            $table->string('os_version', 20)->nullable();
            $table->string('ssh_user')->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('backup_policy')->nullable();

            // HasCost (excluding VAT)
            $table->decimal('cost_amount', 10, 2)->nullable();
            $table->string('cost_period', 10)->nullable();

            // HasRenewal: the hosting contract
            $table->date('expires_on')->nullable();
            $table->boolean('auto_renews')->default(true);
            $table->unsignedTinyInteger('renewal_period_months')->default(12);
            $table->unsignedSmallInteger('renewal_lead_days')->nullable();
            $table->timestamp('renewal_stopped_at')->nullable();

            $table->longText('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
