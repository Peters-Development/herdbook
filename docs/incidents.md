# Incidents

- [Introduction](#introduction)
- [Writing an Incident Report](#writing-an-incident-report)
    - [Severity and Status](#severity-and-status)
    - [Times and Durations](#times-and-durations)
    - [Impact, Root Cause and Report](#impact-root-cause-and-report)
- [Timeline](#timeline)
- [Action Items](#action-items)
- [Linking an Incident](#linking-an-incident)
- [Draft Incidents From Checks](#draft-incidents-from-checks)
- [Importing Existing Reports](#importing-existing-reports)
    - [What the Importer Reads](#what-the-importer-reads)
    - [Running the Import](#running-the-import)

<a name="introduction"></a>
## Introduction

An incident is an outage or problem worth writing down: what happened, who noticed, how it was fixed, and what you will change so it does not happen again. Herdbook keeps those reports next to the projects and servers they are about, so "what did we do the last time this broke?" is one click away from the server that broke.

Incidents live under **Knowledge → Incidents**. You write them yourself, Herdbook opens a draft when a critical production check stays down, and you can import the markdown reports you already have in your repositories.

<a name="writing-an-incident-report"></a>
## Writing an Incident Report

Create an incident from **Knowledge → Incidents**, or from the incidents tab of a project, server, domain, network or client: then it is linked to that record from the start.

<a name="severity-and-status"></a>
### Severity and Status

| Severity | Use it for |
|---|---|
| SEV1 · critical | Production down, or data at risk. |
| SEV2 · major | Serious, but with a workaround or limited reach. The default. |
| SEV3 · minor | A small problem, written down so you remember it. |

| Status | Meaning |
|---|---|
| Open | Ongoing. |
| Watching | The problem seems fixed, and you are keeping an eye on it before closing. |
| Resolved | Done. |

Open and watching incidents are *unresolved*: they are listed on the dashboard and counted on the tiles of the projects they affect. The [status page](dashboard.md#the-project-status-page) of a project shows every incident of the chosen period on its timeline, resolved or not. The [status screen](status-screen.md) shows only open ones, and only their severity.

Every incident also has a **source**: *By hand*, *Imported* or *Check* (a draft opened by monitoring).

<a name="times-and-durations"></a>
### Times and Durations

The **date** is required: it is what incidents are sorted by. Besides that you can record when the incident **started**, when it was **detected** and when it was **resolved**. Detected and resolved cannot lie before the start.

From those times Herdbook shows two durations: how long the incident lasted (start to resolved) and how long it went unnoticed (start to detected). A long time to detect is often the most useful finding of a report: it tells you which check was missing.

<a name="impact-root-cause-and-report"></a>
### Impact, Root Cause and Report

- **Impact**: who noticed what, and for how long. Plain text.
- **Root cause**: why it happened. Markdown.
- **Report**: the full write-up. Markdown.

Markdown is never rendered as raw HTML, so a pasted or imported report cannot inject a script into the page. As everywhere in Herdbook, a private key pasted into any of these fields is refused: see [What Is Never Stored](knowledge.md#what-is-never-stored).

<a name="timeline"></a>
## Timeline

The **Timeline** tab of an incident is a list of events: when, what, and who wrote it down. Use it during an incident to keep track of what you tried, and afterwards to reconstruct what happened. Entries are sorted by time, so you can add one you forgot later. The time defaults to now.

Monitoring adds entries by itself to the draft incidents it opens, for example "Check HTTP shop.example.com went down: HTTP 503" and "Up again: HTTP shop.example.com".

<a name="action-items"></a>
## Action Items

The **Action items** tab holds the follow-ups: what has to change so this does not happen again ("alert on NFS latency", "move backups off the app server"). Each has a description and an optional due date. **Complete** marks one as done; **Reopen** takes that back.

Open action items of all incidents are listed on the dashboard, dated ones first, the earliest at the top. An item past its due date (counted in calendar days, in the display timezone) is shown in red, and can be completed from there.

<a name="linking-an-incident"></a>
## Linking an Incident

Under **Related to**, link an incident to the projects, servers, domains, networks and clients it was about. It then appears on each of their pages.

For the overviews, an incident counts towards a project when it is linked to:

- the project itself;
- a server that the project's production runs on, or that one of those servers depends on;
- a network in which such a server stands.

So an incident "Storage server unreachable" linked only to `storage-1` shows up on the tile of every project whose production needs `storage-1`, and "Office internet down" linked to the office network shows up on the tile of whatever runs on the machines there. This is the same reach as the [health of a project](monitoring.md#which-checks-count).

<a name="draft-incidents-from-checks"></a>
## Draft Incidents From Checks

When a **critical** check that watches **production** has been down for five minutes, Herdbook opens a draft incident:

- the title names the project and the check, such as "Acme Shop: HTTP shop.example.com unreachable";
- severity SEV1, status Open, source Check;
- the start is the first failed result of the streak, and the detection time is the moment the check switched to down, so the time to detect is right;
- the impact is the check's error, and the first timeline entry says that the check went down;
- it is linked to the affected projects and to the servers involved.

A check watches production when its hostname points to a production environment, or when it is a server check on a server that a production environment runs on, directly or through a [dependency](inventory.md#dependencies-and-impact). A failing storage server therefore opens an incident for the project on the app server that mounts it. Archived projects do not count.

**One incident per outage, not one per check.** If an unresolved check incident of the same project was detected within the last twelve hours, a new outage joins it instead: it gets a timeline entry ("Also down: …"), its projects and servers are added to the links, and an incident that was *Watching* goes back to *Open*. An incident nobody closed does not swallow next week's outage.

**Recovery.** When a check of the incident comes back up, a timeline entry says so. Once none of its checks is down any more, the incident moves to *Watching*. Closing it is up to you: fill in the root cause and what you learned, and set it to *Resolved*.

Each outage is considered once. A draft you delete does not come back, a check that recovered within the five minutes opens nothing, and paused checks never open incidents. The five minutes are `checks.incident_after_minutes` in `config/herdbook.php`.

The title and timeline entries are written in the default language of the installation (`APP_LOCALE`), since they are created by the scheduler, not by a person. See [Translations](translations.md#generated-text).

<a name="importing-existing-reports"></a>
## Importing Existing Reports

If you already write incident reports as markdown files in your repositories, `incidents:import` brings them into Herdbook. Running it again imports nothing twice, so you can import a directory again after adding new reports.

<a name="what-the-importer-reads"></a>
### What the Importer Reads

The importer expects reports shaped like this, but copes with variations:

```markdown
# Incident 2026-07-08 — Uploads failed after a storage stall

**Impact:** Customers could not upload files for about 40 minutes.

## Timeline
...
```

saved as `2026-07-08-storage-stall.md`.

| Field | Taken from |
|---|---|
| Title | The first `#` heading, without an "Incident YYYY-MM-DD — " prefix. Without a heading: the file name, without its date. |
| Date | The `YYYY-MM-DD-` prefix of the file name, or else the first date in the heading. A file without a date is skipped, with a message. |
| Impact | The paragraph after `**Impact:**`, as one line. Optional. |
| Report | The whole file, as it is. |

Every imported incident gets status *Resolved*, severity *SEV2 · major* and source *Imported*. Look them over afterwards and adjust the severity where needed: the command reminds you.

<a name="running-the-import"></a>
### Running the Import

The command runs inside the app container, which cannot see your other repositories. Copy the reports into Herdbook's `storage/app/import/` first, one directory per repository. Locally that is the `storage/app` directory of your checkout:

```bash
mkdir -p storage/app/import/acme-shop
cp ../acme-shop/docs/incidents/*.md storage/app/import/acme-shop/
```

In production, `storage/app` is the `app` folder in the data directory on the host (`DATA_DIR` in `docker/box.env`, see [Deployment](deployment.md)).

Then try it with `--dry-run`, which shows per file the title, the date, whether an impact was found and what would happen, and writes nothing:

```bash
php artisan incidents:import storage/app/import/acme-shop --source=acme-shop --project=acme-shop --dry-run
php artisan incidents:import storage/app/import/acme-shop --source=acme-shop --project=acme-shop
```

- `{directory}`: the directory with the reports. Only `*.md` files directly in it are read, not subdirectories.
- `--source=` (required): the repository the reports come from. Each incident remembers `source/file name`, which is how a second run knows what it already imported, and keeps reports with the same file name from different repositories apart.
- `--project=`: the slug of a project to link every imported incident to.
- `--dry-run`: show what would be imported, write nothing.

A file without a date is skipped with the advice to rename the copy to `YYYY-MM-DD-<name>.md`. See [Commands](commands.md) for how to run Artisan commands in your setup.
