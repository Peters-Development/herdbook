# Commands

- [Introduction](#introduction)
- [Accounts](#accounts)
    - [`users:create`](#users-create)
    - [`users:reset-two-factor`](#users-reset-two-factor)
- [Status Screens](#status-screens)
    - [`screens:create`](#screens-create)
    - [`screens:list`](#screens-list)
    - [`screens:revoke`](#screens-revoke)
- [Alerts](#alerts)
    - [`alerts:send`](#alerts-send)
- [Incidents](#incidents)
    - [`incidents:import`](#incidents-import)
- [Demo Data](#demo-data)
    - [`demo:reset`](#demo-reset)
- [Scheduled Commands](#scheduled-commands)
    - [`checks:dispatch`](#checks-dispatch)
    - [`checks:notify`](#checks-notify)
    - [`checks:incidents`](#checks-incidents)
    - [`checks:aggregate`](#checks-aggregate)
    - [`model:prune`](#model-prune)
    - [`backups:check`](#backups-check)
    - [`heartbeat`](#heartbeat)
    - [`renewals:digest`](#renewals-digest)
- [Queue Workers](#queue-workers)

<a name="introduction"></a>
## Introduction

Herdbook adds a handful of Artisan commands. Most of them run by themselves on a schedule; the ones you run by hand manage accounts, status screens and imports, or let you test your alert setup.

Every command runs inside the `app` container:

```bash
# On the local stack
docker compose exec app php artisan <command>

# On the host
docker/compose.sh exec app php artisan <command>
```

`php artisan list` shows them all, and `php artisan help <command>` shows the arguments and options of one.

<a name="accounts"></a>
## Accounts

<a name="users-create"></a>
### `users:create`

```bash
php artisan users:create [--name=] [--email=]
```

Creates an account. Herdbook has no sign-up page, so every account starts here. The command asks for the name and email address unless you pass them, and always asks for the password (at least 12 characters) and its confirmation, so it never ends up in your shell history.

The new account sets up two-factor authentication at its first sign-in. Sign in soon: until then, the password alone is enough to set it up. Every account is an administrator.

<a name="users-reset-two-factor"></a>
### `users:reset-two-factor`

```bash
php artisan users:reset-two-factor {email}
```

The way back for an account that lost both its authenticator app and its recovery codes. It removes the account's two-factor setup, and signs it out everywhere: every session and every "remember me" cookie. The setup page asks for no password, so a lost phone that is still signed in must not be the device that sets up the new authenticator. At the next sign-in, Herdbook asks for a new setup.

<a name="status-screens"></a>
## Status Screens

See [Status Screen](status-screen.md) for what the screen shows and how to set one up.

<a name="screens-create"></a>
### `screens:create`

```bash
php artisan screens:create {name?}
```

Creates a link for a wall display, such as `screens:create "Hallway"`. Without a name, the command asks for one; names are unique. It prints the link, `APP_URL/screen#<token>`, once: Herdbook only stores a hash of the token.

<a name="screens-list"></a>
### `screens:list`

```bash
php artisan screens:list
```

Lists every screen with when it was created and when it last asked for data, in the display timezone. A screen that went quiet is switched off, or lost its network.

<a name="screens-revoke"></a>
### `screens:revoke`

```bash
php artisan screens:revoke {name}
```

Revokes the link of the screen with that name. The screen's next request is refused, and it says its access was revoked.

<a name="alerts"></a>
## Alerts

<a name="alerts-send"></a>
### `alerts:send`

```bash
php artisan alerts:send {title} {body?} [--problem] [--critical] [--via=]
```

Sends an alert through the same channels as the checks: the first channel that is set up and delivers ([Alerts](alerts.md#channels)). Use it to try out your setup:

```bash
php artisan alerts:send "Test"                          # the normal route
php artisan alerts:send "Test" --via=mail               # only this channel, with its error if it fails
php artisan alerts:send "Test" --problem --critical     # the emergency route
```

| Option | Effect |
|---|---|
| `--problem` | Bad news: a higher priority on ntfy. |
| `--critical` | With `--problem`: the critical route, starting with Pushover's emergency priority, which repeats until you acknowledge it. |
| `--via=` | Only through `pushover`, `ntfy` or `mail`, also when another channel comes first, and shows the error when it fails. |

Try `--via=mail` after every change to `MAIL_*`: otherwise you only find out about a mistake on the day ntfy lets you down. The host's backup scripts use this command to report a [failed backup](backups.md#when-a-backup-fails).

<a name="incidents"></a>
## Incidents

<a name="incidents-import"></a>
### `incidents:import`

```bash
php artisan incidents:import {directory} --source= [--project=] [--dry-run]
```

Imports incident reports written in markdown, from every `*.md` file directly in the directory (not in subdirectories).

| Argument or option | Meaning |
|---|---|
| `directory` | The directory with the reports. |
| `--source=` | Required: where the reports come from, such as the repository name `acme-platform`. It keeps reports with the same file name from different repositories apart. |
| `--project=` | The slug of a project to link every imported incident to. |
| `--dry-run` | Show what would be imported, and write nothing. |

Each report needs a date, at the start of its file name (`2026-07-27-database-full.md`) or in its first `# ` heading. The heading becomes the title (without an `Incident 2026-07-27 —` prefix; without a heading, the file name), the paragraph that starts with `**Impact:**` becomes the impact, and the whole file becomes the report. Imported incidents get severity SEV2 and status resolved: check them in the panel afterwards. Running the import twice imports nothing twice.

The container can only read its own files, so copy the reports into `storage/app` first. On the host, that is `DATA_DIR/app`:

```bash
sudo mkdir -p /srv/herdbook-data/app/import
sudo cp -r ~/acme-platform/docs/incidents /srv/herdbook-data/app/import/acme-platform
sudo chown -R 33:33 /srv/herdbook-data/app/import
docker/compose.sh exec app php artisan incidents:import storage/app/import/acme-platform --source=acme-platform --dry-run
```

[Incidents](incidents.md) covers the rest of the incident workflow.

<a name="demo-data"></a>
## Demo Data

<a name="demo-reset"></a>
### `demo:reset`

```bash
php artisan demo:reset [--force]
```

Empties the inventory, the checks and their history, the incidents, documents and the activity log, and fills them with fictional demo data. The accounts stay, so you don't have to set up two-factor authentication again. It asks for confirmation unless you pass `--force`, and refuses to run outside the `local` environment.

The demo checks are never scheduled, so nothing goes out to the network. That also means the picture freezes after a few minutes; run the command again for a fresh one.

<a name="scheduled-commands"></a>
## Scheduled Commands

The `scheduler` container runs `php artisan schedule:work`, which starts these commands on their schedule. You don't have to set up cron. Times without a timezone are in UTC; the others follow `HERDBOOK_DISPLAY_TIMEZONE`.

| Command | When |
|---|---|
| `checks:dispatch` | Every minute |
| `checks:notify` | Every minute |
| `checks:incidents` | Every minute |
| `checks:aggregate` | Hourly, at five past |
| `model:prune` | Daily at 04:15 (UTC) |
| `backups:check` | Daily at 09:00 (display timezone) |
| `heartbeat` | Every five minutes |
| `renewals:digest` | Weekdays at 08:00 (display timezone) |

Their output goes to the scheduler container's log:

```bash
docker/compose.sh logs -f scheduler
```

`php artisan schedule:list` shows when each one runs next. The check commands never overlap with themselves, and their lock expires (after 5 minutes, or 55 for `checks:aggregate`), so a run killed halfway (a container that stops) doesn't block the next ones.

You can run any of them by hand, too. That is mostly useful for `backups:check`, `heartbeat` and `renewals:digest`.

<a name="checks-dispatch"></a>
### `checks:dispatch`

Puts the checks that are due on the `checks` queue, where the [queue workers](#queue-workers) run them. HTTP checks run concurrently, so they go in batches of 20; the other types run one by one and go in pairs. Each check's next run is set before it is queued, so a slow queue never gets the same check twice.

<a name="checks-notify"></a>
### `checks:notify`

Sends the alerts for check events that nobody has heard about yet. When many checks change at once (5 or more, `group_alerts_from` in [Configuration](configuration.md#checks)), they become one message.

<a name="checks-incidents"></a>
### `checks:incidents`

Opens a draft incident when a critical production check has been down for 5 minutes (`incident_after_minutes`). When the check comes back up, its recovery is added to the incident's timeline. [Incidents](incidents.md) explains how they work.

<a name="checks-aggregate"></a>
### `checks:aggregate`

```bash
php artisan checks:aggregate [--hours=3]
```

Rolls the raw check results of the last full hours up into hourly statistics, and those into daily statistics. By default it recomputes the last three hours. When the scheduler or the database was down for longer, it goes back to the last hour it rolled up, so the hours you missed are filled in too. Running it again changes nothing.

<a name="model-prune"></a>
### `model:prune`

Laravel's own pruning command. It removes raw check results after 14 days and hourly statistics after 90 days (`keep_results_days` and `keep_hour_stats_days`). Daily statistics are kept for good.

<a name="backups-check"></a>
### `backups:check`

Alerts you when the last good backup is more than 26 hours old (`backup_max_age_hours`), judged by the marker file that the nightly [backup](backups.md#when-a-backup-fails) touches after every good run. Without a marker, it assumes backups aren't set up yet and stays quiet.

<a name="heartbeat"></a>
### `heartbeat`

Calls `HERDBOOK_HEARTBEAT_URL`, when set, so an outside monitor notices when Herdbook itself goes quiet. It withholds the heartbeat while there are active checks but no fresh results: a stuck queue or a broken deploy turns the monitor red too, not only a dead host. See [Watching the Watcher](alerts.md#watching-the-watcher).

<a name="renewals-digest"></a>
### `renewals:digest`

```bash
php artisan renewals:digest [--force]
```

Sends one message with the renewals that need attention, and what they cost, on the first channel that delivers (ntfy, then email, then Pushover). It is scheduled every weekday at 08:00 but sends at most once a week: on the first of those mornings on which something needs attention and the message gets through, usually Monday. Nothing is sent when nothing needs attention. `--force` sends it again in a week where it already went out. See [Renewals](renewals.md).

<a name="queue-workers"></a>
## Queue Workers

Two containers work the queue, and both keep running:

| Container | Command |
|---|---|
| `queue` | `php artisan queue:work --queue=checks,default --tries=3 --timeout=60` |
| `queue-checks` | `php artisan queue:work --queue=checks --tries=3 --timeout=60` |

The second worker only runs checks, so a check that waits on a host that doesn't answer never holds up the next minute's checks, nor other work. `docker/deploy.sh` restarts both after an update (`queue:restart`).
