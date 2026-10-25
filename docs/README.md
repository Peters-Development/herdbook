# Herdbook Documentation

Herdbook is a self-hosted logbook for the applications, servers and domains you look after: what runs where and for whom, whether it is up, what went wrong last time, what needs renewing and what it all costs. It runs on a machine of your own, on a private network, for one team.

These pages explain how to get Herdbook running, how to use it day to day, and how it is built. If you are new, start with [Installation](installation.md) to try it locally with demo data, then read [Inventory](inventory.md): the project is the center of everything else, and the rest of the guide builds on it.

## Getting Started

- [Installation](installation.md): try Herdbook locally, create the first account.
- [Deployment](deployment.md): run it for real on a Linux host, update and roll back.
- [Networking](networking.md): reach it over Tailscale, on your local network, or both.
- [Configuration](configuration.md): the settings in `.env` and `config/herdbook.php`.
- [Backups](backups.md): nightly restic backups, restore tests, moving to another machine.
- [Vaultwarden](vaultwarden.md): an optional password vault next to Herdbook, for the secrets it refers to.

## Using Herdbook

- [Inventory](inventory.md): projects, environments, servers, networks, domains, hostnames, providers and clients.
- [Monitoring](monitoring.md): checks, thresholds, and how Herdbook decides whether a project is healthy.
- [Alerts](alerts.md): Pushover, ntfy and email, the weekly renewal digest, and who watches Herdbook itself.
- [Incidents](incidents.md): incident reports, timelines, follow-up actions, draft incidents and importing old reports.
- [Knowledge](knowledge.md): documents with revisions, and references to credentials.
- [Maintenance](maintenance.md): logging and planning updates, reboots and upgrades.
- [Renewals](renewals.md): expiry dates of domains, server contracts, subscriptions and internet lines.
- [Costs](costs.md): what everything costs per year and per month, and for which project or client.
- [Dashboard and Map](dashboard.md): the dashboard, the status page of a project, and the map.
- [Status Screen](status-screen.md): a wall display for a TV or a Raspberry Pi.

## Reference

- [Commands](commands.md): every Artisan command you are likely to run.
- [Security](security.md): the design choices that keep Herdbook and what it knows safe.
- [Translations](translations.md): how the interface is translated, and how to add a language.
- [Development](development.md): local development, tests, architecture and conventions.

Contributions are welcome: see [CONTRIBUTING.md](../CONTRIBUTING.md). To report a security issue, see [SECURITY.md](../SECURITY.md).
