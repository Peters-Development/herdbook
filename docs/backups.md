# Backups

- [Introduction](#introduction)
- [What Is in a Backup](#what-is-in-a-backup)
- [Where It Goes](#where-it-goes)
- [Setting Up](#setting-up)
    - [The Settings File](#the-settings-file)
- [On the Host](#on-the-host)
- [On a NAS](#on-a-nas)
    - [The Backup Server](#the-backup-server)
    - [Pruning on the NAS](#pruning-on-the-nas)
    - [Filesystem Snapshots](#filesystem-snapshots)
    - [Moving From the Host to a NAS](#moving-from-the-host-to-a-nas)
- [When a Backup Fails](#when-a-backup-fails)
- [Keeping the Keys Offline](#keeping-the-keys-offline)
- [Restore Test](#restore-test)
    - [On the Host](#restore-test-on-the-host)
    - [From a NAS](#restore-test-from-a-nas)
    - [The Full Test](#the-full-test)
- [Disaster Recovery](#disaster-recovery)
- [Moving to Another Host](#moving-to-another-host)
    - [Going Back](#going-back)

<a name="introduction"></a>
## Introduction

Every night, a systemd timer on the host makes an encrypted backup with [restic](https://restic.net): the database, the app's files, the vault and both settings files. It runs on the host itself, as root, from `docker/backup/backup.sh`, so it still works when something inside the stack is broken.

restic encrypts everything before it leaves the host, wherever the backup lands. Without the restic password, a backup is unreadable, for an attacker and for you. That is why the password, like `APP_KEY`, also goes [offline](#keeping-the-keys-offline).

<a name="what-is-in-a-backup"></a>
## What Is in a Backup

Each night produces two snapshots, both tagged `herdbook` and with the fixed host name `herdbook` (so their history continues when you move to another host):

- **`herdbook.sql`**: a dump of the MySQL database (`mysqldump --single-transaction`). restic runs the dump itself, so a dump that fails makes that step fail instead of storing an empty file. It is not compressed, so restic can deduplicate it from night to night.
- **The files**:
  - `DATA_DIR`: the app's files (`DATA_DIR/app`), the vault with a consistent copy of its database (`db-backup.sqlite3`, made with SQLite's online backup), and with the `lan` profile Caddy's certificate authority;
  - `/srv/herdbook/.env` and `/srv/herdbook/docker/box.env`. `.env` holds `APP_KEY`, without which the two-factor secrets in the dump can't be read.

Not in the backup: the code (it comes from git), the Tailscale identity of the nodes (a restored host gets new auth keys), `/etc/herdbook` with the backup's own settings and password, and any firewall rules on the host.

<a name="where-it-goes"></a>
## Where It Goes

You choose the destination with `RESTIC_REPOSITORY` in `/etc/herdbook/backup.env`:

| Destination | Protects against | Pruned by |
|---|---|---|
| **On the host** (`/srv/herdbook-backup`, preferably on a second disk) | Mistakes: a bad migration, something deleted by accident, a broken database. **Not** against a broken disk, theft or fire. | The host, after every good backup (`PRUNE_ON_BOX=true`) |
| **A NAS**, over Tailscale, append-only | A host that is gone, too | The NAS (`docker/backup/prune.sh`) |

Starting on the host is fine while you try Herdbook out. Once you rely on it, add a backup that lives elsewhere: you can [move the snapshots over](#moving-from-the-host-to-a-nas) later.

<a name="setting-up"></a>
## Setting Up

First prepare the destination: [on the host](#on-the-host) that is one directory, [on a NAS](#on-a-nas) it is a small backup server. Then, on the host:

```bash
sudo install -d -m 700 /etc/herdbook
sudo cp /srv/herdbook/docker/backup/backup.env.example /etc/herdbook/backup.env    # fill it in, see below
sudo sh -c 'openssl rand -hex 32 > /etc/herdbook/restic-password'
sudo chmod 600 /etc/herdbook/backup.env /etc/herdbook/restic-password

# Create the repository
sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic init'

# The nightly timer
sudo systemctl link /srv/herdbook/docker/backup/herdbook-backup.service \
    /srv/herdbook/docker/backup/herdbook-backup.timer \
    /srv/herdbook/docker/backup/herdbook-backup-failed.service
sudo systemctl enable --now herdbook-backup.timer

# Run the first backup now, and look at it
sudo systemctl start herdbook-backup.service
journalctl -u herdbook-backup -n 50
```

A good run ends with `Backup complete.` Write the restic password down now ([Keeping the Keys Offline](#keeping-the-keys-offline)).

The timer runs every night at 03:30, with up to 15 minutes of random delay. When the host was off at that time, the backup runs as soon as it is back. A run may take up to three hours before systemd gives up on it, and it runs with low CPU and disk priority.

<a name="the-settings-file"></a>
### The Settings File

`/etc/herdbook/backup.env` is read by systemd, which takes a comment after a value as part of the value: put comments on lines of their own.

| Setting | Value |
|---|---|
| `RESTIC_REPOSITORY` | An absolute path on the host, such as `/srv/herdbook-backup`, or a restic URL, such as `rest:http://nas.<tailnet>.ts.net:8000/herdbook/` |
| `PRUNE_ON_BOX` | `true` for a repository on the host, `false` for a NAS. Exactly `true` or `false`: anything else stops the backup, so a typo can't switch pruning off unnoticed. |
| `RESTIC_REST_USERNAME`, `RESTIC_REST_PASSWORD` | Only for a NAS: the user of the backup server |
| `RESTIC_PASSWORD_FILE` | `/etc/herdbook/restic-password` |
| `HERDBOOK_DIR` | The checkout, `/srv/herdbook`. `DATA_DIR` is read from its `docker/box.env`. |
| `NTFY_URL`, `NTFY_TOKEN` | Where a failed backup is reported when the app can't do it ([When a Backup Fails](#when-a-backup-fails)). The app's own topic is fine. |

The script refuses a repository on the host without `PRUNE_ON_BOX=true` (nothing else would ever remove old snapshots, and the disk fills up), a repository inside `DATA_DIR` (it would back itself up every night) and a `RESTIC_REPOSITORY` with a space in it.

<a name="on-the-host"></a>
## On the Host

Create the directory, preferably on a second disk:

```bash
sudo install -d -m 700 /srv/herdbook-backup
```

Keep `RESTIC_REPOSITORY=/srv/herdbook-backup` and `PRUNE_ON_BOX=true` in `backup.env`, or change the path if the directory lives elsewhere.

After every good backup, the host prunes old snapshots with `docker/backup/prune.sh`. It keeps one snapshot per day for 14 days, per week for 8 weeks and per month for 12 months. Nothing is pruned while backups fail.

Two things to keep an eye on:

- **Disk space.** When the repository shares a disk with MySQL, mind the space: pruning needs some room itself. From 90% full, you get an alert.
- **The clock.** Pruning refuses as long as a snapshot is dated more than a day in the future, which on the host usually means its clock jumped. Set the clock right (`timedatectl`) and remove that snapshot:

  ```bash
  sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic snapshots'
  sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic forget <id>'
  ```

<a name="on-a-nas"></a>
## On a NAS

A backup on a NAS survives the host. The NAS runs restic's backup server in **append-only** mode: the host can add new backups, but can't delete or overwrite anything. Even a compromised host can't wipe its own history.

The host reaches the NAS over Tailscale, so you need [Tailscale on the host](deployment.md#tailscale-on-the-host) and the grant to `tag:backup` ([Tags and Grants](networking.md#tags-and-grants)).

<a name="the-backup-server"></a>
### The Backup Server

On the NAS, once:

- Install Tailscale, with the tag `tag:backup`.
- Run [rest-server](https://github.com/restic/rest-server) (the Docker image `restic/rest-server`) on port 8000, with `OPTIONS="--append-only --private-repos"`.
- Add a user `herdbook` to its `.htpasswd`, with a password from `openssl rand -hex 24`. Hexadecimal, so no character breaks a URL or a shell.

Then, in `/etc/herdbook/backup.env` on the host, switch to the NAS lines:

```ini
RESTIC_REPOSITORY=rest:http://nas.<tailnet>.ts.net:8000/herdbook/
PRUNE_ON_BOX=false
RESTIC_REST_USERNAME=herdbook
RESTIC_REST_PASSWORD=<the password>
```

The connection runs inside Tailscale, and restic encrypts the data itself. Write the rest-server password down too: you need it to reach the backup when the host is gone.

<a name="pruning-on-the-nas"></a>
### Pruning on the NAS

Since the host can't delete anything there, the NAS prunes, directly on the repository directory. Copy `docker/backup/prune.sh` to the NAS (it needs `restic` and `jq`) and schedule it weekly in the NAS's task scheduler, outside the backup window, for example on Sunday at noon:

```bash
prune.sh /path/to/rest-server-data/herdbook /path/to/restic-password
```

It applies the same retention as on the host. For that, the NAS needs the restic password in a file (`chmod 600`, readable for the task only), which means the NAS can read the backups too. That is the price of pruning without letting the host delete.

The script refuses to prune as long as a snapshot is dated more than a day in the future. A compromised host can't delete backups, but it could add fake snapshots with a future date that push the real ones out of every retention period.

Check after the first run that the task succeeded. A failing task bothers nobody, and the repository just keeps growing.

<a name="filesystem-snapshots"></a>
### Filesystem Snapshots

Snapshots of the repository directory on the NAS itself (Btrfs or ZFS, for example daily, kept for a few months) are **not optional**. They are the safety net for when pruning ever throws away something it shouldn't have, including snapshots that a compromised host forged with a date in the past, which `prune.sh` can't recognize. Check after the first time that they are being made.

<a name="moving-from-the-host-to-a-nas"></a>
### Moving From the Host to a NAS

A new repository starts empty. To bring the snapshots from the host along, create the NAS repository from the existing one (with the NAS settings already in `backup.env`) and copy them over, instead of a plain `restic init`:

```bash
sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic init --from-repo /srv/herdbook-backup --from-password-file /etc/herdbook/restic-password --copy-chunker-params'
sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic copy --from-repo /srv/herdbook-backup --from-password-file /etc/herdbook/restic-password'
```

`--copy-chunker-params` keeps the deduplication between the old and the new snapshots intact. The repository on the host is no longer pruned after this, so remove it once the first backup to the NAS succeeded: `sudo rm -rf /srv/herdbook-backup`.

<a name="when-a-backup-fails"></a>
## When a Backup Fails

Every step runs even when an earlier one failed: a broken dump must not also cost you the vault's backup. The run then ends as failed, and `herdbook-backup-failed.service` sends an alert right away. The exit code of `backup.sh` decides which:

| Exit code | Alert |
|---|---|
| `1` | The backup failed (or the settings are wrong). |
| `3` | The backup succeeded, pruning old snapshots failed. |
| `4` | The backup succeeded, but the disk with the repository is 90% full or more. |
| `5` | The backup failed, and the disk is 90% full or more. |

The alert goes through the app (`alerts:send`), on the same channels as the checks ([Alerts](alerts.md#channels)). When the app or its database is down too, it goes straight to `NTFY_URL` from `backup.env`. Fill that in: a backup often fails precisely because something else is broken.

Herdbook also watches for backups that silently stop. After every good run, `backup.sh` touches a marker file, `DATA_DIR/app/backup-last-success`. Every morning at 09:00, [`backups:check`](commands.md#backups-check) alerts you when that marker is more than 26 hours old: a timer that doesn't run, or a host that was off at night. The dashboard and the status screen show the backup's age too. As long as there is no marker at all, Herdbook assumes backups aren't set up yet and stays quiet.

<a name="keeping-the-keys-offline"></a>
## Keeping the Keys Offline

A backup you can only open with keys that lived on the lost host is no backup. Keep these **outside the host**, on paper and in your password manager:

| Key | Without it |
|---|---|
| The restic password (`/etc/herdbook/restic-password`) | The backup can't be read at all. |
| `APP_KEY` from `.env` | Nobody can sign in after a restore: the two-factor secrets stay encrypted. |
| The rest-server password (with a NAS) | You can't reach the backup. |
| The vault's master password (with the [vault](vaultwarden.md#backups)) | The vault can't be opened, not even from the backup. |

<a name="restore-test"></a>
## Restore Test

A backup you never restored is a hope, not a backup. Run this test when you set up the backup, and then monthly. Don't run it around the time of the nightly backup (03:30): `restic check` then waits until the backup is done.

> [!NOTE]
> The commands on this page assume the default `DATA_DIR`, `/srv/herdbook-data`, and the checkout in `/srv/herdbook`. If yours differ, change the paths accordingly.

<a name="restore-test-on-the-host"></a>
### On the Host

With the repository on the host, the first steps run on the host:

```bash
sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic snapshots --tag herdbook && restic check --retry-lock 30m --read-data-subset=5%'
(umask 077; sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic dump --path /herdbook.sql latest /herdbook.sql' > /tmp/herdbook.sql)
grep -c "INSERT INTO \`users\`" /tmp/herdbook.sql        # must be more than 0
sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic restore --path /srv/herdbook-data latest --target /tmp/restore'
sudo grep APP_KEY /tmp/restore/srv/herdbook/.env
# With the vault: its copy must be there and intact ("ok", and the number of accounts)
sudo sqlite3 -readonly /tmp/restore/srv/herdbook-data/vaultwarden/db-backup.sqlite3 'pragma integrity_check; select count(*) from users'
```

Copy `/tmp/herdbook.sql` to your own machine (with `scp`), note the `APP_KEY`, and do [the full test](#the-full-test); its vault step is for a NAS backup, on the host the check above is enough. Then clean up on the host:

```bash
sudo rm -rf /tmp/restore /tmp/herdbook.sql
```

<a name="restore-test-from-a-nas"></a>
### From a NAS

With a NAS, test from another machine than the host, one in `group:herdbook`. That proves you can get at the backup without the host:

```bash
# A copy of /etc/herdbook/backup.env, with RESTIC_PASSWORD_FILE pointing to a
# local file with the password (from paper or from your password manager)
set -a; . ./backup.env; set +a
restic snapshots --tag herdbook
restic check --read-data-subset=5%

# Each night makes two snapshots (database and files); --path picks the right one.
restic dump --path /herdbook.sql latest /herdbook.sql > herdbook.sql
grep -c "INSERT INTO \`users\`" herdbook.sql             # must be more than 0

restic restore --path /srv/herdbook-data latest --target ./restore
ls ./restore/srv/herdbook-data/vaultwarden/db-backup.sqlite3 ./restore/srv/herdbook/.env
```

<a name="the-full-test"></a>
### The Full Test

This proves that you can actually sign in to the restored data. It uses the [local stack](installation.md#trying-it-locally) on your own machine and **overwrites its database**.

1. **Stop the local workers first.** Otherwise your local scheduler starts running the production checks and sending alerts at once. Also remove `PUSHOVER_APP_TOKEN` and `NTFY_URL` from your local `.env`: the restored accounts carry their real Pushover keys.

   ```bash
   docker compose stop scheduler queue queue-checks
   ```

2. **The app.** Load the dump into the local MySQL:

   ```bash
   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < herdbook.sql
   ```

   Put the `APP_KEY` from `./restore/srv/herdbook/.env` (or the one you noted) in your local `.env` and run `docker compose exec app php artisan optimize:clear`. Sign in on http://localhost:16000 with your usual password and two-factor code. If that works, both the dump and the key are good.

   Afterwards, put your own `APP_KEY` back, run `docker compose exec app php artisan migrate:fresh`, create your local account again with `users:create` (and `demo:reset` if you want the demo data back), and start the workers again with `docker compose up -d`.

3. **The vault** (with a NAS backup). Copy `./restore/srv/herdbook-data/vaultwarden` to an empty directory `vw/`, rename `db-backup.sqlite3` in it to `db.sqlite3`, and start Vaultwarden with the same image tag as in `docker/docker-compose.prod.yml`:

   ```bash
   docker run --rm -p 127.0.0.1:18080:80 -v "$PWD/vw":/data vaultwarden/server:<tag>
   ```

   Sign in on http://localhost:18080. A browser treats `localhost` as secure, so the web vault works there without HTTPS.

4. **Clean up.** `./restore`, `vw/` and `herdbook.sql` contain `APP_KEY`, the MySQL passwords and the vault's keys:

   ```bash
   rm -rf ./restore ./vw ./herdbook.sql
   ```

<a name="disaster-recovery"></a>
## Disaster Recovery

When the host is gone, you can only restore from a backup that lived elsewhere, such as a NAS. A backup on the host itself is lost with the host. If the old host still works, [move](#moving-to-another-host) instead.

1. Prepare a new host as in [Preparing the Host](deployment.md#preparing-the-host). Skip the configuration, the first deploy and the backup setup.
2. Put back `/etc/herdbook/backup.env` and `/etc/herdbook/restic-password` as in [Setting Up](#setting-up), with the **old** restic password and the rest-server password from paper. Do **not** run `restic init` (the repository exists) and do **not** turn the timer on yet.
3. Restore the files as root, so owners and permissions are right, and fetch the dump:

   ```bash
   sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic restore --path /srv/herdbook-data latest --target /'
   (umask 077; sudo sh -c 'set -a; . /etc/herdbook/backup.env; restic dump --path /herdbook.sql latest /herdbook.sql' > /tmp/herdbook.sql)
   sudo mv /srv/herdbook-data/vaultwarden/db-backup.sqlite3 /srv/herdbook-data/vaultwarden/db.sqlite3    # only with the vault
   ```

   This also puts back `/srv/herdbook/.env` and `/srv/herdbook/docker/box.env`. The `mv` puts the vault's database copy in place; without it, the vault starts empty. The checkout and the two settings files still have the old host's owner, so give them back to your user:

   ```bash
   sudo chown "$USER": /srv/herdbook /srv/herdbook/docker /srv/herdbook/.env /srv/herdbook/docker/box.env
   ```

4. With Tailscale: remove the old nodes `herdbook` and `vault` in the Tailscale admin console. Otherwise the new nodes are called `herdbook-1` and `vault-1`, and neither `APP_URL` nor the vault's address match any more.
5. With Tailscale: put new [auth keys](networking.md#auth-keys) in `docker/box.env`. With the `lan` profile, Caddy's CA comes back from the backup, so your devices trust Herdbook again straight away. If the new host has another address, change `LAN_IP` (and `APP_URL` if it uses the address).
6. Start only MySQL, wait until it is ready, and load the dump:

   ```bash
   docker/compose.sh up -d --wait mysql
   docker/compose.sh exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < /tmp/herdbook.sql
   rm /tmp/herdbook.sql
   ```

7. Run `docker/deploy.sh`, remove the `TS_AUTHKEY_*` values from `docker/box.env` again, and run `docker/up.sh`.
8. Only now turn on the backup timer (the `systemctl link` and `enable --now` from [Setting Up](#setting-up)).

<a name="moving-to-another-host"></a>
## Moving to Another Host

This moves Herdbook to a new machine while the old one still works. Never run both at the same time: the checks and alerts would run twice, and two Tailscale nodes would fight over one name. Below, `<old-host>` and `<new-host>` are the addresses or names you reach them by with `ssh` from your own machine.

1. **On the new host:** prepare it as in [Preparing the Host](deployment.md#preparing-the-host), without the configuration, the first deploy and the backup setup. That can be well in advance; the old host keeps running meanwhile.
2. **On the old host:** stop the app and the vault, make one last backup, and switch everything off. The app goes first, so nothing changes after the backup. Leave `vaultwarden` out of the first command if you don't use the vault.

   ```bash
   docker/compose.sh stop app queue queue-checks scheduler vaultwarden
   sudo systemctl start herdbook-backup.service
   journalctl -u herdbook-backup --since "15 min ago" | grep -F 'Backup complete.'    # must show that line
   sudo systemctl disable --now herdbook-backup.timer herdbook-stack.service
   docker/compose.sh down
   ```

3. **Move the backup over:** the repository and `/etc/herdbook`. The archive contains the restic password, so it is readable for you only (`umask 077`), and removed everywhere afterwards.

   ```bash
   # on the old host
   repo="$(sudo sh -c '. /etc/herdbook/backup.env && echo "$RESTIC_REPOSITORY"')"
   sudo sh -c "umask 077; tar -C / -cf /tmp/herdbook-move.tar etc/herdbook ${repo#/}"
   sudo chown "$USER": /tmp/herdbook-move.tar
   # from your own machine
   scp -3 <old-host>:/tmp/herdbook-move.tar <new-host>:/tmp/
   # on the new host
   sudo tar -C / -xf /tmp/herdbook-move.tar && rm /tmp/herdbook-move.tar
   # on the old host
   rm /tmp/herdbook-move.tar
   ```

   - The archive is as large as the repository: make sure `/tmp` has room, or use a USB drive.
   - Is the repository on a second disk? Move the disk itself, and only transfer `/etc/herdbook`.
   - Is the backup on a NAS? Leave out `${repo#/}` and only transfer `/etc/herdbook`.

4. **The address:** shut the old host down (`sudo poweroff`). In your router, move the DHCP reservation (the fixed address) to the new host and reboot it (`sudo reboot`). Check on the new host with `ip -4 -br addr` that it got the address. That way `LAN_IP`, `APP_URL`, your local DNS names and any firewall rules in the router stay right. On your own machine, run `ssh-keygen -R <address>`: another machine answers there now.
5. **Follow [Disaster Recovery](#disaster-recovery) from step 3:** restore the files and the dump, the Tailscale nodes, MySQL, `deploy.sh` and the timer.
   - Did the new host get another address after all? Then change `LAN_IP` in `docker/box.env` before `deploy.sh`, and where needed `APP_URL` in `.env`, the DNS names in your router and your firewall rules.
   - Did you filter on the old host itself (the `DOCKER-USER` block in `/etc/ufw/after.rules`, see [Who Can Reach It](networking.md#who-can-reach-it))? Set it up on the new host too: it isn't part of the backup.
   - Caddy's CA comes along, so your devices trust Herdbook again straight away.
6. **Check** that you can sign in, that the checks run (the dashboard), and with the `lan` profile the [verification](networking.md#verifying-the-local-network). The morning after, check the first nightly backup:

   ```bash
   systemctl list-timers herdbook-backup.timer                          # LAST: last night
   journalctl -u herdbook-backup --since yesterday | grep -F 'Backup complete.'
   ```

7. **Clean up the old host**, only once the new one runs. It still holds `APP_KEY` and the MySQL passwords. Start it again (your router shows its new address), then:

   ```bash
   sudo systemctl disable herdbook-backup.service herdbook-backup-failed.service
   docker volume ls -q --filter name=herdbook_ | xargs -r docker volume rm
   sudo rm -rf /srv/herdbook /srv/herdbook-backup /srv/herdbook-data /etc/herdbook
   sudo tailscale logout                                  # if the host ran Tailscale
   ```

   Then remove the old host from the Tailscale admin console.

<a name="going-back"></a>
### Going Back

If the new host doesn't work out (before step 7): shut it down, give the address back to the old host and start that. Turn its units on again with the `systemctl link` and `enable` commands from [Starting at Boot](deployment.md#starting-at-boot) and [Setting Up](#setting-up) (`disable` removed the links too), and run `docker/up.sh`. If you already removed the old Tailscale nodes, put new [auth keys](networking.md#auth-keys) in `docker/box.env` first. Whatever the new host recorded in the meantime is lost.
