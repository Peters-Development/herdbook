<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Related to": incidents, documents and credential references link to
 * projects, environments, servers, domains, hostnames and clients. One pivot
 * per kind; the morph types are the aliases from AppServiceProvider.
 */
return new class extends Migration
{
    /** @var array<string, string> pivot table => owning table */
    private const array PIVOTS = [
        'incidentables' => 'incidents',
        'documentables' => 'documents',
        'credentialables' => 'credential_references',
    ];

    public function up(): void
    {
        foreach (self::PIVOTS as $pivot => $owner) {
            $morph = Str::singular($pivot);
            $foreignKey = Str::singular($owner).'_id';

            Schema::create($pivot, function (Blueprint $table) use ($pivot, $owner, $morph, $foreignKey) {
                $table->foreignId($foreignKey)->constrained($owner)->cascadeOnDelete();
                $table->morphs($morph);

                // Explicit name: the generated one exceeds MySQL's 64-character limit.
                $table->unique([$foreignKey, "{$morph}_type", "{$morph}_id"], "{$pivot}_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PIVOTS) as $pivot) {
            Schema::dropIfExists($pivot);
        }
    }
};
