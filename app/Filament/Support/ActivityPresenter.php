<?php

namespace App\Filament\Support;

use App\Enums\MaintenanceTask;
use App\Enums\ProviderKind;
use App\Models\Client;
use App\Models\Contracts\Renewable;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\Costs\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * How an activity log entry reads in the panel: in the user's language,
 * dates in its format, timestamps in the display timezone, enums by their label.
 */
final class ActivityPresenter
{
    /**
     * Foreign keys resolve to how what they point at reads (an attribute or
     * accessor; "full_name" says which project an environment belongs to).
     *
     * @var array<string, array{class-string<Model>, string}>
     */
    private const array REFERENCES = [
        'client_id' => [Client::class, 'name'],
        'provider_id' => [Provider::class, 'name'],
        'network_id' => [Network::class, 'name'],
        'registrar_id' => [Provider::class, 'name'],
        'dns_provider_id' => [Provider::class, 'name'],
        'domain_id' => [Domain::class, 'name'],
        'environment_id' => [Environment::class, 'full_name'],
        'hostname_id' => [Hostname::class, 'fqdn'],
        'server_id' => [Server::class, 'name'],
        'depends_on_id' => [Server::class, 'name'],
        'project_id' => [Project::class, 'name'],
        'user_id' => [User::class, 'name'],
    ];

    /**
     * Array attributes whose items are enum values.
     *
     * @var array<string, class-string<BackedEnum>>
     */
    private const array ENUM_LISTS = [
        'kinds' => ProviderKind::class,
        'tasks' => MaintenanceTask::class,
    ];

    /**
     * "Changed", or "Changed · Environment Production" for an entry about a
     * part of the page's record ($ownerType: the morph alias of that record).
     */
    public static function description(Activity $activity, ?string $ownerType = null): string
    {
        $description = match ($activity->event) {
            'created' => __('Created'),
            'updated' => __('Changed'),
            'deleted' => __('Deleted'),
            default => self::storedDescription($activity),
        };

        if ($ownerType === null || $activity->subject_type === $ownerType) {
            return $description;
        }

        $type = self::subjectTypes()[$activity->subject_type] ?? Str::ucfirst(str_replace('_', ' ', (string) $activity->subject_type));
        $label = $activity->properties?->get('label');

        return trim("{$description} · {$type} {$label}");
    }

