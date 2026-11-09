<?php

namespace App\Mcp\Tools;

use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\ValidatesArguments;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Projects, servers and hostnames, found the way an agent knows them: by a
 * name, the git remote of the repository it works in, `hostname -f`, or the
 * address it connects to. Fields the tools never return (repository URL,
 * addresses, the provider's hostname) are compared whole, never with LIKE,
 * which could be made to spell them out character by character.
 */
#[Name('search_inventory')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class SearchInventory extends Tool
{
    use ValidatesArguments;

    private const int LIMIT = 25;

    private const array TYPES = ['projects', 'servers', 'hostnames'];

    public function description(): string
    {
        return 'Find projects, servers and hostnames in Herdbook, for instance to get the ids maintenance links to. '
            .'A project matches on any part of its name or slug, or on its whole git remote (any form: https, ssh or git@host:owner/repo; credentials are not needed); a project without a repository filled in also matches when its slug is the name of the repository, which may be a different repository of the same name. '
            .'A server matches on any part of its name, on its whole provider hostname or the first label of it, on the first label of a fully qualified hostname (the output of `hostname -f`), or on one of its IP addresses (whole). '
            .'A hostname matches on any part of its name. '
            .'Without a query it lists everything. Each kind is sorted by name and holds at most '.self::LIMIT.' results; "more" names the kinds that have more, so search more precisely. '
            .'A server shows when updates, a reboot, an inspection and an upgrade were last done on it. '
            .'Project status: active, maintenance (only kept running) or archived. Server status: active, maintenance or decommissioned.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(255)->description('A name, slug, git remote, hostname or IP address. Leave it out to list everything.'),
            'type' => $schema->string()->enum(self::TYPES)->description('Only this kind of result.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'query' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(self::TYPES)],
        ]);

        $query = trim($input['query'] ?? '');
        $results = [];
        $more = [];

        foreach (isset($input['type']) ? [$input['type']] : self::TYPES as $type) {
            $found = match ($type) {
                'projects' => $this->projects($query),
                'servers' => $this->servers($query),
                'hostnames' => $this->hostnames($query),
            };

            $results[$type] = $found->take(self::LIMIT)->map(match ($type) {
                'projects' => Serializer::project(...),
                'servers' => Serializer::server(...),
                'hostnames' => Serializer::hostname(...),
            })->values()->all();

            if ($found->count() > self::LIMIT) {
                $more[] = $type;
            }
        }

        return Response::structured([...$results, 'more' => $more]);
    }

    /**
     * "host/owner/repo" for any form of a git remote: https (with or without
     * credentials, a port or ".git"), ssh:// or git@host:owner/repo. Null: it
     * isn't one.
     */
    public static function remote(string $value): ?string
    {
        $value = trim($value);

        // github.com/acme/shop, without a scheme.
        if (! str_contains($value, '://') && preg_match('~^[\w-]+(\.[\w-]+)+/~', $value) === 1) {
            $value = "https://{$value}";
        }

        if (preg_match('~^[\w.-]+@([\w.-]+):(?!//)(.+)$~', $value, $scp) === 1) {
            [$host, $path] = [$scp[1], $scp[2]];
        } else {
            $parts = parse_url($value);

            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
                return null;
            }

            [$host, $path] = [$parts['host'], $parts['path']];
        }

        $path = preg_replace('~\.git$~i', '', trim($path, '/'));

        return $path === '' ? null : Str::lower("{$host}/{$path}");
    }

    /**
     * @return Collection<int, Project>
     */
    private function projects(string $query): Collection
    {
        $remote = self::remote($query);
        $withRemote = $remote === null ? [] : Project::query()
            ->whereNotNull('repository_url')
            ->get(['id', 'repository_url'])
            ->filter(fn (Project $project): bool => self::remote($project->repository_url) === $remote)
            ->modelKeys();

        return Project::query()
            ->with(Serializer::PROJECT_RELATIONS)
            ->when($query !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query, $remote, $withRemote): void {
                $this->orWhereContains($builder, 'projects.name', $query);
                $this->orWhereContains($builder, 'projects.slug', $query);
                $builder->orWhereIn('projects.id', $withRemote);

                // A project whose repository isn't filled in: by its slug.
                if ($remote !== null) {
                    $builder->orWhere(fn (Builder $builder) => $builder->whereNull('projects.repository_url')->where('projects.slug', Str::afterLast($remote, '/')));
                }
            }))
            ->orderBy('name')
            ->limit(self::LIMIT + 1)
            ->get();
    }

    /**
     * @return Collection<int, Server>
     */
    private function servers(string $query): Collection
    {
        $isAddress = filter_var($query, FILTER_VALIDATE_IP) !== false;
        $isHostname = ! $isAddress && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+\.?$/i', $query) === 1;

        return Server::query()
            ->with(Serializer::SERVER_RELATIONS)
            ->withLastDoneOfEachTask()
            ->when($query !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query, $isAddress, $isHostname): void {
                $this->orWhereContains($builder, 'servers.name', $query);
                $builder->orWhere('servers.provider_hostname', $query);

                // Or its first label: srv1234 for srv1234.provider.example. One
                // whole label only, and not a number: provider hostnames often
                // hold the address, which a longer prefix would spell out.
                if (! str_contains($query, '.') && ! ctype_digit($query)) {
                    $builder->orWhereRaw("servers.provider_hostname like ? escape '!'", [$this->escapeLike($query).'.%']);
                }

                if ($isAddress) {
                    $builder->orWhereIn('servers.id', $this->serversWithAddress($query));
                } elseif ($isHostname) {
                    // `hostname -f`: app-1.example.com is server app-1.
                    $builder->orWhere('servers.name', Str::before($query, '.'));
                }
            }))
            ->orderBy('name')
            ->limit(self::LIMIT + 1)
            ->get();
    }

    /**
     * The servers with this address in any of its forms (2001:DB8:0::7 is
     * 2001:db8::7): compared in PHP, as the form addresses are stored in
     * is whatever was typed.
     *
     * @return list<int>
     */
    private function serversWithAddress(string $address): array
    {
        $packed = inet_pton($address);

        return Server::query()
            ->get(['id', ...Server::ADDRESSES])
            ->filter(fn (Server $server): bool => collect(Server::ADDRESSES)->contains(
                fn (string $column): bool => filter_var($server->{$column}, FILTER_VALIDATE_IP) !== false && inet_pton($server->{$column}) === $packed,
            ))
            ->modelKeys();
    }

    /**
     * @return Collection<int, Hostname>
     */
    private function hostnames(string $query): Collection
    {
        return Hostname::query()
            ->with(Serializer::HOSTNAME_RELATIONS)
            ->when($query !== '', fn (Builder $builder) => $builder->where(fn (Builder $builder) => $this->orWhereContains($builder, 'hostnames.fqdn', $query)))
            ->orderBy('fqdn')
            ->limit(self::LIMIT + 1)
            ->get();
    }

    private function orWhereContains(Builder $builder, string $column, string $term): void
    {
        $builder->orWhereRaw("{$column} like ? escape '!'", ['%'.$this->escapeLike($term).'%']);
    }

    /**
     * % and _ taken literally. "!" is the escape character: a backslash means
     * something else in MySQL strings than in SQLite.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
