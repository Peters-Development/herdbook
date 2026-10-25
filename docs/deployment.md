# Deployment

- [Introduction](#introduction)
- [The Production Stack](#the-production-stack)
    - [Services](#services)
    - [Where Things Live](#where-things-live)
    - [The Compose Wrapper](#the-compose-wrapper)
- [Preparing the Host](#preparing-the-host)
    - [Packages](#packages)
    - [Tailscale on the Host](#tailscale-on-the-host)
    - [The Checkout](#the-checkout)
    - [Data Directories](#data-directories)
    - [Starting at Boot](#starting-at-boot)
- [Configuration](#configuration)
    - [The App: `.env`](#app-env)
    - [The Host: `docker/box.env`](#box-env)
    - [Quoting Values](#quoting-values)
    - [Keeping `APP_KEY` Safe](#keeping-app-key-safe)
- [First Deploy](#first-deploy)
    - [The First Account](#the-first-account)
    - [Removing the Auth Keys](#removing-the-auth-keys)
    - [Checking Who Can Reach It](#checking-who-can-reach-it)
- [Profiles](#profiles)
- [Updating](#updating)
    - [What `deploy.sh` Does](#what-deploy-does)
    - [Rolling Back](#rolling-back)
    - [Image Versions](#image-versions)
- [Day-to-Day](#day-to-day)
    - [Logs](#logs)
    - [Changing Settings](#changing-settings)
    - [Accounts](#accounts)
    - [When the App Does Not Answer](#when-the-app-does-not-answer)
    - [Tinker and the Database](#tinker-and-the-database)

<a name="introduction"></a>
## Introduction

This page takes you from an empty Linux machine (the **host**) to a running Herdbook that survives reboots, updates itself with one command and is reachable only from your private network.

The order is: [prepare the host](#preparing-the-host), choose how you will reach Herdbook ([Networking](networking.md)), [fill in the two settings files](#configuration), [deploy](#first-deploy), and then set up [backups](backups.md). Plan for the backup on day one: Herdbook will soon hold the only overview of what you run.

<a name="the-production-stack"></a>
## The Production Stack

<a name="services"></a>
### Services

Production uses `docker/docker-compose.prod.yml`. The code is built into the images, nothing is bind-mounted except data, and no service publishes a port unless you turn on the `lan` profile.

| Service | Container | What it does | Profile |
|---|---|---|---|
| `app` | `herdbook-app` | PHP-FPM with the code | always |
| `queue` | `herdbook-queue` | Queue worker for the `checks` and `default` queues | always |
| `queue-checks` | `herdbook-queue-checks` | A second worker for the `checks` queue only, so a check that waits on a dead host never holds up the next minute's checks or the mail | always |
| `scheduler` | `herdbook-scheduler` | `php artisan schedule:work` ([scheduled commands](commands.md#scheduled-commands)) | always |
| `mysql` | `herdbook-mysql` | MySQL 8.4, with binary logging off | always |
| `web` | `herdbook-web` | nginx, inside the network namespace of `ts-herdbook` | `tailscale` |
| `ts-herdbook` | `herdbook-ts` | Tailscale sidecar: `tailscale serve` publishes HTTPS on `https://herdbook.<tailnet>.ts.net` | `tailscale` |
| `web-lan` | `herdbook-web-lan` | nginx, on the internal Docker network only | `lan` |
| `caddy` | `herdbook-caddy` | HTTPS on your local network, with certificates from its own CA | `lan` |
| `vaultwarden` | `herdbook-vaultwarden` | The optional password vault, inside the network namespace of `ts-vault` | `vaultwarden` |
| `ts-vault` | `herdbook-ts-vault` | Tailscale sidecar for the vault: `https://vault.<tailnet>.ts.net` | `vaultwarden` |

Herdbook's own images are built from `docker/Dockerfile`: its `app` target (PHP-FPM with the production PHP settings and the code) runs `app`, `queue`, `queue-checks` and `scheduler`, and its `web` target (nginx with the public files copied from `app`) runs `web` and `web-lan`. MySQL, Tailscale, Caddy and Vaultwarden use their official images.

Every container logs to Docker's `local` driver, which rotates at five files of 20 MB. On every start, the app containers cache their configuration, routes and views from the environment they were started with. That is why a changed `.env` needs the containers to be recreated, not restarted ([Changing Settings](#changing-settings)).

<a name="where-things-live"></a>
### Where Things Live

| What | Where |
|---|---|
| The code | `/srv/herdbook` (a git checkout) |
| App settings | `/srv/herdbook/.env`, the only settings file the app containers see |
| Host settings and secrets | `/srv/herdbook/docker/box.env`: profiles, the MySQL root password, Tailscale auth keys, Vaultwarden, the LAN address |
| App files, Vaultwarden data, Caddy's CA | `DATA_DIR/app`, `DATA_DIR/vaultwarden`, `DATA_DIR/caddy` (`DATA_DIR` is `/srv/herdbook-data` by default) |
| The database | Docker volume `herdbook_mysql-data` |
| The Tailscale identity of each node | Docker volumes `herdbook_ts-herdbook-state` and `herdbook_ts-vault-state` |
| Backup settings | `/etc/herdbook/backup.env` and `/etc/herdbook/restic-password` ([Backups](backups.md#setting-up)) |

> [!NOTE]
> The systemd units in `docker/boot/` and `docker/backup/` refer to `/srv/herdbook`. Keep the checkout there, or adjust the units.

<a name="the-compose-wrapper"></a>
### The Compose Wrapper

On the host, always run compose through `docker/compose.sh`. It loads `.env` for the app and `docker/box.env` for the host, turns on the profiles from `COMPOSE_PROFILES` and checks that each profile has what it needs, before handing everything to `docker compose`:

```bash
docker/compose.sh ps
docker/compose.sh logs -f app
docker/compose.sh exec app php artisan users:create
```

Two scripts build on it. `docker/up.sh` brings the stack up in a known-good state, and `docker/deploy.sh` [updates](#updating) it. A short alias saves typing:

```bash
echo "alias hb='/srv/herdbook/docker/compose.sh'" >> ~/.bash_aliases
```

These docs write the commands in full, as run from `/srv/herdbook`.

> [!WARNING]
> Never run a bare `docker compose` in `/srv/herdbook`. It picks up `docker-compose.yml`, which is the local development stack, with the same container names.

For a single command you can also use `docker exec` with the fixed container names, for example `docker exec -it herdbook-app php artisan tinker`. Don't forget `-it` for a command that asks you something.

<a name="preparing-the-host"></a>
## Preparing the Host

<a name="packages"></a>
### Packages

Install Docker Engine with the compose plugin by following [Docker's instructions](https://docs.docker.com/engine/install/) for your distribution. Then the tools the scripts use (shown for Debian and Ubuntu):

```bash
sudo apt install -y git sqlite3 jq
```

The backup needs [restic](https://github.com/restic/restic/releases) 0.17 or newer, because it lets restic run the database dump itself. Your distribution's package may be older; the official binary works fine:

```bash
restic version
```

Let your own user run Docker without `sudo`, because `deploy.sh` runs as you. Sign out and back in afterwards:

```bash
sudo usermod -aG docker "$USER"
```

<a name="tailscale-on-the-host"></a>
### Tailscale on the Host

The Tailscale profiles bring their own sidecars, so the host itself doesn't need Tailscale for that. You do need it on the host when the [backup goes to a NAS](backups.md#on-a-nas) over Tailscale, or when checks, the heartbeat or your own ntfy server live on your tailnet: the containers reach the tailnet through the host.

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --advertise-tags=tag:herdbook-host
sudo tailscale set --auto-update
```

The tag `tag:herdbook-host` is explained in [Networking](networking.md#tags-and-grants).

<a name="the-checkout"></a>
### The Checkout

The checkout belongs to your user, not to root, because `deploy.sh` runs `git pull`:

```bash
sudo install -d -o "$USER" -g "$USER" /srv/herdbook
git clone https://github.com/Peters-Development/herdbook.git /srv/herdbook
```

<a name="data-directories"></a>
### Data Directories

Only root may enter the data directory: it holds the keys of the vault and of Caddy's certificate authority. The app's own directory belongs to `www-data` in the app container (uid 33):

```bash
sudo install -d -m 700 /srv/herdbook-data
sudo install -d -o 33 -g 33 /srv/herdbook-data/app
sudo install -d -m 700 /srv/herdbook-data/vaultwarden
```

Docker creates `/srv/herdbook-data/caddy` when the `lan` profile first starts. If you choose another `DATA_DIR`, use that path here and in `docker/box.env`.

<a name="starting-at-boot"></a>
### Starting at Boot

Docker restarts the containers after a reboot by itself, but in no particular order. A web server that starts before its Tailscale sidecar ends up in a dead network namespace, and Caddy cannot bind the LAN address before the network is up. The `herdbook-stack` unit runs `docker/up.sh` once the network is online, which puts all of that right:

```bash
sudo systemctl link /srv/herdbook/docker/boot/herdbook-stack.service
sudo systemctl enable herdbook-stack.service
```

<a name="configuration"></a>
## Configuration

Herdbook reads two files. `.env` holds the app's settings and is the only file the app containers see. `docker/box.env` holds the host's own settings, such as the MySQL root password and the Tailscale auth keys, so those never reach the app. Both stay out of git and out of the images.

```bash
cd /srv/herdbook
cp .env.example .env
cp docker/box.env.example docker/box.env
chmod 600 .env docker/box.env
```

<a name="app-env"></a>
### The App: `.env`

Change these from the example. [Configuration](configuration.md) describes every setting Herdbook adds.

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | The address you open Herdbook on: `https://herdbook.<tailnet>.ts.net`, or with only the `lan` profile your LAN address (`https://<LAN_IP>` or a local name). Links in alerts, and the link `screens:create` prints, use it. |
| `APP_KEY` | `base64:` followed by the output of `openssl rand -base64 32` |
| `APP_LOCALE` | The default language: `en` or `nl` |
| `HERDBOOK_DISPLAY_TIMEZONE` | Your timezone, for example `Europe/London` (default `UTC`) |
| `HERDBOOK_CURRENCY_SYMBOL` | The symbol in front of every amount (default `€`) |
| `LOG_CHANNEL` | `stderr`, so the logs end up in `docker/compose.sh logs` |
| `LOG_LEVEL` | `warning` |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_PASSWORD` | The output of `openssl rand -hex 24` |
| `MAIL_*` | Your SMTP server, for email alerts: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS`. Use a sender address your server accepts, usually the mailbox you sign in with. If the server refuses the host's name in its greeting, set `MAIL_EHLO_DOMAIN` to a real domain name (by default it is the host from `APP_URL`). Not sending email? Set `MAIL_MAILER=log`: the example points to Mailpit, which only exists on the local stack. |
| `PUSHOVER_APP_TOKEN` | Optional: the application token from pushover.net. Each user adds their own user key in their profile. |
| `NTFY_URL`, `NTFY_TOKEN` | Optional: an ntfy topic, such as `https://ntfy.sh/<long-random-topic>`, and a token for a protected topic. |
| `HERDBOOK_VAULT_URL` | `https://vault.<tailnet>.ts.net` with the [vault](vaultwarden.md), or the address of your own web vault. Empty: no vault links. |
| `HERDBOOK_HEARTBEAT_URL` | Optional: a URL Herdbook pings every five minutes ([Watching the Watcher](alerts.md#watching-the-watcher)). |

Remove `DB_ROOT_PASSWORD` and `HOST_UID`. They are for the local stack only; on the host the root password lives in `docker/box.env`.

Leave `DB_HOST=mysql`, `DB_DATABASE` and `DB_USERNAME` as they are. MySQL creates that database and user on its first start.

<a name="box-env"></a>
### The Host: `docker/box.env`

The example file explains every line. `docker/compose.sh` tells you what is missing for the profiles you chose.

| Variable | Needed for | Value |
|---|---|---|
| `COMPOSE_PROFILES` | always | How Herdbook is reached, comma-separated: `tailscale`, `lan` or both, plus `vaultwarden` if you want the vault. Example: `tailscale,vaultwarden`. See [Profiles](#profiles). |
| `DATA_DIR` | always | The data directory. Default `/srv/herdbook-data`. |
| `DB_ROOT_PASSWORD` | always | The output of `openssl rand -hex 24`. |
| `TS_AUTHKEY_HERDBOOK` | `tailscale` | A tagged auth key for the app's node, for the first start only ([Auth Keys](networking.md#auth-keys)). |
| `TS_AUTHKEY_VAULT` | `vaultwarden` | The same, for the vault's node. |
| `TAILNET_DOMAIN` | `vaultwarden` | Your tailnet's domain, `<tailnet>.ts.net`, as shown on the DNS page of the Tailscale admin console. |
| `VAULT_SIGNUPS_ALLOWED` | `vaultwarden` | `false`. Only `true` while you create the vault accounts ([Vaultwarden](vaultwarden.md#creating-accounts)). |
| `VAULT_ADMIN_TOKEN` | `vaultwarden` | Empty, which turns the vault's admin page off ([The Admin Page](vaultwarden.md#the-admin-page)). |
| `LAN_IP` | `lan` | The host's fixed IPv4 address on your local network. HTTPS is published on this address only. |
| `LAN_HOSTNAMES` | `lan` | Optional: local DNS names for the host, separated by spaces, such as `herdbook.home.arpa`. |
| `LAN_PORT` | `lan` | The HTTPS port on `LAN_IP`. Default `443`. |

> [!NOTE]
> `DB_PASSWORD` and `DB_ROOT_PASSWORD` only count on MySQL's very first start; after that they live in the database. To change one later, change it in MySQL first and in the settings files second. The other way round, the app or the backup dump breaks without saying so.

<a name="quoting-values"></a>
### Quoting Values

When a value contains a `$`, as passwords and the vault's admin token often do, put it in **single quotes**. Compose would otherwise try to expand it. This goes for both files:

```ini
MAIL_PASSWORD='pa$$word'
```

<a name="keeping-app-key-safe"></a>
### Keeping `APP_KEY` Safe

The two-factor secrets and recovery codes in the database are encrypted with `APP_KEY`. Without it, nobody can sign in after a restore. The key is part of every [backup](backups.md#what-is-in-a-backup), but that is exactly the backup you want to be able to use when everything else is gone.

So keep a copy of `APP_KEY` outside the host as well: in your password manager and on paper, next to the restic password. Don't simply replace the key of an installation that is in use: the stored two-factor secrets can then no longer be decrypted, and nobody can sign in until their two-factor setup is [reset](#accounts). To rotate it, put the old key in `APP_PREVIOUS_KEYS` (comma-separated) when you set the new one: Laravel then still decrypts what was encrypted with the old key.

<a name="first-deploy"></a>
## First Deploy

With both files filled in:

```bash
docker/deploy.sh
```

The first run takes a while: it builds the images, starts MySQL, creates the tables and starts everything else. [What `deploy.sh` Does](#what-deploy-does) explains the steps.

<a name="the-first-account"></a>
### The First Account

```bash
docker/compose.sh exec app php artisan users:create
```

Then sign in **straight away** on `APP_URL` and set up two-factor authentication. Until that first sign-in, the password alone is enough to set it up. Keep the recovery codes in your password manager.

<a name="removing-the-auth-keys"></a>
### Removing the Auth Keys

The Tailscale nodes now have their identity in their state volumes, so the auth keys are no longer needed. Remove the values of `TS_AUTHKEY_HERDBOOK` and `TS_AUTHKEY_VAULT` from `docker/box.env`, then run:

```bash
docker/up.sh
```

<a name="checking-who-can-reach-it"></a>
### Checking Who Can Reach It

With only the `tailscale` profile, nothing on the host may listen on your local network:

```bash
sudo ss -ltnp | grep -v 127.0.0       # no docker-proxy or nginx ports
curl -m 5 http://<LAN_IP>             # from another device on your network: must fail
```

Then turn Tailscale off on your phone: `https://herdbook.<tailnet>.ts.net` must not load. With Tailscale on, it loads and you can sign in with your second factor. For the `lan` profile, see [Verifying the Local Network](networking.md#verifying-the-local-network).

Next: set up the [backups](backups.md#setting-up), and run a [restore test](backups.md#restore-test) before you rely on them.

<a name="profiles"></a>
## Profiles

`COMPOSE_PROFILES` in `docker/box.env` decides how you reach Herdbook:

| Profile | Reachable | Services |
|---|---|---|
| `tailscale` | Only on your tailnet, also on the road: `https://herdbook.<tailnet>.ts.net`. No port is published at all. | `web`, `ts-herdbook` |
| `lan` | From any device on your local network, without Tailscale: `https://<LAN_IP>` and any `LAN_HOSTNAMES`. HTTPS with a certificate authority of its own. | `web-lan`, `caddy` |
| `tailscale,lan` | Both | all four |
| `vaultwarden` (in addition) | The password vault, always on the tailnet only: `https://vault.<tailnet>.ts.net` | `vaultwarden`, `ts-vault` |

You need at least `tailscale` or `lan`; `docker/compose.sh` refuses to start without either, because nobody could reach the app. [Networking](networking.md) explains each way in.

What each profile needs:

- **`tailscale`**: the tailnet settings, `tag:herdbook` with its grant, and `TS_AUTHKEY_HERDBOOK` ([Tailscale](networking.md#tailscale)).
- **`lan`**: a fixed address for the host and `LAN_IP` ([Local Network](networking.md#local-network)).
- **`vaultwarden`**: the tailnet settings, `tag:vault` with its grant, `TS_AUTHKEY_VAULT` and `TAILNET_DOMAIN` ([Vaultwarden](vaultwarden.md)).

To switch, change `COMPOSE_PROFILES` and run `docker/up.sh`. It also removes the containers of a profile you switched off; a plain `up` would leave them running.

<a name="updating"></a>
## Updating

```bash
docker/deploy.sh
```

That's all. The script pulls first and then starts itself again, so a change to the script itself takes effect in the same deploy.

`deploy.sh` deploys whatever the branch you cloned points to, normally `main`. If you want to decide yourself when changes arrive, deploy from a fork and bring it up to date when you are ready.

<a name="what-deploy-does"></a>
### What `deploy.sh` Does

1. Refuses to run on a detached commit (a [rollback](#rolling-back)), then runs `git pull --ff-only`.
2. Pulls the images it doesn't build: MySQL, Tailscale, Caddy and Vaultwarden.
3. Remembers the current app and web images, then builds new ones (with fresh base images).
4. Starts MySQL, waits until it is healthy, and runs the migrations in a one-off container with the new image.
5. **If a migration fails**, it puts the previous images back and restarts nothing. Fix the cause and deploy again. Without this, the next reboot would start the new code on the old schema.
6. Brings the stack up with `docker/up.sh`, restarts the queue workers and, with the `lan` profile, recreates Caddy (Caddy only reads its configuration at start).
7. Removes the images that are no longer used.
8. When the systemd units in `docker/backup/` or `docker/boot/` changed, it tells you to run `sudo systemctl daemon-reload` (and `sudo systemctl link /srv/herdbook/docker/<directory>/<unit>` for a new unit).
9. Shows `docker/compose.sh ps`.

<a name="rolling-back"></a>
### Rolling Back

To go back to an earlier version:

```bash
git checkout <commit> && docker/deploy.sh --pulled
```

`--pulled` skips the `git pull`. To return to the normal track:

```bash
git checkout main && docker/deploy.sh
```

Migrations are not rolled back. Most of them only add tables and columns, which older code doesn't notice. Before you roll back past a release whose migrations rename or remove something, keep in mind that the older code doesn't know the new names; when in doubt, restore the [backup](backups.md) from before the update instead.

<a name="image-versions"></a>
### Image Versions

The versions are pinned in the repository, so they change with an update:

| Image | Where |
|---|---|
| PHP | The `FROM` tag in `docker/Dockerfile`, pinned to a patch release |
| MySQL | `mysql:8.4` in `docker/docker-compose.prod.yml`, the latest 8.4 at every deploy |
| Caddy, Vaultwarden | Pinned tags in `docker/docker-compose.prod.yml` |
| Tailscale | `tailscale/tailscale:stable`, Tailscale's stable channel at every deploy |
| nginx | `nginx:stable-alpine`, refreshed at every build |

If you run your own fork, raising one of those tags and deploying is all an image update takes. For the vault, use the same tag in the commands of [Vaultwarden](vaultwarden.md).

<a name="day-to-day"></a>
## Day-to-Day

<a name="logs"></a>
### Logs

```bash
docker/compose.sh logs -f app queue queue-checks scheduler
docker/compose.sh logs -f scheduler        # output of the scheduled commands
docker/compose.sh logs -f queue-checks     # check runs, and a warning when the host's own line is down
```

The backup logs to the journal: `journalctl -u herdbook-backup` and `systemctl list-timers herdbook-backup.timer`.

<a name="changing-settings"></a>
### Changing Settings

| What changed | What to run |
|---|---|
| `.env` | `docker/compose.sh up -d --force-recreate app queue queue-checks scheduler`. A plain restart keeps the environment the containers were created with. |
| `docker/box.env` | `docker/up.sh` |
| `COMPOSE_PROFILES` | `docker/up.sh`, which also removes the containers of a profile you switched off |
| `docker/caddy/Caddyfile` | `deploy.sh` recreates Caddy. By hand: `docker/compose.sh up -d --no-deps --force-recreate caddy` |
| A systemd unit | `sudo systemctl daemon-reload`; for a new unit `sudo systemctl link` it first |

<a name="accounts"></a>
### Accounts

Accounts are managed on the command line ([Commands](commands.md#accounts)).

**A new account:**

```bash
docker/compose.sh exec app php artisan users:create
```

**Lost both the authenticator and the recovery codes:**

```bash
docker/compose.sh exec app php artisan users:reset-two-factor you@example.com
```

This removes the account's two-factor setup and signs it out everywhere. At the next sign-in, Herdbook asks for a new setup. Sign in soon after: until then, the password alone is enough to set up two-factor authentication.

**A forgotten password.** There is no reset by email. Set a new one in Tinker (the password is hashed on save):

```bash
docker/compose.sh exec app php artisan tinker
```

```php
App\Models\User::where('email', 'you@example.com')->first()->update(['password' => 'a-new-password-of-12-or-more-characters']);
```

Signed-in users change their own password, language and alert settings in their profile.

<a name="when-the-app-does-not-answer"></a>
### When the App Does Not Answer

If the app or the vault stops answering after a reboot, a restart of a sidecar or a change, run:

```bash
docker/up.sh
```

`web` and `vaultwarden` live in the network namespace of their Tailscale sidecar. When a sidecar restarts on its own, they keep the old, dead namespace; `up.sh` recreates them in the current one. The boot unit and every deploy do this for you, so you only need it when a sidecar restarted in between.

<a name="tinker-and-the-database"></a>
### Tinker and the Database

```bash
docker/compose.sh exec app php artisan tinker
docker/compose.sh exec mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"'
```

There is no phpMyAdmin or other database tool in the stack, on purpose: nothing extra listens anywhere.
