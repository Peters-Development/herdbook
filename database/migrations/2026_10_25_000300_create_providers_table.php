<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('kinds');
            $table->string('website_url')->nullable();
            $table->string('panel_url')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone', 50)->nullable();
            $table->unsignedSmallInteger('renewal_lead_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
