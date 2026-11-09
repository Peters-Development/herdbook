<?php

namespace Tests\Feature\Inventory;

use App\Rules\NoNewLinks;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoNewLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://herdbook.example.com']);
    }

    /**
     * Every way the panel's markdown makes a link to elsewhere.
     *
     * @return array<string, array{string}>
     */
    public static function linksElsewhere(): array
    {
        return [
            'a link' => ['Sign in again [here](https://elsewhere.example/login?d=db-1).'],
            'an autolink' => ['See <https://elsewhere.example>.'],
            'a bare address' => ['See https://elsewhere.example/x.'],
            'www' => ['See www.elsewhere.example/x?d=1.'],
            'mailto under other words' => ['[Mail us](mailto:ops@elsewhere.example)'],
            'mailto with a body' => ['[ops@elsewhere.example?body=secret](mailto:ops@elsewhere.example?body=secret)'],
            'mailto to two' => ['[ops@herdbook.example.com,x@elsewhere.example](mailto:ops@herdbook.example.com,x@elsewhere.example)'],
            'an empty mailto' => ['[](mailto:)'],
            'a reference' => ["[here][1]\n\n[1]: https://elsewhere.example/x"],
            'protocol-relative' => ['[here](//elsewhere.example/x)'],
            'javascript' => ['[here](javascript:alert(1))'],
            'Herdbook over plain http elsewhere' => ['[here](http://elsewhere.example/maintenances/1)'],
            'Herdbook\'s host on another port' => ['[here](https://herdbook.example.com:8443/x)'],
            'Herdbook\'s host over plain http' => ['[here](http://herdbook.example.com/x)'],
        ];
    }

    #[DataProvider('linksElsewhere')]
    public function test_links_to_other_sites_are_refused(string $value): void
    {
        $validator = Validator::make(['plan' => $value], ['plan' => [new NoNewLinks]]);

        $this->assertTrue($validator->fails());
        $this->assertSame('Geen links naar andere sites: zet adressen tussen backticks.', $validator->errors()->first('plan'));
    }

    /** @return array<string, array{string}> */
    public static function markdown(): array
    {
        return [
            'an address in a code span' => ['Download `https://elsewhere.example/x.tar.gz` first.'],
            'an address in a code block' => ["```\ncurl https://elsewhere.example/install.sh\n```"],
            'a relative link' => ['See [the plan](/maintenances/5).'],
            'a link into Herdbook' => ['See [the plan](https://herdbook.example.com/maintenances/5).'],
            'a link into Herdbook by an address in capitals' => ['See [the plan](https://HERDBOOK.example.com/maintenances/5).'],
            'no link at all' => ['Run apt upgrade, then reboot.'],
            'an email address as itself' => ['Mail ops@elsewhere.example when done.'],
            'ssh to a user at a host' => ['Run ssh deploy@app-1.example.com and check the logs.'],
            'Herdbook\'s own port written out' => ['See [the plan](https://herdbook.example.com:443/maintenances/5).'],
        ];
    }

    #[DataProvider('markdown')]
    public function test_links_into_herdbook_and_addresses_between_backticks_pass(string $value): void
    {
        $this->assertFalse(Validator::make(['plan' => $value], ['plan' => [new NoNewLinks]])->fails());
    }

    public function test_the_links_of_the_text_it_replaces_may_stay_but_no_new_ones(): void
    {
        $replaced = "1. Read [the changelog](https://elsewhere.example/changelog).\n2. Upgrade.";

        $this->assertFalse(Validator::make(['plan' => $replaced."\n3. Reboot."], ['plan' => [new NoNewLinks($replaced)]])->fails());
        $this->assertTrue(Validator::make(['plan' => $replaced."\n3. See [this](https://elsewhere.example/other)."], ['plan' => [new NoNewLinks($replaced)]])->fails());
    }

    public function test_a_host_a_request_names_is_not_herdbook(): void
    {
        $this->app['request']->headers->set('HOST', 'elsewhere.example');
        $this->app['request']->headers->set('X-Forwarded-Host', 'elsewhere.example');

        $this->assertTrue(Validator::make(['plan' => '[plan](https://elsewhere.example/steal?d=1)'], ['plan' => [new NoNewLinks]])->fails());
    }
}
