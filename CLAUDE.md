# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this app is

**Herdbook** is an open-source (AGPL-3.0-or-later), self-hosted overview of everything a team or freelancer runs: projects, their environments, the servers they run on, networks, domains and hostnames, incident reports, runbooks, references to credentials, maintenance, uptime checks with notifications, and what it all costs (subscriptions included). The UI speaks English and Dutch (see "Language boundary"). Laravel 13 + Filament 5 (one panel, at `/`), MySQL 8.4, PHP 8.5.

- **One organization, no tenancy.** One database, one panel. Every account is an administrator; there is no registration (`php artisan users:create`). Two-factor authentication is required.
- **The project is the center.** Project → Environment (production, staging, …) → Server (with a role). Everything else links to it.
- **Not a CRM.** A client is a name, an external link (to whatever invoicing or CRM tool the user has) and an outage contact.
- **Runs on the user's own machine ("the host")** on a private network: over Tailscale (`tailscale serve` → `https://herdbook.<tailnet>.ts.net`, no published port), on the local network (Caddy, HTTPS with its own CA), or both: `COMPOSE_PROFILES` in `docker/box.env` (`tailscale`, `lan`, `vaultwarden`).
- **Public repository.** No company, client or personal data in code, comments, tests, demo data or docs: examples are neutral (`acme`, `example.com`, `app-1`).

Documentation: `docs/` (index: `docs/README.md`). Architecture and conventions for contributors: `docs/development.md`. Deployment, networking and backups: `docs/deployment.md`, `docs/networking.md`, `docs/backups.md`.

---

## Docker is the runtime

Run every PHP / Artisan / Composer / test / Pint command inside the `app` container. The PHP on your own machine is a different version.

```bash
docker compose up -d
docker compose exec app php artisan <anything>
docker compose exec app composer <anything>
docker compose exec app php artisan test --compact [--filter=Name]
docker compose exec app ./vendor/bin/pint --dirty
```

Local: http://localhost:16000 (nginx), MySQL on 127.0.0.1:16336, Mailpit on http://localhost:16025. The 16000 range keeps clear of other local stacks. Tests use SQLite in-memory, in Dutch (`APP_LOCALE=nl` in `phpunit.xml`).