    /**
     * "Field: old → new", one change per line, for updates and renewals.
     */
    public static function changes(Activity $activity): ?string
    {
        $changes = $activity->attribute_changes;

        if ($changes === null || ! in_array($activity->event, ['updated', 'renewed'], true)) {
            return null;
        }

        $casts = self::castsOf($activity);
        $old = $changes->get('old', []);

        $lines = collect($changes->get('attributes', []))->map(
            fn (mixed $value, string $attribute): string => self::label($attribute).': '
                .self::value($attribute, $old[$attribute] ?? null, $casts[$attribute] ?? null)
                .' → '.self::value($attribute, $value, $casts[$attribute] ?? null),
        );

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    public static function label(string $attribute): string
    {
        // Built once per language: a page shows many entries.
        static $labels = [];

        return ($labels[app()->getLocale()] ??= self::labels())[$attribute] ?? Str::ucfirst(str_replace('_', ' ', $attribute));
    }

    /**
     * A renewal's description, in the language of whoever reads it. Any other
     * description is shown as it was stored.
     */
    private static function storedDescription(Activity $activity): string
    {
        $date = $activity->properties?->get('date');

        // Translated with the new expiry date from its "date" property (Y-m-d).
        if ($activity->description === Renewable::RENEWED && is_string($date)) {
            return __(Renewable::RENEWED, ['date' => self::parse($date)?->translatedFormat(__('formats.date')) ?? $date]);
        }

        return (string) $activity->description;
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'name' => __('Name'),
            'slug' => __('Slug'),
            'type' => __('Type'),
            'kind' => __('Type'),
            'kinds' => __('Supplies'),
            'status' => __('Status'),
            'title' => __('Title'),
            'label' => __('Name'),
            'description' => __('Description'),
            'notes' => __('Notes'),
            'tags' => __('Stack'),
            'on_status_screen' => __('On the status screen'),
            'unmonitored' => __('Does not need monitoring'),
            'client_id' => __('Client'),
            'provider_id' => __('Provider'),
            'network_id' => __('Network'),
            'registrar_id' => __('Registrar'),
            'dns_provider_id' => __('DNS host'),
            'domain_id' => __('Domain'),
            'environment_id' => __('Environment'),
            'server_id' => __('Server'),
            'depends_on_id' => __('Depends on'),
            'project_id' => __('Project'),
            'role' => __('Role'),
            'repository_url' => __('Repository'),
            'external_url' => __('External link'),
            'contact_name' => __('Contact person'),
            'contact_phone' => __('Phone'),
            'contact_email' => __('Email'),
            'archived_at' => __('Archived'),
            'website_url' => __('Website'),
            'panel_url' => __('Control panel'),
            'support_email' => __('Support email'),
            'support_phone' => __('Support phone'),
            'deploy_method' => __('Deployed via'),
            'branch' => __('Branch'),
            'path' => __('Path'),
            'sort_order' => __('Order'),
            'provider_hostname' => __('Name at the provider'),
            'plan' => __('Plan'),
            'location' => __('Location'),
            'public_ipv4' => __('Public IPv4'),
            'public_ipv6' => __('Public IPv6'),
            'private_ip' => __('Private IP'),
            'tailscale_ip' => __('Tailscale IP'),
            'subnet' => __('Subnet'),
            'gateway' => __('Gateway'),
            'public_ip' => __('Static IP address'),
            'address' => __('IP address'),
            'mac_address' => __('MAC address'),
            'vcpu' => 'vCPU',
            'ram_gb' => 'RAM (GB)',
            'disk_gb' => __('Storage (GB)'),
            'os_name' => __('Operating system'),
            'os_version' => __('OS version'),
            'ssh_user' => __('SSH user'),
            'ssh_port' => __('SSH port'),
            'backup_policy' => __('Backups'),
            'cost_amount' => __('Amount'),
            'cost_period' => __('Billing period'),
            'expires_on' => __('Expiry date'),
            'auto_renews' => __('Renews automatically'),
            'renewal_period_months' => __('Term (months)'),
            'renewal_lead_days' => __('Renewal lead time (days)'),
            'renewal_stopped_at' => __('Cancelled'),
            'fqdn' => __('Hostname'),
            'purpose' => __('Purpose'),
            'redirects_to' => __('Redirects to'),
            'behind_proxy' => __('Behind a proxy'),
            'local_only' => __('Only on the local network'),
            'expected_ips' => __('Expected IP addresses'),
            'username' => __('Username'),
            'host' => __('Host'),
            'port' => __('Port'),
            'vault_item_id' => __('Vaultwarden item'),
            'severity' => __('Severity'),
            'occurred_on' => __('Date'),
            'started_at' => __('Started'),
            'detected_at' => __('Detected'),
            'resolved_at' => __('Resolved'),
            'impact' => __('Impact'),
            'source' => __('Source'),
            'imported_from' => __('Imported from'),
            'hostname_id' => __('Hostname'),
            'target' => __('Target'),
            'settings' => __('Settings'),
            'interval_seconds' => __('How often (seconds)'),
            'failure_threshold' => __('Down after'),
            'success_threshold' => __('Up again after'),
            'alert_level' => __('Alert'),
            'enabled' => __('Active'),
            'pushover_user_key' => __('Pushover user key'),
            'receives_critical_alerts' => __('Critical alerts'),
            'tasks' => __('Work'),
            'starts_at' => __('Start'),
            'ends_at' => __('End'),
            'user_id' => __('By'),
        ];
    }

    /**
     * What subject types are called when they show up on another page.
     *
     * @return array<string, string>
     */
    private static function subjectTypes(): array
    {
        return [
            'environment' => __('Environment'),
            'environment_server' => __('Server link'),
            'server_dependency' => __('Dependency'),
            'network_address' => __('Address'),
            'hostname' => __('Hostname'),
            'check' => __('Check'),
        ];
    }

    /**
     * The casts of the subject's model, so values are shown as the model sees them.
     *
     * @return array<string, string>
     */
    private static function castsOf(Activity $activity): array
    {
        $class = Relation::getMorphedModel((string) $activity->subject_type);

        return $class !== null && is_subclass_of($class, Model::class) ? (new $class)->getCasts() : [];
    }

    private static function value(string $attribute, mixed $value, ?string $cast): string
    {
        if (isset(self::REFERENCES[$attribute]) && $value !== null && $value !== '') {
            [$model, $attribute] = self::REFERENCES[$attribute];

            return $model::query()->find($value)?->{$attribute} ?? __('#:id (deleted)', ['id' => $value]);
        }

        if ($attribute === 'cost_amount' && is_numeric($value)) {
            return Money::format((string) $value);
        }

        if (isset(self::ENUM_LISTS[$attribute]) && is_array($value)) {
            $enum = self::ENUM_LISTS[$attribute];
            $value = array_map(fn (mixed $item): mixed => $enum::tryFrom($item)?->getLabel() ?? $item, $value);
        }

        return self::format($value, $cast);
    }

    private static function format(mixed $value, ?string $cast): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => self::format($item, null), $value));
        }

        if (is_bool($value)) {
            return $value ? __('yes') : __('no');
        }

        if ($cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            $case = $cast::tryFrom($value);

            return $case !== null && method_exists($case, 'getLabel') ? $case->getLabel() : (string) $value;
        }

        if (in_array($cast, ['date', 'immutable_date'], true)) {
            return self::parse($value)?->translatedFormat(__('formats.date')) ?? (string) $value;
        }

        if (in_array($cast, ['datetime', 'immutable_datetime'], true)) {
            return self::parse($value)?->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.datetime')) ?? (string) $value;
        }

        return Str::limit((string) $value, 80);
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
