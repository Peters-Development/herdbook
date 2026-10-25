# Installation

- [Introduction](#introduction)
- [Requirements](#requirements)
    - [For Trying It Out](#requirements-local)
    - [For Running It for Real](#requirements-production)
- [Trying It Locally](#trying-it-locally)
    - [Starting the Stack](#starting-the-stack)
    - [The First Account](#the-first-account)
    - [Demo Data](#demo-data)
    - [What Runs Where](#what-runs-where)
    - [Language](#language)
- [Stopping and Starting Over](#stopping-and-starting-over)
- [Next Steps](#next-steps)

<a name="introduction"></a>
## Introduction

Herdbook runs entirely in Docker. You don't need PHP, Composer or MySQL on your own machine: every command runs inside the containers, so the PHP version on your machine doesn't matter.

There are two ways to run it. The **local stack** (`docker-compose.yml`) is for trying Herdbook out and for development. It mounts your checkout into the containers and publishes its ports on `127.0.0.1` only. The **production stack** (`docker/docker-compose.prod.yml`) builds the code into images and is meant for the machine that will keep Herdbook running. This page covers the local stack. When you're ready for the real thing, continue with [Deployment](deployment.md).

<a name="requirements"></a>
## Requirements

<a name="requirements-local"></a>
### For Trying It Out

- Docker with the compose plugin (Docker Desktop, or Docker Engine on Linux).
- Git.
- Free ports `16000`, `16025` and `16336` on `127.0.0.1`.

<a name="requirements-production"></a>
### For Running It for Real

- A Linux host with systemd and Docker Engine with the compose plugin. The scripts expect the checkout at `/srv/herdbook`.
- `git`, `sqlite3` and `jq` on the host, and [restic](https://restic.net) 0.17 or newer for the nightly backup.
- A way in: a [Tailscale](https://tailscale.com) account with MagicDNS and HTTPS certificates turned on, a fixed address on your local network, or both. See [Networking](networking.md).
- Optional: a [Pushover](https://pushover.net) application, an [ntfy](https://ntfy.sh) topic and an SMTP server, for alerts. One of them is enough.

<a name="trying-it-locally"></a>
## Trying It Locally

<a name="starting-the-stack"></a>
### Starting the Stack

Clone the repository and create your `.env`:

```bash
git clone https://github.com/Peters-Development/herdbook.git
cd herdbook
cp .env.example .env
```

Open `.env` and set `HOST_UID` to the output of `id -u`. The development image creates a user with that id, so files the containers write into your checkout (such as `vendor/`) belong to you.

Then build and start the stack, install the dependencies, generate the application key and create the database tables:

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

`composer install` also publishes Filament's assets and Herdbook's own stylesheet and map script into `public/`. There is no JavaScript build step.

> [!NOTE]
> Until `composer install` and `migrate` have run, the queue workers and the scheduler restart over and over: they need the dependencies and the tables. That is expected, and stops by itself.

<a name="the-first-account"></a>
### The First Account

Herdbook has no sign-up page. Every account is created on the command line:

```bash
docker compose exec app php artisan users:create
```

The command asks for a name, an email address and a password of at least 12 characters. You can pass the first two as `--name=` and `--email=`.

Now open http://localhost:16000 and sign in. Two-factor authentication is required, so Herdbook asks you to set it up straight away: scan the QR code with an authenticator app (any TOTP app works) and enter the code it shows. You then get a set of recovery codes. Keep them somewhere safe, such as your password manager: they are your way back in if you lose your phone.

Herdbook starts in the dark theme. To switch to the light theme, or to follow your system, use the theme buttons in the user menu (top right); the choice is remembered per browser. The language is in your profile (see [Translations](translations.md#choosing-a-language)).

> [!NOTE]
> Every account is an administrator. There are no roles: anyone with an account can see and change everything. See [Security](security.md#accounts-and-sign-in).

<a name="demo-data"></a>
### Demo Data

An empty Herdbook doesn't show much. To look around first, fill it with fictional projects, servers, domains, incidents, a day of check results and a month of hourly statistics:

```bash
docker compose exec app php artisan demo:reset
```

The command asks for confirmation (pass `--force` to skip it), empties everything except the accounts and seeds the demo data. All names end in `.test`, and the demo checks are never scheduled, so nothing goes out to the network.

Because those checks never run, the picture freezes after a few minutes and the dashboard warns that no fresh results come in. Run `demo:reset` again whenever you want a fresh picture; you won't have to set up two-factor authentication again. The command refuses to run outside the `local` environment.

> [!WARNING]
> Checks you create yourself on the local stack do run: the scheduler and the queue workers are part of it. They request their targets from your machine and send alerts through whatever channels you configure in `.env`.

<a name="what-runs-where"></a>
### What Runs Where

The local stack runs these containers:

| Service | What it does |
|---|---|
| `app` | PHP-FPM with your checkout mounted at `/var/www`. Run every `php artisan` and `composer` command here. |
| `web` | nginx on http://localhost:16000. |
| `mysql` | MySQL 8.4, reachable from your machine on `127.0.0.1:16336` (database, user and password are in `.env`). |
| `queue` | A queue worker for the `checks` and `default` queues. |
| `queue-checks` | A second worker for the `checks` queue only, so slow checks never hold up other work. |
| `scheduler` | `php artisan schedule:work`: runs the checks, alerts and digests on their schedule ([Commands](commands.md#scheduled-commands)). |
| `mailpit` | Catches every email. Read them at http://localhost:16025. |

All ports bind to `127.0.0.1`, so nothing is reachable from your network.

To try email alerts locally, turn on email alerts in your profile and send a test through that channel only:

```bash
docker compose exec app php artisan alerts:send "Test" --via=mail
```

The message shows up in Mailpit. [Alerts](alerts.md#channels) explains the channels.

<a name="language"></a>
### Language

Herdbook speaks English and Dutch. `APP_LOCALE` in `.env` sets the default language (`en` or `nl`; English when it is not set), which the sign-in page, alerts, digests and the status screen use. Every account can pick its own language in its profile. After changing `.env` locally, the next request picks it up; there is no config cache on the local stack.

[Configuration](configuration.md#language-and-region) lists the related settings, and [Translations](translations.md) explains how to add a language.

<a name="stopping-and-starting-over"></a>
## Stopping and Starting Over

```bash
docker compose stop          # stop the containers, keep everything
docker compose up -d         # start them again
docker compose down          # remove the containers; the database volume stays
```

The database lives in the Docker volume `herdbook_mysql-data`. To start with an empty database (your accounts included), run `docker compose exec app php artisan migrate:fresh` and create an account again.

> [!WARNING]
> The local stack and the production stack use the same container names (`herdbook-app`, `herdbook-mysql`, …) and the same database volume name. Never run both on one Docker host: they would take over each other's containers and data.

<a name="next-steps"></a>
## Next Steps

- [Deployment](deployment.md): run Herdbook on a host of its own, with backups and updates.
- [Networking](networking.md): reach it over Tailscale, on your local network, or both.
- [Inventory](inventory.md): enter what you run. A good order: providers and clients, then projects with their environments, then servers, then domains and hostnames, and finally **Turn on monitoring** on the hostnames.
- [Monitoring](monitoring.md) and [Alerts](alerts.md): turn on checks and decide who gets woken up.
- [Development](development.md): the architecture and conventions, if you want to contribute.

The [documentation index](README.md) lists every page.