Production (the host): `docker/docker-compose.prod.yml`, images from `docker/Dockerfile` (one file, targets `dev`/`app`/`web`). Always through `docker/compose.sh` (loads `.env` for the app and `docker/box.env` for the host's own settings and secrets); `docker/up.sh` brings the stack up, `docker/deploy.sh` deploys.
- The app containers cache config/routes/views on every start (`docker/php/entrypoint.sh`). A changed `.env` needs a recreate, not a restart.
- Production containers have fixed names, the same as in dev (`herdbook-app`, `herdbook-mysql`, …): a local test of the prod compose file needs other container names and other volume names (`herdbook_mysql-data` is shared too), or it hits the dev stack.
- Profiles: `tailscale` (`web` + `ts-herdbook`), `lan` (`web-lan` + `caddy`), `vaultwarden` (`vaultwarden` + `ts-vault`). `docker/compose.sh` exports `COMPOSE_PROFILES` from `box.env` and checks the settings each profile needs; never put `${VAR:?}` on a profile's service (compose checks it even when the profile is off).
- `web` and `vaultwarden` share the network namespace of their Tailscale sidecar; `docker/up.sh` recreates them after every `up`, and removes the containers of a profile that was switched off.

Monitoring: the scheduler (`routes/console.php`) runs `checks:dispatch` every minute; `RunChecks` jobs run on the `checks` queue. Every alert goes out through `AlertChannels` (Pushover, ntfy, email: the first one that is set up and delivers), never through one channel directly; `alerts:send` does the same from the command line. Network calls go through `NetworkProbe` (tests bind `Tests\Fakes\FakeProbe`) and the `Http` facade (tests use `Http::fake()`): no test touches the network.

Overview: how a project, environment, server or hostname is doing comes from `ProjectHealth` (scoped; `HealthState`), for the dashboard tiles, the map (`Pages\Map`) and the status screen alike: never derive it elsewhere. The status screen (`routes/screen.php`, `/screen`) is outside the `web` group: no session, a Bearer token from `status_screens` (only its hash is stored), and a payload built field by field (`StatusScreenPayload`: no hostnames, addresses, check names or incident titles, visitors can read along). Local demo data: `php artisan demo:reset` (local only, keeps the accounts).

Costs: domains, servers, subscriptions and networks (their internet line) are `Renewable` (`HasRenewal` + `HasCost`; amounts excluding VAT). What they cost per year, per month and per provider, project or client comes from `RecurringCosts` (scoped), for the cost page (`Pages\Costs`), the cost section on project and client pages (`CostSchema::ownerSection()`) and anything else that shows a total: never derive it elsewhere. Money is `BcMath\Number` and formatted with `Money` (separators and symbol follow the language and `config('herdbook.currency_symbol')`), never a float. A new renewable model implements `Renewable` (incl. `costProjects()`/`costClients()`/`costRelations()`) and goes into `Renewable::MODELS` and `RenewalSchema::resource()`/`icon()`/`mono()`.

---

## Hot rules

1. **No secrets in the database.** A credential is a reference: label, username, host, and the id of the Vaultwarden item. Never add a field that stores a password, private key or token. Server-side encryption would only protect against a leaked dump, not against a compromised host.
2. **Time:** timestamps are stored in UTC (`config/app.php`); Filament shows them in `config('herdbook.display_timezone')` (`HERDBOOK_DISPLAY_TIMEZONE`, default UTC), in the formats of the language (`__('formats.date')`, never a hard-coded `d-m-Y`). Dates that count in calendar days are `date` columns, not `datetime` (renewal and expiry dates), and "today" for them is taken in the display timezone, not UTC. Dates are `CarbonImmutable` (`Date::use()` in `AppServiceProvider`).
3. **Morph types are aliases.** `Relation::enforceMorphMap()` in `AppServiceProvider`: every model in a polymorphic relation (incl. the activity log) gets a short alias there.
4. **Money**: `decimal` columns with a decimal cast; no float arithmetic.
5. **Filament 5 namespaces**: `Filament\Schemas\…` for layouts and `Get`/`Set`, `Filament\Actions\…` for actions. Never the Filament 3/4 namespaces.
6. **Markdown from users is never rendered raw.** In Filament use `TextEntry::markdown()` (Filament sanitizes the HTML); anywhere else `Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false])`.
7. **Error messages to users** are friendly and translatable; never table names, SQL or stack traces.
8. **Strings that can be long are cut before they are stored** (`Str::limit()`): MySQL refuses values longer than the column, and the SQLite tests do not catch it.
9. **Every change is tested.** Write or update a PHPUnit feature test and run it. Run Pint after every batch of PHP edits.
10. **Migrations are append-only once they are released.** Change the schema with a new migration; an edited migration never reaches a database that already ran it.
11. **Scheduled tasks go into `$events` in `routes/console.php`**, so their output reaches the container log (the scheduler sends it to /dev/null otherwise). The current ones: `checks:dispatch`, `checks:notify`, `checks:incidents`, `checks:aggregate`, `model:prune`, `backups:check`, `heartbeat`, `renewals:digest`.

---

## Look

The brand is the ear tag ("oormerk"): a yellow tag on ink, IBM Plex.
- **Yellow is the brand, not a status.** `primary` is `Color::Yellow`; `warning` is `Color::Orange` so the two never read as the same thing. A status enum (`getColor()`) never returns `primary`.
- **Styling is plain CSS, no Vite build:** `resources/css/herdbook.css`, registered with `->assets()` in `AdminPanelProvider` and published to `public/css/app/` by `filament:assets` (which `composer install` runs; in dev, run `php artisan filament:assets` after a change). The same goes for the map's script, `resources/js/herdbook-map.js` (a plain ES module, loaded as an Alpine component). The panel starts in the dark theme (`defaultThemeMode()` in `AdminPanelProvider`); the light theme is light throughout, the topbar and sidebar included.
- **Nothing from third parties:** fonts are self-hosted in `public/fonts/ibm-plex/`, avatars come from `InitialsAvatarProvider` (Filament's default asks ui-avatars.com). The Content-Security-Policy (`App\Http\Middleware\SecurityHeaders`) enforces it: scripts, styles, fonts and requests only from the app's own origin. A new outside source means changing that policy, and the answer is almost always: host it yourself.
- **Technical values in mono:** IP addresses, hostnames, SSH commands, check targets and hosts get `->fontFamily(FontFamily::Mono)` in tables and infolists, and `->extraInputAttributes(['class' => 'fi-font-mono'])` on their inputs.
- Logo: `resources/views/filament/brand.blade.php`. Favicons, app icons and the manifest: `resources/views/filament/head.blade.php`. The PNGs and `favicon.ico` are rendered from `public/favicon.svg` (e.g. `rsvg-convert -w 192 -h 192 public/favicon.svg -o public/icon-192.png`); the apple-touch and maskable icons put the same tag on a full-bleed square.

---

## Language boundary

**English**: identifiers, file names, comments and PHPDoc, test method names, log messages, artisan descriptions and command output, docs, commit messages, pull requests and issues, and the source text of everything a user reads.

**Translated**: everything a user reads (Filament labels, notifications, validation messages, enum labels, mails, alerts, the status screen) is an English string in `__('…')` / `trans_choice()`, with its Dutch in `lang/nl.json` in the same change.
- The languages are `config('herdbook.locales')`; `APP_LOCALE` is the default (sign-in page, alerts, digests, status screen), each account picks its own in its profile (`SetUserLocale`). Date and money formats per language: `lang/<locale>/formats.php`.
- Filament's static label properties cannot call `__()`: use the methods (`getModelLabel()`, `getTitle()`, …). Menu groups: `App\Enums\NavigationGroup`.
- `TranslationsTest` fails on a string without a Dutch translation, an unused translation, or mismatched placeholders. The suite runs in Dutch, so its assertions read the Dutch UI. How to add a language: `docs/translations.md`.

---

## Executing multi-phase plans

When a change is planned in phases:
- After every phase: at least **2 review rounds** over that phase's changes (fresh subagents or the `code-review` skill), and fix what they find before the next phase.
- After the final phase: **2 more broad rounds** over the whole implementation.

Each round weighs correctness, fit with the existing patterns, maintainability, Laravel/Filament best practice, DRY, whether it does what the user needs, security, and performance.

---

## Commit conventions

- **No `Co-Authored-By` trailer.**
- **Sign off every commit** (`git commit -s`): the DCO app checks every pull request for a `Signed-off-by` line (see CONTRIBUTING.md).
- **One subject per commit.** Split unrelated changes.
- Format: short imperative subject, optionally `type(scope): subject`, in English.
- **Don't auto-commit.** Only commit when asked.
