<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reference, never a secret: the secret itself lives in Vaultwarden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_references', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('kind', 30);
            $table->string('username')->nullable();
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->uuid('vault_item_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_references');
    }
};
