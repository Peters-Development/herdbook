# Configuration

- [Introduction](#introduction)
- [Applying Changes](#applying-changes)
- [Environment Settings](#environment-settings)
    - [The Application](#the-application)
    - [Language and Region](#language-and-region)
    - [Alerts](#alerts)
    - [Email](#email)
    - [The Vault](#the-vault)
    - [The Heartbeat](#the-heartbeat)
    - [Database, Sessions, Queue and Cache](#database-sessions-queue-and-cache)
    - [Logging](#logging)
- [The Herdbook Config File](#the-herdbook-config-file)
    - [Languages](#languages)
    - [Renewals](#renewals)
    - [Checks](#checks)
    - [Backups](#backups)

<a name="introduction"></a>
## Introduction

Herdbook is a Laravel application, so its settings live in two places:

- **`.env`**, for everything that differs between installations: addresses, passwords, tokens and your preferences. This page lists the settings that matter for Herdbook, with their defaults (the value used when the setting is missing). Start from `.env.example`; [Deployment](deployment.md#app-env) lists what to change for production.
- **`config/herdbook.php`**, for the values Herdbook was tuned with: renewal lead times, check timeouts, retention. They are not read from the environment.

The host-only settings in `docker/box.env` (profiles, the MySQL root password, Tailscale keys, the LAN address) are described in [Deployment](deployment.md#box-env).

<a name="applying-changes"></a>
## Applying Changes

On the host, the app containers cache their configuration when they start, from the environment they were created with. After changing `.env`, recreate them:

```bash
docker/compose.sh up -d --force-recreate app queue queue-checks scheduler
```

A plain restart is not enough. On the local stack, web requests pick up a change straight away; restart the workers with `docker compose restart queue queue-checks scheduler`.

`config/herdbook.php` is part of the code, and so part of the image. To change one of its values, change it in your own fork and deploy that.

<a name="environment-settings"></a>
## Environment Settings

<a name="the-application"></a>
### The Application

| Setting | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | `local` for the local stack, `production` on the host. `demo:reset` only runs in `local`. |
| `APP_DEBUG` | `false` | Never `true` on the host: error pages would show code and settings. |
| `APP_URL` | `http://localhost` | The address you open Herdbook on. Links in alerts, and the link `screens:create` prints, use it, so set it to the address people actually use. |
| `APP_KEY` | none | Encrypts the two-factor secrets, the recovery codes and the sessions. Keep a copy offline ([Keeping `APP_KEY` Safe](deployment.md#keeping-app-key-safe)). |

<a name="language-and-region"></a>
### Language and Region

| Setting | Default | Purpose |
|---|---|---|
| `APP_LOCALE` | `en` | The default language: `en` (English) or `nl` (Dutch). |
| `APP_FALLBACK_LOCALE` | `en` | The language for any text that has no translation in the chosen one. |
| `HERDBOOK_DISPLAY_TIMEZONE` | `UTC` | The timezone Herdbook shows times in, such as `Europe/Amsterdam`. Calendar days (renewals) are counted in it too. |
| `HERDBOOK_CURRENCY_SYMBOL` | `€` | The symbol in front of every amount. |
| `APP_FAKER_LOCALE` | `en_US` | Only used when generating test data. |

**Language.** Every account can choose its own language in its profile; an account that hasn't chosen one gets the default. The default is also the language of everything that isn't tied to a signed-in account: the sign-in page, alerts, the weekly renewal digest and the [status screen](status-screen.md). [Translations](translations.md) explains how to add a language.

**Time.** Herdbook stores every timestamp in UTC and shows it in `HERDBOOK_DISPLAY_TIMEZONE`, so the repeated hour at the end of daylight saving time is never ambiguous. Dates that count in whole days, such as renewal and expiry dates, are plain dates, and "today" for them is taken in this timezone too. So are the times of the morning [backup check](commands.md#backups-check) and the [renewal digest](commands.md#renewals-digest). Use a timezone name from the tz database, such as `Europe/London` or `America/New_York`.

**Currency.** Herdbook doesn't convert: all amounts are in one currency, entered excluding VAT. The setting only changes the symbol. Where the symbol goes and which separators are used follow the language: `€1,234.56` in English, `€ 1.234,56` in Dutch. Those formats live in `lang/<locale>/formats.php`.

<a name="alerts"></a>
### Alerts

Every channel is optional, and one is enough. An alert goes out through the first channel that is set up and delivers; [Alerts](alerts.md#channels) explains the order and what each channel is good at.

| Setting | Default | Purpose |
|---|---|---|
| `PUSHOVER_APP_TOKEN` | empty | The application token of a Pushover application (pushover.net → Create an Application). Each user adds their own user key in their profile. |
| `NTFY_URL` | empty | An ntfy topic URL, such as `https://ntfy.sh/<long-random-topic>`, or a topic on your own ntfy server. |
| `NTFY_TOKEN` | empty | An access token, for a reserved or protected topic. |

> [!WARNING]
> On ntfy.sh, a topic is public: whoever knows its name can read along. Alerts carry the names of checks and domains and expiry dates. Use a long, random topic name, or better, a reserved topic with `NTFY_TOKEN`, or your own ntfy server.

If you run your own ntfy server and use the iOS app, the server needs `upstream-base-url` set for instant notifications.

<a name="email"></a>
### Email

Email uses Laravel's standard `MAIL_*` settings. Herdbook sends alerts by email to every account that turned email alerts on in its profile.

| Setting | Notes |
|---|---|
| `MAIL_MAILER` | `smtp` to send. `log` means Herdbook doesn't send email at all: a mailer that only writes to the log (or `array`, or a failover that ends in the log) doesn't count as a channel, so an alert is never "sent" to nowhere. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Your SMTP server. Put a password with a `$` in single quotes. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | The sender. Most servers refuse `herdbook@localhost`; use an address your server accepts, usually the mailbox you sign in with. |
| `MAIL_EHLO_DOMAIN` | The name Herdbook greets the server with. By default the host from `APP_URL`, which is a `.ts.net` name or an IP address; set a real domain name if your server refuses it. |

Use an SMTP server that doesn't run on the servers Herdbook watches: otherwise the alert disappears exactly when that server is down. Test with `alerts:send "Test" --via=mail` ([Commands](commands.md#alerts-send)).

<a name="the-vault"></a>
### The Vault

| Setting | Default | Purpose |
|---|---|---|
| `HERDBOOK_VAULT_URL` | empty | The address of your web vault, such as `https://vault.<tailnet>.ts.net`. Credential references link to their item there. Empty: no links. See [Vaultwarden](vaultwarden.md#linking-credentials). |

<a name="the-heartbeat"></a>
### The Heartbeat

| Setting | Default | Purpose |
|---|---|---|
| `HERDBOOK_HEARTBEAT_URL` | empty | Called every five minutes, as long as the checks keep running. An outside monitor (healthchecks.io, or a monitor of your own) then tells you when Herdbook goes quiet. Empty: off. |

[Watching the Watcher](alerts.md#watching-the-watcher) explains why you want one. If the monitor lives on your tailnet, see [Services on Your Tailnet](networking.md#services-on-your-tailnet).

<a name="database-sessions-queue-and-cache"></a>
### Database, Sessions, Queue and Cache

Keep these as in `.env.example`. Herdbook needs no Redis: the queue, the cache and the sessions all live in MySQL.

| Setting | In `.env.example` | Notes |
|---|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT` | `mysql`, `mysql`, `3306` | The `mysql` container. |
| `DB_DATABASE`, `DB_USERNAME` | `herdbook`, `herdbook` | Created by MySQL on its first start. |
| `DB_PASSWORD` | `secret` | On the host: `openssl rand -hex 24`, before the first start. |
| `DB_ROOT_PASSWORD` | `secret` | Local stack only. On the host it lives in `docker/box.env`. |
| `SESSION_DRIVER` | `database` | |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before you have to sign in again. |
| `SESSION_ENCRYPT` | `true` | |
| `SESSION_SECURE_COOKIE` | not set | `true` on the host: the session cookie only travels over HTTPS. |
| `QUEUE_CONNECTION` | `database` | |
| `CACHE_STORE` | `database` | The cache must survive a restart: it remembers, for example, that this week's renewal digest went out. |
| `HOST_UID` | `501` | Local stack only: your user id (`id -u`), for the development image. |

<a name="logging"></a>
### Logging

| Setting | Recommended on the host | Notes |
|---|---|---|
| `LOG_CHANNEL` | `stderr` | The logs then end up in `docker/compose.sh logs`. The containers don't keep `storage/logs`. |
| `LOG_LEVEL` | `warning` | |

<a name="the-herdbook-config-file"></a>
## The Herdbook Config File

`config/herdbook.php` holds the settings above that come from the environment, and these values, which don't.

<a name="languages"></a>
### Languages

| Key | Value |
|---|---|
| `locales` | The languages an account can choose, each with its name in that language: `en` (English) and `nl` (Nederlands). A new language goes here, next to its translation files ([Translations](translations.md)). |

<a name="renewals"></a>
### Renewals

| Key | Default | Meaning |
|---|---|---|
| `renewal.default_lead_days` | `60` | How many days before the expiry date a provider renews automatically, unless the item or its provider says otherwise. |
| `renewal.confirmation_grace_days` | `14` | How long after that moment an automatic renewal may stay unconfirmed before Herdbook asks you to check it. Never more than half the lead time. |

[Renewals](renewals.md) explains how these play out.

<a name="checks"></a>
### Checks

| Key | Default | Meaning |
|---|---|---|
| `checks.reference_urls` | `https://one.one.one.one/cdn-cgi/trace`, `https://www.google.com/generate_204` | When a check fails, Herdbook first tries these. If none answers, the host's own internet connection is down: nothing is recorded and nobody is alerted, instead of every check going red at once ([The Connectivity Guard](monitoring.md#the-connectivity-guard)). |
| `checks.timeout_seconds` | `10` | How long an HTTP check may take, of which at most 5 seconds to connect. The other check types get at most 5 seconds to connect or answer; DNS lookups have their own 2-second limit per record type. |
| `checks.incident_after_minutes` | `5` | A critical production check that is down this long opens a draft incident. |
| `checks.group_alerts_from` | `5` | When this many checks change in one run, their alerts become one message. |
| `checks.keep_results_days` | `14` | How long raw check results are kept. |
| `checks.keep_hour_stats_days` | `90` | How long hourly statistics are kept. Daily statistics are kept for good. |
| `checks.user_agent` | `herdbook-monitor` | The User-Agent of HTTP checks. |

[Monitoring](monitoring.md) explains the checks themselves.

<a name="backups"></a>
### Backups

| Key | Default | Meaning |
|---|---|---|
| `backup_marker` | `storage/app/backup-last-success` | The file the nightly backup touches after every good run. On the host that is `DATA_DIR/app/backup-last-success`. |
| `backup_max_age_hours` | `26` | `backups:check` alerts you when the marker is older than this. Without a marker, backups count as not set up yet, and nothing is reported. |

[Backups](backups.md#when-a-backup-fails) describes the whole chain.
