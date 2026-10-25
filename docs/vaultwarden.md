# Vaultwarden

- [Introduction](#introduction)
- [Turning It On](#turning-it-on)
- [Creating Accounts](#creating-accounts)
- [The Admin Page](#the-admin-page)
- [Using the Vault](#using-the-vault)
- [Linking Credentials in Herdbook](#linking-credentials)
- [Backups](#backups)
- [Updating](#updating)

<a name="introduction"></a>
## Introduction

Herdbook never stores a password, a private key or a token. A credential in Herdbook is a **reference**: a label, a username, a host and the id of the item in your password vault. The secret itself stays in the vault, which is built to keep it.

If you don't have a vault yet, the production stack can run one next to Herdbook: [Vaultwarden](https://github.com/dani-garcia/vaultwarden), a lightweight server that works with the official Bitwarden apps and browser extensions. It is optional. If you already use Bitwarden or Vaultwarden elsewhere, skip to [Linking Credentials in Herdbook](#linking-credentials).

<a name="turning-it-on"></a>
## Turning It On

The vault has a profile of its own, `vaultwarden`, and is only ever reachable on your tailnet, at `https://vault.<tailnet>.ts.net`, also when Herdbook itself uses the `lan` profile. It runs in the network namespace of its own Tailscale sidecar (`ts-vault`), on a separate Docker network, so it can't reach Herdbook's PHP or MySQL.

You need:

1. The [tailnet settings](networking.md#tailnet-settings) (MagicDNS and HTTPS certificates), the tag `tag:vault` and its grant ([Tags and Grants](networking.md#tags-and-grants)).
2. An [auth key](networking.md#auth-keys) with `tag:vault`, as `TS_AUTHKEY_VAULT` in `docker/box.env`.
3. `TAILNET_DOMAIN` in `docker/box.env`: your tailnet's domain, `<tailnet>.ts.net`. Vaultwarden uses it for its own address.
4. `vaultwarden` in `COMPOSE_PROFILES`, for example `tailscale,vaultwarden`.

Then run `docker/up.sh`, and remove the auth key afterwards as for the app ([Removing the Auth Keys](deployment.md#removing-the-auth-keys)). The vault keeps its data in `DATA_DIR/vaultwarden`, which only root may read.

<a name="creating-accounts"></a>
## Creating Accounts

New accounts are refused by default. Open the door only while you create them:

1. Set `VAULT_SIGNUPS_ALLOWED=true` in `docker/box.env` and run `docker/up.sh`.
2. Create the accounts on `https://vault.<tailnet>.ts.net`, and turn on two-step login in each of them.
3. Set `VAULT_SIGNUPS_ALLOWED=false` and run `docker/up.sh` again.
4. Write the master password down on paper too. Without it, nobody can open the vault, not even from the backup.

<a name="the-admin-page"></a>
## The Admin Page

Vaultwarden's `/admin` page is off as long as `VAULT_ADMIN_TOKEN` in `docker/box.env` is empty, and you rarely need it. To turn it on, generate an argon2 hash of a token of your choice with the same image the stack uses:

```bash
docker run --rm -it vaultwarden/server:<tag> /vaultwarden hash
```

Use the tag from `docker/docker-compose.prod.yml`. The hash is full of `$` characters, so put it in **single quotes** in `box.env`, or compose mangles it:

```ini
VAULT_ADMIN_TOKEN='$argon2id$v=19$m=...'
```

Run `docker/up.sh` after changing it, and empty it again when you're done.

<a name="using-the-vault"></a>
## Using the Vault

Point the Bitwarden apps and browser extensions at your own server (`https://vault.<tailnet>.ts.net`) when you sign in. They keep an offline copy of the vault, so you can still read your passwords when the host is down: exactly the moment you may need them.

If you want to use SSH keys from the vault, turn on the SSH agent in the Bitwarden desktop app. The socket path for each operating system is in [Bitwarden's documentation](https://bitwarden.com/help/ssh-agent/).

Two settings in the stack are worth knowing: the vault sees the real client address of every sign-in attempt (so its rate limit works per device, not for everyone at once), and it doesn't download website icons for your items, so it doesn't contact every site you have a login for.

<a name="linking-credentials"></a>
## Linking Credentials in Herdbook

Set `HERDBOOK_VAULT_URL` in `.env` to the address of your web vault:

```ini
HERDBOOK_VAULT_URL=https://vault.<tailnet>.ts.net
```

Then recreate the app containers ([Changing Settings](deployment.md#changing-settings)). Every credential reference with an item id then gets a link that opens its item in the web vault, in the Bitwarden web vault's format (`<HERDBOOK_VAULT_URL>/#/vault?itemId=<id>`). Vaultwarden serves the same web vault, so a vault hosted elsewhere works the same way as long as you set its address here. Leave the setting empty and the references show no links.

To link a reference to its item, open the item in the web vault and copy the link from the address bar into the reference's **Vaultwarden item** field. Herdbook takes the item id from the link; a bare item id works too. [Credential References](knowledge.md#credential-references) covers them in the app.

> [!NOTE]
> Herdbook refuses text that looks like a private key in its free-text fields, such as notes and documents. Keys and passwords belong in the vault. See [Security](security.md#no-secrets-in-the-database).

<a name="backups"></a>
## Backups

When the `vaultwarden` profile is on, the nightly [backup](backups.md) includes the vault. It first makes a consistent copy of the vault's SQLite database (`db-backup.sqlite3`, through SQLite's online backup), then backs up the whole data directory: the copy, the attachments, the keys and the configuration. The live database files are left out, because a copy taken while they change could be broken.

On a [restore](backups.md#disaster-recovery), rename `db-backup.sqlite3` back to `db.sqlite3`, or the vault starts empty. The [restore test](backups.md#restore-test) checks the copy and, from a NAS, starts it in a throwaway container.

The backup can't help you without the master password: keep it [offline](backups.md#keeping-the-keys-offline).

<a name="updating"></a>
## Updating

The Vaultwarden version is pinned in `docker/docker-compose.prod.yml`, so it changes with a Herdbook update, and `docker/deploy.sh` pulls it. Read [Vaultwarden's release notes](https://github.com/dani-garcia/vaultwarden/releases) when the version changes.
