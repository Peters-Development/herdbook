<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Concerns\Linkable;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The centre of the app: environments, servers, hostnames, incidents,
 * documents and credentials all hang off a project.
 */
#[Fillable(['client_id', 'name', 'slug', 'type', 'status', 'on_status_screen', 'unmonitored', 'description', 'repository_url', 'tags', 'notes'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, Linkable, RecordsActivity;

    protected $attributes = [
        'status' => 'active',
        'on_status_screen' => false,
        'unmonitored' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->slug ??= Str::slug($project->name);
        });

        // An archived project pages nobody: the checks of its hostnames are paused.
        static::updated(function (Project $project): void {
            if ($project->wasChanged('status') && $project->status === ProjectStatus::Archived) {
                Check::query()
                    ->where('enabled', true)
                    ->whereIn('hostname_id', $project->hostnames()->select('hostnames.id'))
                    ->get()
                    ->each->update(['enabled' => false]);
            }
        });

        // Through Eloquent, not the cascading foreign key, so each removed
        // environment is logged on the project's activity.
        static::deleting(function (Project $project): void {
            $project->environments()->get()->each->delete();
        });
    }

    /**
     * The children go through Eloquent (see booted()), so all of it is one
     * transaction: a failure halfway must not leave the parent without them.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasManyThrough<Hostname, Environment, $this> */
    public function hostnames(): HasManyThrough
    {
        return $this->hasManyThrough(Hostname::class, Environment::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'on_status_screen' => 'boolean',
            'unmonitored' => 'boolean',
            'tags' => 'array',
        ];
    }
}
