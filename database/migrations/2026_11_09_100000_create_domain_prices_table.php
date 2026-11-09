<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The price list of a registrar: what a domain with this extension
        // costs per year, for the domains without an amount of their own.
        Schema::create('domain_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            // Without the leading dot: "com", "nl", "co.uk".
            $table->string('extension', 63);
            $table->decimal('cost_amount', 10, 2);
            $table->timestamps();

            $table->unique(['provider_id', 'extension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_prices');
    }
};
