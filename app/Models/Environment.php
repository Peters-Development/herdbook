<?php

namespace App\Models;

use App\Enums\EnvironmentKind;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Production, staging, ...: sits between a project and its servers, because
 * the environments of one project run on different servers, and one
 * environment can span several (an app server plus a storage server).
 */
#[Fillable(['project_id', 'name', 'kind', 'deploy_method', 'branch', 'path', 'notes', 'sort_order'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, RecordsActivity;

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        // Its hostnames stay (without an environment), but their checks no
        // longer belong to anything that is watched: paused, not paging.
        static::deleting(function (Environment $environment): void {
            Check::query()
                ->where('enabled', true)
                ->whereIn('hostname_id', $environment->hostnames()->select('id'))
                ->get()
                ->each->update(['enabled' => false]);
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Server + role rows, for editing (one server can have several roles).
     *
     * @return HasMany<EnvironmentServer, $this>
     */
    public function serverAssignments(): HasMany
    {
        return $this->hasMany(EnvironmentServer::class);
    }

    /** @return BelongsToMany<Server, $this, EnvironmentServer> */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)
            ->using(EnvironmentServer::class)
            ->withPivot(['id', 'role', 'notes']);
    }

    /** @return HasMany<Hostname, $this> */
    public function hostnames(): HasMany
    {
        return $this->hasMany(Hostname::class);
    }

    /**
     * "acme · Production": how an environment reads outside its project.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->project->name} · {$this->name}");
    }

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        return ['project_id' => $this->project_id, 'label' => $this->name];
    }

    protected function casts(): array
    {
        return [
            'kind' => EnvironmentKind::class,
            'sort_order' => 'integer',
        ];
    }
}
