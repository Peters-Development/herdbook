# Development

- [Introduction](#introduction)
- [Local Development](#local-development)
    - [The Local Stack](#the-local-stack)
    - [Running Commands](#running-commands)
    - [Demo Data](#demo-data)
    - [Styles and Scripts](#styles-and-scripts)
- [Tests](#tests)
- [Code Style](#code-style)
- [Where Things Live](#where-things-live)
- [Single Sources of Truth](#single-sources-of-truth)
- [Rules of the Codebase](#rules-of-the-codebase)
    - [No Secrets](#no-secrets)
    - [Time](#time)
    - [Money](#money)
    - [Morph Map Aliases](#morph-map-aliases)
    - [Markdown](#markdown)
    - [Long Strings](#long-strings)
    - [Migrations](#migrations)
    - [Scheduled Tasks](#scheduled-tasks)
    - [Filament 5](#filament-5)
    - [Nothing From Third Parties](#nothing-from-third-parties)
    - [Look and Feel](#look-and-feel)
- [Adding a Renewable Model](#adding-a-renewable-model)
- [The Data Model](#the-data-model)
- [Code Conventions](#code-conventions)

<a name="introduction"></a>
## Introduction

Herdbook is a Laravel 13 application with a single Filament 5 panel (Livewire 4), running on PHP 8.5 and MySQL 8.4. Queue and cache use the database driver, so there is no Redis. There is no JavaScript build step: one stylesheet and one small ES module.

This page is for people who want to change Herdbook: how to run it locally, how the tests work, where things live, and the handful of rules that keep the codebase consistent. To contribute, read [CONTRIBUTING.md](../CONTRIBUTING.md) as well.

<a name="local-development"></a>
## Local Development

Follow [Installation](installation.md) to get a local copy running with demo data.

<a name="the-local-stack"></a>
### The Local Stack

`docker-compose.yml` runs everything you need, with the code mounted into the containers:

| Service | What it does | On your machine |
|---|---|---|
| `app` | PHP-FPM; where you run every command | |
| `web` | nginx | http://localhost:16000 |
| `mysql` | MySQL 8.4 | `127.0.0.1:16336` |
| `queue` | `queue:work --queue=checks,default` | |
| `queue-checks` | `queue:work --queue=checks`: checks only | |
| `scheduler` | `schedule:work`: runs `routes/console.php` | |
| `mailpit` | catches all email | http://localhost:16025 |

Ports bind to `127.0.0.1` only. Set `HOST_UID` in `.env` to the output of `id -u`, so files the containers create belong to you.

<a name="running-commands"></a>
### Running Commands

**Every PHP, Artisan, Composer, test and Pint command runs inside the `app` container.** The PHP on your machine may be a different version, or missing altogether.

```bash
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app composer install
docker compose exec app php artisan test --compact
docker compose exec app ./vendor/bin/pint --dirty
```

The app containers in production cache their configuration on start, but the development containers do not: a change to `.env` is picked up by the next request. The queue workers and the scheduler are long-running processes, though: restart them after changing code they run (`docker compose restart queue queue-checks scheduler`).

<a name="demo-data"></a>
### Demo Data

```bash
docker compose exec app php artisan demo:reset
```

`demo:reset` empties the inventory and fills it with fictional projects, servers, domains, a network, incidents, maintenance, subscriptions, a day of check results and a month of hourly statistics, so the dashboard, the map, the status pages and the costs page have something to show. Accounts are kept. It only runs in the `local` environment, and the demo data is always English.

The demo checks are never scheduled, so nothing goes out to the network. After a few minutes the dashboard says that measurements have stopped: that is expected. Run `demo:reset` again for fresh history.

<a name="styles-and-scripts"></a>
### Styles and Scripts

Styling is plain CSS in `resources/css/herdbook.css`; the map's script is a plain ES module in `resources/js/herdbook-map.js`, loaded as an Alpine component on the map page only. Both are registered as Filament assets and copied to `public/` by `filament:assets`, which `composer install` runs. After changing either, run:

```bash
docker compose exec app php artisan filament:assets
```

<a name="tests"></a>
## Tests

```bash
docker compose exec app php artisan test --compact
docker compose exec app php artisan test --compact --filter=ProjectHealthTest
```

The tests are PHPUnit feature tests in `tests/Feature/`, run on **SQLite in memory**. Three things are worth knowing:

- **They run in Dutch.** `phpunit.xml` sets `APP_LOCALE=nl`, so assertions read the Dutch interface. That is deliberate: a string without a Dutch translation then fails a test instead of showing up in English in the middle of a Dutch page. `TranslationsTest` checks that every string in the code has a Dutch translation, that every translation is used, and that placeholders and plural forms match. See [Translations](translations.md#writing-translatable-code).
- **No test touches the network.** HTTP goes through Laravel's `Http` facade, faked with `Http::fake()`. Everything else the checks do on the network (TCP, TLS, DNS, ping, the connectivity guard) goes through the `NetworkProbe` interface; tests bind `Tests\Fakes\FakeProbe` instead (`$this->app->instance(NetworkProbe::class, new FakeProbe)`), on which you can close ports, set certificate expiry dates, DNS answers and unreachable hosts, or take the whole line offline.
- **SQLite is not MySQL.** In particular, SQLite does not enforce string lengths, so a value that MySQL would refuse passes the tests. See [Long Strings](#long-strings).

Every change comes with a test: write a new feature test or extend an existing one, and run the suite before you push. For tests of the panel, `Tests\Concerns\SignsIn` signs in an account that has two-factor authentication set up.

<a name="code-style"></a>
## Code Style

[Laravel Pint](https://laravel.com/docs/pint) with the default Laravel preset. Run it after every batch of PHP changes:

```bash
docker compose exec app ./vendor/bin/pint --dirty
```

<a name="where-things-live"></a>
## Where Things Live

| Path | Contents |
|---|---|
| `app/Models` | Eloquent models. `Concerns/` holds the shared behavior: `HasRenewal`, `HasCost`, `Linkable` and `LinksToInventory` (the "related to" links), `RecordsActivity` (the activity log). `Contracts/Renewable` is the interface of everything that renews. |
| `app/Enums` | Backed enums (mostly strings) with a label (`HasLabel`) and often a color (`HasColor`). |
| `app/Services/Checks` | The monitoring engine: `CheckRunner`, `CheckStateMachine`, `ConnectivityGuard`, `DefaultChecks`, `DnsExpectation`, `NetworkProbe` and its real implementation `SocketProbe`, `CheckHistory`, `CheckIncidents`, `MonitoringPulse`, `Uptime`. |
| `app/Services/Alerts` | `AlertChannels`, the Pushover, ntfy and mail channels, `CheckAlerts` (alerts for check events), `AlertMessage`. |
| `app/Services/Health` | `ProjectHealth`, `ProjectIncidents`, `ProjectTimeline` (the status page). |
| `app/Services/Costs` | `RecurringCosts`, `CostOverview`, `Money`. |
| `app/Services/…` | `Impact/ImpactAnalyzer`, `Map/InfrastructureMap`, `Screen/StatusScreenPayload`, `IncidentImport/`, `RenewalAttention`, `BackupFreshness`. |
| `app/Jobs/RunChecks.php` | Runs a batch of checks on the `checks` queue. |
| `app/Console/Commands` | Every Artisan command (see [Commands](commands.md)). |
| `app/Filament/Resources` | One resource per model, each with `Schemas/` (form, infolist), `Tables/`, `Pages/` and `RelationManagers/`. |
| `app/Filament/Pages` | The map, the costs page and the profile page. |
| `app/Filament/Widgets` | The dashboard. |
| `app/Filament/Support` | Schemas and actions shared between resources: `RenewalSchema`, `CostSchema`, `LinkSchema`, `MaintenanceSchema`, `CheckActions`, `ImpactSchema`, `DeleteConsequences`, `ActivityPresenter`. |
| `app/Filament/RelationManagers` | Relation managers shared between resources (incidents, documents, credentials, maintenance, checks, activity). |
| `app/Http` | `SecurityHeaders`, `SetUserLocale`, and the status screen's controller and token middleware. |
| `app/Rules` | `NoSecretMaterial`, `DistinctItems`, `Subnet`. |
| `routes/` | `web.php`, `screen.php` (the status screen and its API, outside the `web` group: no session), `console.php` (the schedule). |
| `config/herdbook.php` | Herdbook's own settings. |
| `lang/` | `nl.json`, and the formats and Laravel messages per language. |
| `resources/` | The stylesheet, the map script, and Blade views for the widgets, pages, mail and status screen. |
| `docker/` | The Dockerfile, the production compose file and the deploy, backup and network scripts. |
| `database/seeders/DemoSeeder.php` | The demo data. |

<a name="single-sources-of-truth"></a>
## Single Sources of Truth

A few questions are answered in exactly one place. Everything that needs the answer asks that place; never derive it again elsewhere, or the dashboard and the map will one day disagree.

| Question | Answered by |
|---|---|
| How is a project, environment, server or hostname doing? | `Services\Health\ProjectHealth` (with `HealthState`): tiles, projects list, status page, map, status screen. |
| Which incidents belong to a project? | `Services\Health\ProjectIncidents`: linked to the project, to a server its production needs, or to such a server's network. |
| What does something cost, per year, per month, per group? | `Services\Costs\RecurringCosts`: the costs page, the cost section on project and client pages, and any other total. |
| Which renewals need attention? | `Services\RenewalAttention`: dashboard, filters, digest. |
| What goes down when a server (or network) does? | `Services\Impact\ImpactAnalyzer`: the server and network pages, the map, health, draft incidents. |
| How does an alert get out? | `Services\Alerts\AlertChannels`: the first channel that is set up and delivers. Never call a channel directly. |
| How do checks reach the network? | `Services\Checks\NetworkProbe` (TCP, TLS, DNS, ping, reachability) and the `Http` facade. Nothing else opens connections, so tests can fake all of it. |
| Do the checks still run? Is the backup recent? | `MonitoringPulse` and `BackupFreshness`: the dashboard, the heartbeat, the status screen and `backups:check` use the same rules. |

`ProjectHealth`, `ProjectIncidents`, `RecurringCosts` and `RenewalAttention` load what they need once and are bound as **scoped** singletons in `AppServiceProvider`: one instance per request or queue job, so a dashboard asks the database once, not once per tile.

<a name="rules-of-the-codebase"></a>
## Rules of the Codebase

<a name="no-secrets"></a>
### No Secrets

Never add a field that stores a password, private key or token. A credential is a reference: a label, a username, a host and the id of the item in the password manager. Encrypting secrets on the server would only protect against a leaked database dump, not against a compromised machine. Free-text fields use the `NoSecretMaterial` rule, which refuses private keys. URLs with credentials in them are refused as check targets for the same reason.

<a name="time"></a>
### Time

- Timestamps are stored in **UTC** and shown in `config('herdbook.display_timezone')`, which Filament is told about in `AppServiceProvider`. A local zone would make the repeated hour at the end of daylight saving time ambiguous.
- Dates that count in **calendar days** (expiry dates, due dates) are `date` columns, not `datetime`, and "today" for them is taken in the display timezone, not in UTC.
- Dates are `CarbonImmutable` (`Date::use()` in `AppServiceProvider`), so a shared instance can never be shifted by accident.
- Format dates with the formats of the language (`__('formats.date')` and friends), never with a hard-coded pattern.

<a name="money"></a>
### Money

Amounts are `decimal` columns with a decimal cast, calculated with `BcMath\Number` and formatted with `Services\Costs\Money` (which follows the language and `config('herdbook.currency_symbol')`). Never use floats for money. Amounts are excluding VAT.

<a name="morph-map-aliases"></a>
### Morph Map Aliases

Polymorphic relations (the "related to" links, the activity log) store a short alias, never a class name: `Relation::enforceMorphMap()` in `AppServiceProvider`. Every model that takes part in a morph relation, including every model with an activity log, gets an alias there. A renamed class then does not orphan its rows, and because the map is enforced, using a model without an alias throws, so a missing one shows up in the tests.

<a name="markdown"></a>
### Markdown

Markdown written by users (notes, documents, incident reports, maintenance plans) is never rendered raw. In Filament, use `TextEntry::markdown()`, which sanitizes the HTML. Anywhere else, use:

```php
Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
```

<a name="long-strings"></a>
### Long Strings

Strings that can be long, such as check errors, generated names or imported titles, are cut with `Str::limit()` before they are stored. MySQL refuses a value longer than its column, and the SQLite tests do not notice.

<a name="migrations"></a>
### Migrations

Migrations are append-only once they are released: change the schema with a new migration, never by editing an existing one. An edited migration never reaches a database that already ran it.

<a name="scheduled-tasks"></a>
### Scheduled Tasks

Scheduled tasks are defined in `routes/console.php`, in the `$events` array, so their output is appended to the container log (the scheduler sends it to `/dev/null` otherwise). The schedule:

| Command | When |
|---|---|
| `checks:dispatch` | every minute: queue the checks that are due |
| `checks:notify` | every minute: send alerts for new check events |
| `checks:incidents` | every minute: open draft incidents for long outages |
| `checks:aggregate` | hourly at :05: roll results up into hourly and daily statistics |
| `model:prune` | daily at 04:15: remove old results and hourly statistics |
| `backups:check` | daily at 09:00 (display timezone): warn about an old backup |
| `heartbeat` | every five minutes: ping the heartbeat URL |
| `renewals:digest` | weekdays at 08:00 (display timezone), sent once a week |

Overlap locks expire well before the next run is due, so a run that was killed half-way does not block the next ones.

<a name="filament-5"></a>
### Filament 5

Use the Filament 5 namespaces: `Filament\Schemas\…` for layouts and the `Get`/`Set` utilities, `Filament\Actions\…` for actions. Code from Filament 3 or 4 examples needs adjusting. Models are strict outside production (`Model::shouldBeStrict()`): lazy loading throws, so eager-load what a table or page needs.

User-facing error messages are friendly and translatable: never a table name, SQL or a stack trace.

<a name="nothing-from-third-parties"></a>
### Nothing From Third Parties

Herdbook loads nothing from other servers. Fonts (IBM Plex) are self-hosted in `public/fonts/`, avatars are generated locally by `InitialsAvatarProvider`, and the Content-Security-Policy in `App\Http\Middleware\SecurityHeaders` only allows scripts, styles, fonts and requests from Herdbook's own origin. A new outside source would mean changing that policy; the answer is almost always to host it yourself.

<a name="look-and-feel"></a>
### Look and Feel

- **Yellow is the brand, not a status.** Filament's `primary` color is yellow and `warning` is orange, so the two never read as the same thing. A status enum never returns `primary` as its color.
- **Technical values in a monospace font**: IP addresses, hostnames, SSH commands, check targets and hosts get `->fontFamily(FontFamily::Mono)` in tables and infolists, and `->extraInputAttributes(['class' => 'fi-font-mono'])` on inputs.

<a name="adding-a-renewable-model"></a>
## Adding a Renewable Model

Domains, servers, subscriptions and networks share the renewal and cost behavior. To add another kind of thing that renews and costs money:

1. Give its table the renewal columns (`expires_on`, `auto_renews`, `renewal_period_months`, `renewal_lead_days`, `renewal_stopped_at`) and the cost columns (`cost_amount`, `cost_period`).
2. Use `HasRenewal` and `HasCost` on the model and implement `Contracts\Renewable`, including `renewalProviderRelation()`, `hasContract()`, `costProjects()`, `costClients()` and `costRelations()`.
3. Add it to `Renewable::MODELS`, and to `RenewalSchema::resource()`, `icon()` and `mono()`.
4. Give it a morph map alias.
5. Use `RenewalSchema` and `CostSchema` in its form, infolist and table.

The dashboard, the renewal calendar, the digest and the costs page then pick it up by themselves.

<a name="the-data-model"></a>
## The Data Model

The project is the center; see [Inventory](inventory.md#the-model-at-a-glance) for the picture. The tables:

| Area | Tables |
|---|---|
| Accounts | `users` (with two-factor columns, `locale`, `pushover_user_key`, `receives_critical_alerts`, `receives_alert_mail`), `sessions` |
| Inventory | `clients`, `providers` (`kinds` as JSON), `projects`, `environments`, `servers`, `environment_server` (with `role`), `server_dependencies` (`server_id` needs `depends_on_id`, with a `kind`), `networks`, `network_addresses`, `domains`, `hostnames`, `subscriptions` |
| Monitoring | `checks` (on a hostname or a server; `settings` as JSON), `check_results` (raw), `check_stats` (`hour` and `day`), `check_events` (switches between up and down, with notification and incident bookkeeping) |
| Knowledge | `incidents`, `incident_timeline_entries`, `incident_actions`, `documents`, `document_revisions`, `credential_references`, `maintenances` |
| Links | `incidentables`, `documentables`, `credentialables`, `maintainables`: one pivot per kind, polymorphic on the inventory side |
| Other | `status_screens` (only a SHA-256 hash of each token), `activity_log` (spatie/laravel-activitylog), and Laravel's own tables for the cache, the queue and failed jobs |

Renewable tables (`domains`, `servers`, `subscriptions`, `networks`) share the renewal columns (`expires_on` as a `date`, `auto_renews`, `renewal_period_months`, `renewal_lead_days`, `renewal_stopped_at`) and the cost columns (`cost_amount` as `decimal(10,2)`, `cost_period`). Derived values, such as a renewal status, the health of a project or the duration of an incident, are computed, never stored.

<a name="code-conventions"></a>
## Code Conventions

- **English** for identifiers, file names, comments, PHPDoc, test method names, log messages, and the descriptions and output of Artisan commands.
- **Translatable** for everything a user reads: Filament labels, notifications, validation messages, enum labels, emails, alerts and the status screen. Write the English text in `__('…')` and add the Dutch to `lang/nl.json` in the same change. See [Translations](translations.md#writing-translatable-code).
- Comments explain *why*, not what the next line does.
- One subject per pull request, with a test. See [CONTRIBUTING.md](../CONTRIBUTING.md).
