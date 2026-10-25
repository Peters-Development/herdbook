<?php

namespace App\Models;

use App\Enums\ServerRole;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * "Runs on": an environment uses a server in a role (app, storage, ...).
 */
#[Fillable(['environment_id', 'server_id', 'role', 'notes'])]
class EnvironmentServer extends Pivot
{
    use RecordsActivity;

    public $incrementing = true;

    public $timestamps = false;

    protected $table = 'environment_server';

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        // Explicit queries: these rows are often saved or deleted as part of a
        // repeater collection, where lazy loading is not allowed (strict mode).
        $environment = Environment::query()->whereKey($this->environment_id)->first(['name', 'project_id']);
        $server = Server::query()->whereKey($this->server_id)->value('name');

        return [
            'project_id' => $environment?->project_id,
            'server_id' => $this->server_id,
            'label' => __(':server (:role) in :environment', ['server' => $server, 'role' => $this->role?->getLabel(), 'environment' => $environment?->name]),
        ];
    }

    protected function casts(): array
    {
        return [
            'role' => ServerRole::class,
        ];
    }
}
