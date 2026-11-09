<?php

namespace Tests\Feature\Inventory;

use App\Models\Domain;
use App\Models\Provider;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * With a DNS server, the DNS host of a domain is that server's provider.
 */
class DnsServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dns_host_follows_the_provider_of_the_dns_server(): void
    {
        $package = Server::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        $domain = Domain::factory()->create(['dns_provider_id' => Provider::factory()->create()->id]);

        $domain->update(['dns_server_id' => $package->id]);

        $this->assertSame($package->provider_id, $domain->fresh()->dns_provider_id);
    }

    public function test_a_dns_server_of_your_own_leaves_no_dns_host(): void
    {
        $box = Server::factory()->create(['provider_id' => null]);
        $domain = Domain::factory()->create(['dns_provider_id' => Provider::factory()->create()->id]);

        $domain->update(['dns_server_id' => $box->id]);

        $this->assertNull($domain->fresh()->dns_provider_id);
    }

    public function test_the_dns_host_cannot_drift_from_the_dns_server(): void
    {
        $package = Server::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        $domain = Domain::factory()->create(['dns_server_id' => $package->id]);

        $domain->update(['dns_provider_id' => Provider::factory()->create()->id]);

        $this->assertSame($package->provider_id, $domain->fresh()->dns_provider_id);
    }

    public function test_a_loaded_relation_does_not_bring_back_the_old_server(): void
    {
        $old = Server::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        $new = Server::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        $domain = Domain::factory()->create(['dns_server_id' => $old->id]);
        $domain->load('dnsServer');

        $domain->update(['dns_server_id' => $new->id]);

        $this->assertSame($new->provider_id, $domain->fresh()->dns_provider_id);
    }

    public function test_clearing_the_dns_server_keeps_the_dns_host(): void
    {
        $package = Server::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        $domain = Domain::factory()->create(['dns_server_id' => $package->id]);

        $domain->update(['dns_server_id' => null]);

        $this->assertSame($package->provider_id, $domain->fresh()->dns_provider_id);
    }

    public function test_the_dns_host_follows_when_the_server_moves_to_another_provider(): void
    {
        $package = Server::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        $domain = Domain::factory()->create(['dns_server_id' => $package->id]);
        $untouched = Domain::factory()->create(['dns_provider_id' => $package->provider_id]);
        $other = Provider::factory()->create();

        $package->update(['provider_id' => $other->id]);

        $this->assertSame($other->id, $domain->fresh()->dns_provider_id);
        $this->assertTrue(Activity::query()->whereMorphedTo('subject', $domain)->where('event', 'updated')->get()
            ->contains(fn (Activity $activity): bool => ($activity->attribute_changes?->get('attributes')['dns_provider_id'] ?? null) === $other->id), 'The change is logged on the domain.');
        // Only the domains with their DNS on it.
        $this->assertSame($untouched->dns_provider_id, $untouched->fresh()->dns_provider_id);

        $package->update(['provider_id' => null]);

        $this->assertNull($domain->fresh()->dns_provider_id);
    }
}
