<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServerRole;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Mcp\HiddenValues;
use App\Mcp\Serializer;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Incident;
use App\Models\Network;
use App\Models\NetworkAddress;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class IncidentReadToolsTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:00');
        config(['herdbook.mcp.enabled' => true]);
        [, $this->token] = $this->agentToken();
    }

    public function test_an_item_has_exactly_these_fields_and_nothing_of_the_inventory_it_doesnt_name(): void
    {
        $server = Server::factory()->create(['name' => 'app-1', 'public_ipv4' => '192.0.2.10', 'notes' => 'secret-notes']);
        $project = Project::factory()->create(['name' => 'Shop']);
        $incident = Incident::factory()->for(User::factory()->state(['name' => 'Ada']))->create([
            'title' => 'Uploads failed',
            'severity' => IncidentSeverity::Sev1,
            'status' => IncidentStatus::Monitoring,
            'occurred_on' => '2026-11-08',
            'started_at' => '2026-11-08 21:00:00',
            'detected_at' => '2026-11-08 21:05:00',
            'resolved_at' => null,
            'body' => 'the-report',
        ]);
        $incident->forceFill(['recorded_via' => 'deploy-agent'])->save();
        $incident->servers()->attach($server);
        $incident->projects()->attach($project);
        $incident->domains()->attach(Domain::factory()->create(['name' => 'example.com']));
        $incident->networks()->attach(Network::factory()->create(['name' => 'Office']));
        $incident->clients()->attach(Client::factory()->create(['name' => 'Acme']));
        $incident->actions()->createMany([['description' => 'Alert on NFS latency'], ['description' => 'Done already', 'done_at' => now()]]);

        $found = $this->callToolSuccessfully($this->token, 'list_incidents');

        $this->assertSame([[
            'id' => $incident->id,
            'title' => 'Uploads failed',
            'severity' => 'sev1',
            'status' => 'monitoring',
            'source' => 'manual',
            'occurred_on' => '2026-11-08',
            'started_at' => '2026-11-08T22:00:00+01:00',
            'detected_at' => '2026-11-08T22:05:00+01:00',
            'resolved_at' => null,
            'projects' => [['id' => $project->id, 'name' => 'Shop']],
            'servers' => [['id' => $server->id, 'name' => 'app-1']],
            'domains' => [['id' => $incident->domains->sole()->id, 'name' => 'example.com']],
            'networks' => [['id' => $incident->networks->sole()->id, 'name' => 'Office']],
            'clients' => [['id' => $incident->clients->sole()->id, 'name' => 'Acme']],
            'open_actions' => 1,
            'created_by' => 'Ada',
            'via' => 'deploy-agent',
            'version' => $incident->fresh()->version(),
            'url' => IncidentResource::getUrl('view', ['record' => $incident]),
        ]], $found['incidents']);
        $this->assertFalse($found['more']);

        $answer = json_encode($found, JSON_THROW_ON_ERROR);
        foreach (['the-report', '192.0.2.10', 'secret-notes'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $answer);
        }
    }

    public function test_unresolved_by_default_open_first_then_by_severity(): void
    {
        $watched = Incident::factory()->create(['status' => IncidentStatus::Monitoring, 'severity' => IncidentSeverity::Sev1]);
        // Made first, and later: neither the id nor the date puts it after the critical one.
        $critical = Incident::factory()->create(['status' => IncidentStatus::Open, 'severity' => IncidentSeverity::Sev1, 'occurred_on' => '2026-11-01']);
        $minor = Incident::factory()->create(['status' => IncidentStatus::Open, 'severity' => IncidentSeverity::Sev3, 'occurred_on' => '2026-11-09']);
        $resolved = Incident::factory()->resolved()->create();

        $this->assertSame([$critical->id, $minor->id, $watched->id], $this->ids());
        $this->assertSame([$critical->id, $minor->id], $this->ids(['status' => 'open']));
        $this->assertSame([$watched->id], $this->ids(['status' => 'monitoring']));
        $this->assertSame([$resolved->id], $this->ids(['status' => 'resolved']));
        $this->assertSame([$minor->id], $this->ids(['severity' => 'sev3']));
        $this->assertCount(4, $this->ids(['status' => 'any']));
    }

    public function test_since_leaves_out_what_occurred_before_that_day(): void
    {
        Incident::factory()->create(['occurred_on' => '2026-11-01']);
        $recent = Incident::factory()->create(['occurred_on' => '2026-11-05']);

        $this->assertSame([$recent->id], $this->ids(['since' => '2026-11-05']));
        $this->assertStringContainsString('since', $this->callToolError($this->token, 'list_incidents', ['since' => 'last week']));
    }

    public function test_the_incidents_of_a_project_reach_as_far_as_herdbook_counts_them(): void
    {
        $production = Environment::factory()->create();
        $app = Server::factory()->create(['network_id' => ($network = Network::factory()->create())->id]);
        EnvironmentServer::create(['environment_id' => $production->id, 'server_id' => $app->id, 'role' => ServerRole::App]);

        $onProject = Incident::factory()->resolved()->create();
        $onProject->projects()->attach($production->project_id);
        $onServer = Incident::factory()->create();
        $onServer->servers()->attach($app);
        $onNetwork = Incident::factory()->create();
        $onNetwork->networks()->attach($network);
        Incident::factory()->create()->servers()->attach(Server::factory()->create());

        $this->assertEqualsCanonicalizing([$onProject->id, $onServer->id, $onNetwork->id], $this->ids(['status' => 'any', 'project_ids' => [$production->project_id]]));
        $this->assertSame([$onServer->id], $this->ids(['server_ids' => [$app->id]]));

        // Either: a server and a project together find what either finds.
        $elsewhere = Incident::factory()->create();
        $elsewhere->servers()->attach($other = Server::factory()->create());
        $this->assertEqualsCanonicalizing([$onServer->id, $onNetwork->id, $elsewhere->id], $this->ids(['project_ids' => [$production->project_id], 'server_ids' => [$other->id]]));
    }

    public function test_more_says_there_are_more_than_it_shows(): void
    {
        Incident::factory()->count(3)->create();

        $found = $this->callToolSuccessfully($this->token, 'list_incidents', ['limit' => 2]);

        $this->assertSame([2, true], [count($found['incidents']), $found['more']]);
    }

    public function test_get_incident_adds_its_texts_timeline_and_actions(): void
    {
        $incident = Incident::factory()->create(['impact' => 'Uploads failed for an hour.', 'root_cause' => 'NFS stalled.', 'body' => 'The report.', 'source' => IncidentSource::Check]);
        $incident->timelineEntries()->create(['occurred_at' => '2026-11-08 21:05:00', 'note' => 'Check HTTP shop.example.com went down: HTTP 503']);
        $incident->timelineEntries()->create(['occurred_at' => '2026-11-08 21:20:00', 'note' => 'Restarted php-fpm, no effect.', 'user_id' => User::factory()->create(['name' => 'Ada'])->id, 'recorded_via' => 'deploy-agent']);
        $action = $incident->actions()->create(['description' => 'Alert on NFS latency', 'due_on' => '2026-11-01']);

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame([...array_keys(Serializer::incident($incident->load(Serializer::INCIDENT_RELATIONS))), 'impact', 'root_cause', 'report', 'truncated', 'texts_changed', 'timeline', 'more_timeline', 'actions', 'more_actions'], array_keys($found));
        $this->assertSame(['Uploads failed for an hour.', 'NFS stalled.', 'The report.', [], ['root_cause' => null, 'report' => null]], [$found['impact'], $found['root_cause'], $found['report'], $found['truncated'], $found['texts_changed']]);
        $this->assertSame([
            ['occurred_at' => '2026-11-08T22:05:00+01:00', 'note' => 'Check HTTP shop.example.com went down: HTTP 503', 'truncated' => false, 'created_by' => null, 'via' => null],
            ['occurred_at' => '2026-11-08T22:20:00+01:00', 'note' => 'Restarted php-fpm, no effect.', 'truncated' => false, 'created_by' => 'Ada', 'via' => 'deploy-agent'],
        ], $found['timeline']);
        $this->assertFalse($found['more_timeline']);
        $this->assertSame([['id' => $action->id, 'description' => 'Alert on NFS latency', 'due_on' => '2026-11-01', 'done_at' => null, 'overdue' => true, 'created_by' => null, 'via' => null, 'completed_by' => null, 'completed_via' => null]], $found['actions']);
    }

    public function test_a_long_timeline_shows_its_latest_entries_and_long_texts_are_cut(): void
    {
        $incident = Incident::factory()->create(['body' => str_repeat('a', Serializer::MAX_TEXT + 1)]);
        foreach (range(1, Serializer::TIMELINE_ENTRIES + 1) as $minute) {
            $incident->timelineEntries()->create(['occurred_at' => now()->subMinutes(200 - $minute), 'note' => "Entry {$minute}"]);
        }

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame(['report'], $found['truncated']);
        $this->assertSame(Serializer::MAX_TEXT, mb_strlen($found['report']));
        $this->assertCount(Serializer::TIMELINE_ENTRIES, $found['timeline']);
        $this->assertTrue($found['more_timeline']);
        // The latest, in order: the first entry is the one left out.
        $this->assertSame(['Entry 2', 'Entry '.(Serializer::TIMELINE_ENTRIES + 1)], [$found['timeline'][0]['note'], $found['timeline'][Serializer::TIMELINE_ENTRIES - 1]['note']]);
    }

    public function test_long_notes_are_cut_and_the_timeline_stops_where_the_answer_would_grow_too_long(): void
    {
        $incident = Incident::factory()->create();
        foreach (range(1, 20) as $minute) {
            $incident->timelineEntries()->create(['occurred_at' => now()->subMinutes(100 - $minute), 'note' => "Entry {$minute} ".str_repeat('x', Serializer::MAX_NOTE)]);
        }

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $shown = intdiv(Serializer::TIMELINE_CHARACTERS, Serializer::MAX_NOTE + Serializer::ENTRY_CHARACTERS);
        $this->assertCount($shown, $found['timeline']);
        $this->assertTrue($found['more_timeline']);
        $this->assertSame([Serializer::MAX_NOTE, true], [mb_strlen($found['timeline'][0]['note']), $found['timeline'][0]['truncated']]);
        $this->assertStringStartsWith('Entry 20 ', $found['timeline'][$shown - 1]['note']);
    }

    public function test_entries_at_the_same_moment_come_in_the_order_they_were_written(): void
    {
        $incident = Incident::factory()->create();
        foreach (['First', 'Second', 'Third'] as $note) {
            $incident->timelineEntries()->create(['occurred_at' => '2026-11-08 21:00:00', 'note' => $note]);
        }

        $this->assertSame(['First', 'Second', 'Third'], array_column($this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id])['timeline'], 'note'));
    }

    public function test_addresses_monitoring_wrote_into_an_incident_are_masked(): void
    {
        Server::factory()->create(['name' => 'app-1', 'public_ipv4' => '192.0.2.10', 'provider_hostname' => 'vps-123.provider.example']);
        Network::factory()->create(['name' => 'Office', 'public_ip' => '198.51.100.7']);
        $incident = Incident::factory()->create([
            'source' => IncidentSource::Check,
            'title' => 'Shop: Ping 192.0.2.10 unreachable',
            'impact' => '192.0.2.10 does not answer ping; 192.0.2.1 and 2001:db8::1 neither. Since 12:30:45, version 8.5.',
            'root_cause' => 'The line at 198.51.100.7 went down.',
            'body' => 'vps-123.provider.example rebooted.',
        ]);
        $incident->timelineEntries()->create(['occurred_at' => '2026-11-08 21:05:00', 'note' => 'Check Ping 192.0.2.10 went down: 192.0.2.10 does not answer ping.']);

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame('Shop: Ping [adres van app-1] unreachable', $found['title']);
        $this->assertSame('[adres van app-1] does not answer ping; [adres] and [adres] neither. Since 12:30:45, version 8.5.', $found['impact']);
        $this->assertSame('The line at [adres van Office] went down.', $found['root_cause']);
        $this->assertSame('[adres van app-1] rebooted.', $found['report']);
        $this->assertSame('Check Ping [adres van app-1] went down: [adres van app-1] does not answer ping.', $found['timeline'][0]['note']);
        $this->assertSame('Shop: Ping [adres van app-1] unreachable', $this->callToolSuccessfully($this->token, 'list_incidents')['incidents'][0]['title']);

        $answer = json_encode($found, JSON_THROW_ON_ERROR);
        foreach (['192.0.2.1', '2001:db8', '198.51.100.7', 'vps-123'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $answer);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function addressesInText(): array
    {
        return [
            'with a port, as the TLS check says' => ['No TLS connection to 192.0.2.10:443.', 'No TLS connection to [adres van app-1]:443.'],
            'in a URL' => ['GET https://192.0.2.10:8443/health failed.', 'GET https://[adres van app-1]:8443/health failed.'],
            'a provider hostname in a URL' => ['GET https://vps-123.provider.example/health', 'GET https://[adres van app-1]/health'],
            'a provider hostname with a port' => ['ssh vps-123.provider.example:22', 'ssh [adres van app-1]:22'],
            'a provider hostname in a longer name' => ['shop.vps-123.provider.example is down', 'shop.[adres van app-1] is down'],
            'a provider hostname in capitals' => ['VPS-123.Provider.Example is down', '[adres van app-1] is down'],
            'after a colon' => ['ip:192.0.2.10 host:vps-123.provider.example', 'ip:[adres van app-1] host:[adres van app-1]'],
            'rsync' => ['rsync backup@10.0.0.5:/backups', 'rsync backup@[adres]:/backups'],
            'scp' => ['scp x deploy@vps-123.provider.example:/tmp', 'scp x deploy@[adres van app-1]:/tmp'],
            'before a dot and a word' => ['192.0.2.10.Next', '[adres van app-1].Next'],
            'after an underscore' => ['x_192.0.2.10', 'x_[adres van app-1]'],
            'IPv6 after a colon' => ['addr:2001:db8::10', 'addr:[adres van app-1]'],
            'IPv6 in brackets with a port' => ['[2001:DB8:0::10]:443', '[[adres van app-1]]:443'],
            'an unknown IPv6' => ['to 2001:db8::99 failed', 'to [adres] failed'],
            'an address in a cloud hostname' => ['ip-10-0-0-5.eu-west-1.compute.internal', 'ip-[adres].eu-west-1.compute.internal'],
            'a network\'s gateway, shared by two' => ['via 192.168.1.1', 'via [adres]'],
            'a subnet' => ['in 10.20.0.0/16', 'in [adres van Office]/16'],
            'a network address and its MAC' => ['nas at 10.20.0.9 (00-11-22-33-44-55)', 'nas at [adres van nas] ([adres van nas])'],
            'not times, versions, hashes or methods' => ['At 12:30:45, PHP 8.5.0 (a1b2c3d4) in Class::method.', 'At 12:30:45, PHP 8.5.0 (a1b2c3d4) in Class::method.'],
            'not the start of a longer address' => ['192.0.2.100 is another', '[adres] is another'],
            'a range' => ['range 192.0.2.10-192.0.2.20', 'range [adres van app-1]-[adres]'],
            'before a dash' => ['iface 192.0.2.10-eth0', 'iface [adres van app-1]-eth0'],
            'URL-encoded, as a site\'s Location header' => ['next=https%3A%2F%2F192.0.2.10%2F', 'next=https%3A%2F%2F[adres van app-1]%2F'],
            'glued to a letter' => ['x192.0.2.10 and 192.0.2.10x', 'x[adres van app-1] and [adres van app-1]x'],
            'more numbers in front' => ['1.192.0.2.10', '[adres van app-1]'],
            'with leading zeros' => ['192.000.002.010', '[adres van app-1]'],
            'IPv4 inside IPv6' => ['via ::ffff:192.0.2.10 and [64:ff9b::192.0.2.10]:80', 'via [adres] and [[adres]]:80'],
            'IPv6 glued to a letter' => ['x2001:db8::10', 'x[adres van app-1]'],
            'a cloud hostname with a dashed address' => ['ec2-192-0-2-10.compute', 'ec2-[adres van app-1].compute'],
            'a MAC after a colon' => ['mac:00:11:22:33:44:55: link down', 'mac:[adres van nas]: link down'],
            'a MAC as a DHCP client id' => ['client 01:00:11:22:33:44:55', 'client [adres van nas]'],
            'a MAC written the Cisco way' => ['nas 0011.2233.4455', 'nas [adres van nas]'],
            'a provider hostname glued to what comes before it' => ['%2Fvps-123.provider.example', '%2F[adres van app-1]'],
            'a provider hostname glued to what comes after it' => ['in /var/log/nginx/vps-123.provider.example-access.log', 'in /var/log/nginx/[adres van app-1]-access.log'],
            'a provider hostname and a dashed address glued after it' => ['vps-123.provider.example-192-0-2-10.log', '[adres van app-1]-[adres van app-1].log'],
            'a provider hostname between letters outside ASCII' => ['服务器vps-123.provider.example已重启, vps-123.provider.exampleé', '服务器[adres van app-1]已重启, [adres van app-1]é'],
            'not code with two colons' => ['Cache::add() failed in std::cafe and Foo::bar::baz', 'Cache::add() failed in std::cafe and Foo::bar::baz'],
            'a short IPv6 after a word and a colon' => ['addr:fd00::5 ip:fd00::5', 'addr:[adres van db-1] ip:[adres van db-1]'],
            'a known short IPv6 URL-encoded or glued to letters' => ['next=https%3A%2F%2F%5Bfd00::5%5D%2F ip%3Dfd00::5 srcfd00::5', 'next=https%3A%2F%2F%5B[adres van db-1]%5D%2F ip%3D[adres van db-1] src[adres van db-1]'],
            'a provider hostname glued on both sides' => ['%2Fvar%2Flog%2Fvps-123.provider.example-access.log', '%2Fvar%2Flog%2F[adres van app-1]-access.log'],
            'not a provider hostname that is a name, or numbers that are no address' => ['hz-1 ran out of disk on 6.8.0-45-generic, call 06-12-34-56-78 on 2026-11-09', 'hz-1 ran out of disk on 6.8.0-45-generic, call 06-12-34-56-78 on 2026-11-09'],
        ];
    }

    #[DataProvider('addressesInText')]
    public function test_every_way_an_address_can_appear_in_a_text_is_masked(string $text, string $masked): void
    {
        Server::factory()->create(['name' => 'app-1', 'public_ipv4' => '192.0.2.10', 'public_ipv6' => '2001:db8::10', 'provider_hostname' => 'vps-123.provider.example']);
        Server::factory()->create(['name' => 'hz-1', 'provider_hostname' => 'hz-1']);
        Server::factory()->create(['name' => 'db-1', 'public_ipv6' => 'fd00::5']);
        Network::factory()->create(['name' => 'Office', 'subnet' => '10.20.0.0/16', 'gateway' => '192.168.1.1']);
        Network::factory()->create(['name' => 'Home', 'gateway' => '192.168.1.1']);
        NetworkAddress::query()->create(['network_id' => Network::query()->firstWhere('name', 'Office')->id, 'name' => 'nas', 'address' => '10.20.0.9', 'mac_address' => '00:11:22:33:44:55']);
        $incident = Incident::factory()->create(['impact' => $text]);
        $incident->timelineEntries()->create(['occurred_at' => now(), 'note' => $text]);
        $incident->actions()->create(['description' => Str::limit($text, 250)]);

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame([$masked, $masked], [$found['impact'], $found['timeline'][0]['note']]);
        $this->assertSame($masked, $found['actions'][0]['description']);
    }

    public function test_a_provider_hostname_is_known_however_it_was_stored(): void
    {
        Server::factory()->create(['name' => 'trail', 'provider_hostname' => 'trail.host.example.']);
        Server::factory()->create(['name' => 'panel', 'provider_hostname' => 'https://panel.host.example/vm/77']);
        Server::factory()->create(['name' => 'idn', 'provider_hostname' => 'bücher.example']);
        Server::factory()->create(['name' => 'port', 'provider_hostname' => 'vps-1.host.example:2222']);
        Server::factory()->create(['name' => 'path', 'provider_hostname' => 'vps-2.host.example/vm/7']);
        Server::factory()->create(['name' => 'user', 'provider_hostname' => 'root@vps-3.host.example']);
        Server::factory()->create(['name' => 'notes', 'provider_hostname' => 'vps-7.host.example (rescue)']);
        Server::factory()->create(['name' => 'two', 'provider_hostname' => 'vps-8.host.example, vps-9.host.example']);
        Server::factory()->create(['name' => 'label', 'provider_hostname' => 'Hetzner: vps-10.host.example / panel']);
        Server::factory()->create(['name' => 'plan', 'provider_hostname' => 'CX22 v2.1']);
        Server::factory()->create(['name' => 'hindi', 'provider_hostname' => 'उदाहरण.परीक्षा']);
        Server::factory()->create(['name' => 'thai', 'provider_hostname' => 'ตัวอย่าง.example']);

        $this->assertSame(
            '[adres van trail], [adres van panel] and [adres van idn]; [adres van port], [adres van path], [adres van user]',
            app(HiddenValues::class)->mask('trail.host.example, panel.host.example and xn--bcher-kva.example; vps-1.host.example, vps-2.host.example, vps-3.host.example'),
        );
        // Free text: every hostname in it, but no version.
        $this->assertSame(
            '[adres van notes], [adres van two], [adres van two] and [adres van label] run v2.1',
            app(HiddenValues::class)->mask('vps-7.host.example, vps-8.host.example, vps-9.host.example and vps-10.host.example run v2.1'),
        );
        // An internationalised one also as written, in any case and script; the others still when letters are glued to them.
        $this->assertSame(
            '[adres van idn] and https://[adres van idn]/ are down, as are [adres van hindi] and [adres van thai]; 服务器[adres van port]已重启',
            app(HiddenValues::class)->mask('bücher.example and https://BÜCHER.example/ are down, as are उदाहरण.परीक्षा and ตัวอย่าง.example; 服务器vps-1.host.example已重启'),
        );
    }

    public function test_no_mask_is_masked_again(): void
    {
        // A name that is another server's provider hostname shows in the masks.
        Server::factory()->create(['name' => 'vps-1.host.example', 'public_ipv4' => '192.0.2.10']);
        Server::factory()->create(['name' => 'db-1', 'provider_hostname' => 'vps-1.host.example']);

        $this->assertSame('Ping [adres van vps-1.host.example]', app(HiddenValues::class)->mask('Ping 192.0.2.10'));
    }

    public function test_an_address_where_a_text_is_cut_is_masked_before_it_is_cut(): void
    {
        Server::factory()->create(['name' => 'app-1', 'public_ipv4' => '192.0.2.10']);
        $incident = Incident::factory()->create();
        $incident->timelineEntries()->create(['occurred_at' => now(), 'note' => str_repeat('x', Serializer::MAX_NOTE - 5).' 192.0.2.10']);

        $note = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id])['timeline'][0]['note'];

        $this->assertStringNotContainsString('192.0', $note);
    }

    public function test_masking_holds_up_for_a_large_inventory_and_odd_text(): void
    {
        $now = now()->toDateTimeString();
        DB::table('servers')->insert(array_map(fn (int $i): array => [
            'name' => "server-{$i}", 'public_ipv4' => '10.'.intdiv($i, 250).'.'.($i % 250).'.1', 'provider_hostname' => "vps-{$i}.provider.example",
            'status' => 'active', 'ssh_port' => 22, 'auto_renews' => true, 'renewal_period_months' => 12, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 1500)));
        $incident = Incident::factory()->create(['impact' => 'vps-1499.provider.example and 10.5.249.1 went down; ſ '.str_repeat('a.', 5000).'end']);

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertStringStartsWith('[adres van server-1499] and [adres van server-1499] went down; ſ a.a.', $found['impact']);

        // Labels full of dashes, or names on a tail 1,500 servers share, cost no more than a few lookups each.
        $started = microtime(true);
        app(HiddenValues::class)->mask(str_repeat('a.', 11).str_repeat('a-', 16000).'a');
        app(HiddenValues::class)->mask(str_repeat('x.provider.example ', 20000));
        $this->assertLessThan(2, microtime(true) - $started);
    }

    public function test_when_masking_fails_the_text_is_hidden_not_shown(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $masked = app(HiddenValues::class)->mask('Ping 192.0.2.10 failed: '.str_repeat('a1:', 50));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertSame('[tekst verborgen]', $masked);
    }

    public function test_texts_changed_names_who_last_changed_the_root_cause_and_the_report(): void
    {
        $incident = Incident::factory()->create(['root_cause' => 'Unknown.']);
        $this->actingAs(User::factory()->create(['name' => 'Bob']));
        $incident->update(['root_cause' => 'NFS stalled.']);

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame(['Bob', null], [$found['texts_changed']['root_cause']['by'], $found['texts_changed']['root_cause']['via']]);
        $this->assertNull($found['texts_changed']['report']);
    }

    public function test_get_incident_says_so_when_there_is_none(): void
    {
        $this->assertSame('Er is geen incident met id 999. list_incidents vindt ze.', $this->callToolError($this->token, 'get_incident', ['id' => 999]));
    }

    public function test_listing_many_takes_as_many_queries_as_listing_a_few(): void
    {
        $make = function (int $count): void {
            foreach (range(1, $count) as $index) {
                $incident = Incident::factory()->for(User::factory())->create();
                $incident->servers()->attach(Server::factory()->create());
                $incident->actions()->create(['description' => 'Follow up']);
            }
        };
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ids(['status' => 'any', 'limit' => 50]);

            return count(DB::getQueryLog());
        };

        $make(2);
        $this->ids();
        $few = $queries();
        $make(10);

        $this->assertSame($few, $queries());
    }

    public function test_every_token_may_read_incidents_and_the_tools_say_they_only_read(): void
    {
        foreach (AgentAccess::cases() as $access) {
            [, $token] = $this->agentToken(access: $access);
            $tools = collect($this->mcp($token, 'tools/list')->json('result.tools'))->keyBy('name');

            foreach (['list_incidents', 'get_incident'] as $name) {
                $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], array_intersect_key($tools[$name]['annotations'], ['readOnlyHint' => 0, 'openWorldHint' => 0]), "{$name}, {$access->value}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<int>
     */
    private function ids(array $arguments = []): array
    {
        return array_column($this->callToolSuccessfully($this->token, 'list_incidents', $arguments)['incidents'], 'id');
    }
}
