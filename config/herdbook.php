<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC (config/app.php): a local zone makes the
    | repeated hour at the end of daylight saving time ambiguous. Filament
    | shows them in this zone, and "today" for calendar-day logic (renewal
    | dates) is taken in this zone too.
    |
    */

    'display_timezone' => env('HERDBOOK_DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    |
    | The languages the interface speaks, each with its name in that language.
    | Every account picks one in its profile; without a choice it gets the
    | default (APP_LOCALE). Strings are English in the code and translated in
    | lang/<locale>.json (docs/translations.md).
    |
    */

    'locales' => [
        'en' => 'English',
        'nl' => 'Nederlands',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The symbol in front of every amount (costs of domains, servers,
    | subscriptions and networks). Herdbook does not convert: all amounts
    | are in this one currency. Where the symbol goes and the separators
    | follow the language (lang/<locale>/formats.php).
    |
    */

    'currency_symbol' => env('HERDBOOK_CURRENCY_SYMBOL', '€'),

    /*
    |--------------------------------------------------------------------------
    | Renewals (domains, servers, subscriptions, networks)
    |--------------------------------------------------------------------------
    |
    | default_lead_days: how many days before the expiry date a provider renews
    | automatically, unless the item or its provider says otherwise.
    | confirmation_grace_days: how long after that moment a renewal may stay
    | unconfirmed before it asks for a check (capped at half the lead time).
    |
    */

    'renewal' => [
        'default_lead_days' => 60,
        'confirmation_grace_days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vaultwarden
    |--------------------------------------------------------------------------
    |
    | Base URL of the web vault, e.g. https://vault.<tailnet>.ts.net. Credential
    | references link to their item there. Empty: no links.
    |
    */

    'vault_url' => env('HERDBOOK_VAULT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    |
    | reference_urls: when a check fails, these are tried first; when none
    | answers, the host's own line is down and nothing is recorded or sent.
    | incident_after_minutes: a critical production check that is down this
    | long opens a draft incident. group_alerts_from: this many events in one
    | run become one combined notification.
    |
    */

    'checks' => [
        'reference_urls' => ['https://one.one.one.one/cdn-cgi/trace', 'https://www.google.com/generate_204'],
        'timeout_seconds' => 10,
        'incident_after_minutes' => 5,
        'group_alerts_from' => 5,
        'keep_results_days' => 14,
        'keep_hour_stats_days' => 90,
        'user_agent' => 'herdbook-monitor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Pushover (critical: wakes you up; per user, key in the profile), ntfy
    | (normal: a topic URL, e.g. https://ntfy.sh/<topic> or self-hosted) and
    | email (SMTP, to accounts that turned it on). Messages stay bare: name,
    | state, time.
    |
    */

    'alerts' => [
        'pushover_token' => env('PUSHOVER_APP_TOKEN'),
        'ntfy_url' => env('NTFY_URL'),
        'ntfy_token' => env('NTFY_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watching the watcher
    |--------------------------------------------------------------------------
    |
    | heartbeat_url: pinged every five minutes when set (a local monitor, or
    | an external dead man's switch): silence there means this host is down.
    | backup_marker: written by docker/backup/backup.sh after a good night
    | (storage/app is the bind-mounted data folder); `backups:check` warns
    | when it is older than backup_max_age_hours. Absent: not set up yet.
    |
    */

    'heartbeat_url' => env('HERDBOOK_HEARTBEAT_URL'),

    'backup_marker' => storage_path('app/backup-last-success'),

    'backup_max_age_hours' => 26,

];
