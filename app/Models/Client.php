<?php

namespace App\Models;

use App\Models\Concerns\Linkable;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deliberately small: a name, where to find the client in your invoicing or
 * CRM tool, and who to call when their site is down. Invoicing and customer
 * management live in that tool, not here.
 */
#[Fillable(['name', 'external_url', 'contact_name', 'contact_phone', 'contact_email', 'notes', 'archived_at'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, Linkable, RecordsActivity;

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}
