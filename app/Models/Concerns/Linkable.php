<?php

namespace App\Models\Concerns;

use App\Models\CredentialReference;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Maintenance;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * An inventory item that incidents, maintenance, documents and credential
 * references can be linked to ("related to"): projects, servers,
 * domains, networks, clients (maintenance only to projects and servers).
 * The other side: LinksToInventory.
 */
trait Linkable
{
    /**
     * The link pivots have no foreign key on the morph side: clean them up.
     */
    public static function bootLinkable(): void
    {
        static::deleting(function (self $model): void {
            $model->incidents()->detach();
            $model->maintenances()->detach();
            $model->documents()->detach();
            $model->credentialReferences()->detach();
        });
    }

    /** @return MorphToMany<Incident, $this> */
    public function incidents(): MorphToMany
    {
        return $this->morphToMany(Incident::class, 'incidentable');
    }

    /** @return MorphToMany<Maintenance, $this> */
    public function maintenances(): MorphToMany
    {
        return $this->morphToMany(Maintenance::class, 'maintainable');
    }

    /** @return MorphToMany<Document, $this> */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable');
    }

    /** @return MorphToMany<CredentialReference, $this> */
    public function credentialReferences(): MorphToMany
    {
        return $this->morphToMany(CredentialReference::class, 'credentialable');
    }
}
