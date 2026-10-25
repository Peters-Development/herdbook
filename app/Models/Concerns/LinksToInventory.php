<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Incidents, maintenance, documents and credential references link to
 * inventory items through one pivot each (incidentables, maintainables,
 * documentables, credentialables).
 * The other side: Linkable. Only items with a page that shows the links:
 * projects, servers, domains, networks and clients (not environments or
 * hostnames, which have no page of their own to show them on).
 */
trait LinksToInventory
{
    /**
     * The morph name of the pivot: incidentable, maintainable, documentable, credentialable.
     */
    abstract protected function linkMorphName(): string;

    /** @return MorphToMany<Project, $this> */
    public function projects(): MorphToMany
    {
        return $this->morphedByMany(Project::class, $this->linkMorphName());
    }

    /** @return MorphToMany<Server, $this> */
    public function servers(): MorphToMany
    {
        return $this->morphedByMany(Server::class, $this->linkMorphName());
    }

    /** @return MorphToMany<Domain, $this> */
    public function domains(): MorphToMany
    {
        return $this->morphedByMany(Domain::class, $this->linkMorphName());
    }

    /** @return MorphToMany<Network, $this> */
    public function networks(): MorphToMany
    {
        return $this->morphedByMany(Network::class, $this->linkMorphName());
    }

    /** @return MorphToMany<Client, $this> */
    public function clients(): MorphToMany
    {
        return $this->morphedByMany(Client::class, $this->linkMorphName());
    }
}
