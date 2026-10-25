# Security

- [Introduction](#introduction)
- [A Private Network](#a-private-network)
- [Accounts and Sign-In](#accounts-and-sign-in)
- [No Secrets in the Database](#no-secrets-in-the-database)
- [What Users Write](#what-users-write)
- [Browser Protection](#browser-protection)
- [The Status Screen](#the-status-screen)
- [Alerts](#alerts)
- [What Checks Can Reach](#what-checks-can-reach)
- [Backups](#backups)
- [What Herdbook Does Not Protect Against](#what-herdbook-does-not-protect-against)
- [Reporting a Vulnerability](#reporting-a-vulnerability)

<a name="introduction"></a>
## Introduction

Herdbook holds a map of your infrastructure: which servers you run, which applications live on them, where you sign in and what breaks when something goes down. That makes it valuable to an attacker even though it holds no passwords. This page explains how Herdbook protects that knowledge, and where it deliberately relies on your network and your password manager instead.

<a name="a-private-network"></a>
## A Private Network

Herdbook is meant to be reached from a private network only, and the production stack is built that way:

- **With the `tailscale` profile, no port is published at all.** nginx listens on the loopback of its Tailscale sidecar; `tailscale serve` is the only way in, and Funnel (publishing to the internet) is switched off in its configuration. Tailscale grants decide who may reach the node ([Tags and Grants](networking.md#tags-and-grants)).
- **With the `lan` profile, HTTPS is published on one address**, `LAN_IP`, with certificates from Caddy's own certificate authority. Never plain HTTP: the password, the second factor and the session cookie would cross the network readable. `docker/compose.sh` refuses `0.0.0.0`, which would publish on every network of the host. Who on your network may reach it is up to your VLANs or the `DOCKER-USER` chain ([Who Can Reach It](networking.md#who-can-reach-it)).
- **The node names are neutral** (`herdbook` and `vault`), because the names of Tailscale's certificates end up in public Certificate Transparency logs ([Machine Names](networking.md#machine-names)).
- **The vault has its own Docker network**, so it can't reach PHP or MySQL.
- **There is no database tool** in the stack, and MySQL publishes no port.

Herdbook doesn't use the identity headers that `tailscale serve` adds: being on the tailnet never signs you in.

<a name="accounts-and-sign-in"></a>
## Accounts and Sign-In

- **No sign-up.** Accounts are created on the command line with `users:create`, never through the web.
- **Two-factor authentication is required** for every account (TOTP, with recovery codes). It is enforced on every request, Livewire's included, so a page opened before two-factor was turned off stops working too. The secrets and recovery codes are encrypted with `APP_KEY`.
- **Passwords** are at least 12 characters, and stored as a hash. Sign-in attempts are limited to five a minute. There is no password reset by email: a lost password or second factor is reset on the command line ([Accounts](deployment.md#accounts)).
- **Resetting two-factor signs the account out everywhere**, every session and every "remember me" cookie, so a lost phone that is still signed in can't set up the new authenticator.
- **Every account is an administrator.** There are no roles or permissions: anyone with an account can see and change everything. Give accounts only to people you would give the keys to your infrastructure.
- **Sessions** live in the database, encrypted, and the cookie is secure on the host (`SESSION_SECURE_COOKIE=true`).
- **An activity log** records changes on every record, and who made them.

<a name="no-secrets-in-the-database"></a>
## No Secrets in the Database

Herdbook stores no passwords, private keys or tokens for the things it describes. A credential is a **reference**: a label, a username, a host, and the id of the item in your password vault ([Vaultwarden](vaultwarden.md)).

Why not store them encrypted? Encryption inside the app only protects against a leaked database dump. Against a compromised host it protects nothing, because the key sits right next to the data. A dedicated, zero-knowledge vault is the better place.

To keep secrets from slipping in anyway, every free-text field (notes, documents, incident reports, credential fields) refuses text that looks like a **private key**: a PEM block with `PRIVATE KEY` in its header, or a PuTTY key file. Public keys and certificates pass.

<a name="what-users-write"></a>
## What Users Write

Documents, incidents and notes are markdown. Herdbook never renders that markdown as raw HTML: inside the panel, Filament sanitizes the HTML; elsewhere, raw HTML is stripped and unsafe links (such as `javascript:`) are removed. Error messages shown to users are friendly text, never SQL, table names or stack traces, and `APP_DEBUG` is off on the host.

<a name="browser-protection"></a>
## Browser Protection

Every response, the status screen and its API included, carries these headers:

| Header | Value and why |
|---|---|
| `Content-Security-Policy` | Scripts, styles, fonts and requests only from Herdbook's own origin. No framing of Herdbook by any site, and forms and `<base>` can't point elsewhere. Images may come over HTTPS, because markdown can link to one. |
| `X-Frame-Options` | `DENY`, for browsers that predate `frame-ancestors`. |
| `X-Content-Type-Options` | `nosniff`. nginx adds it to the static files too. |
| `Referrer-Policy` | `same-origin`: a link to a repository or another tool doesn't tell that site your Herdbook address. |
| `Permissions-Policy` | Camera, microphone, geolocation, payment and USB off. |
| `Cross-Origin-Opener-Policy` | `same-origin`. |
| `Strict-Transport-Security` | One year, only over HTTPS (not on the local `http://localhost`). |

**Nothing comes from third parties.** Fonts, icons and avatars are served by Herdbook itself, so the policy can stay this strict. nginx hides its version.

The policy does allow inline scripts and `eval`, because Filament, Livewire and Alpine need them. It still blocks scripts from any other origin; protection against injected script rests on escaping everything users write.

<a name="the-status-screen"></a>
## The Status Screen

The [status screen](status-screen.md) is the one page that needs no sign-in, so it gets its own rules:

- **A token per display.** 48 random characters, shown once by `screens:create`. The database only keeps its SHA-256 hash, so a leaked dump opens no screen. `screens:revoke` takes it away.
- **The token never reaches a log.** It sits in the address after the `#`, which browsers don't send to the server, and the page sends it as a Bearer token.
- **No session and no forms.** The screen's routes sit outside the panel's middleware, and it only reads.
- **Rate limits.** 20 requests a minute per screen, and a separate limit for wrong tokens, which never blocks a good one.
- **A payload built field by field.** Project names and states, hourly bars, uptime, response times, the severity of open incidents, whether the checks run and the backup's age. Never hostnames, IP addresses, check names, incident titles or links: visitors can read along. A test guards this.
- **Text goes in as text.** The page never inserts HTML.

<a name="alerts"></a>
## Alerts

Alerts go to Pushover, ntfy or your SMTP server, which are outside your network, so they stay **bare**: what changed, its state and the time, plus a link back to Herdbook. Names still travel, though: a check's name often contains a hostname, and the renewal digest lists domains with their expiry dates. On ntfy.sh, anyone who knows a topic's name can read it: use a long random topic, a reserved one with a token, or your own server ([Configuration](configuration.md#alerts)).

<a name="what-checks-can-reach"></a>
## What Checks Can Reach

A check can request anything the host can reach, including addresses on your local network and your tailnet. That is deliberate: monitoring internal services is part of the job. It is acceptable because only administrators with two-factor authentication create checks, and a check only reports a state and a response time, never the content of what it fetched.

An HTTP check with **Accept any certificate** does not verify the certificate of what it requests ([Names on the Local Network](monitoring.md#names-on-the-local-network)). It sends nothing secret, so the most someone in between can do is make that check report up while the site is not.

If the containers shouldn't reach parts of your tailnet, limit that in the Tailscale grants of `tag:herdbook-host` ([Services on Your Tailnet](networking.md#services-on-your-tailnet)).

<a name="backups"></a>
## Backups

- **Encrypted** by restic before they leave the host.
- **Append-only** on a NAS: the host can add backups, not delete them. The NAS prunes, and refuses to while a snapshot is dated in the future ([On a NAS](backups.md#on-a-nas)).
- **Readable only with keys you keep offline**: the restic password and `APP_KEY`, on paper and in your password manager ([Keeping the Keys Offline](backups.md#keeping-the-keys-offline)).
- The settings files, the dumps and the archives made by the commands in these docs are created readable for root or for you only.

<a name="what-herdbook-does-not-protect-against"></a>
## What Herdbook Does Not Protect Against

Knowing the limits is part of the model:

- **A compromised host.** Whoever controls the host can read the database and `APP_KEY`, and change the app. Because credentials are references, they get your map, not your passwords.
- **Anyone with an account.** Every account is an administrator. There are no roles to limit what a careless or malicious user can see or change.
- **The open internet.** Herdbook is not built to face it. The headers above help, but there is no protection against floods or automated attacks beyond the sign-in limit, and the sign-in page is visible to anyone who can reach it.
- **Your local network, with the `lan` profile.** Everyone on the same network sees the sign-in page. Docker publishes ports past `ufw`.
- **The key of Caddy's certificate authority.** Whoever holds it can impersonate any website to the devices that trust its root. It lives on the host and in the backup ([Trusting the Root Certificate](networking.md#trusting-the-root-certificate)).
- **The NAS that prunes.** It holds the restic password, so it can read the backups.
- **Forged history on an append-only NAS.** A compromised host can't delete, but it could add snapshots dated in the past. The NAS's own filesystem snapshots are the safety net ([Filesystem Snapshots](backups.md#filesystem-snapshots)).
- **What your alert channels see.** Pushover, ntfy and your mail provider see the alerts.
- **A tailnet with a broad policy.** If your Tailscale policy lets everything reach everything, the grants don't restrict anything.

<a name="reporting-a-vulnerability"></a>
## Reporting a Vulnerability

Found a security issue? Please don't open a public issue. [SECURITY.md](../SECURITY.md) in the repository explains how to report it privately.
