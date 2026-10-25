# Monitoring

- [Introduction](#introduction)
- [How a Check Runs](#how-a-check-runs)
- [Check Types](#check-types)
    - [HTTP](#http)
    - [HTTP With Keyword](#http-with-keyword)
    - [Certificate](#certificate)
    - [TCP Port](#tcp-port)
    - [DNS](#dns)
    - [Ping](#ping)
- [Turning On Monitoring](#turning-on-monitoring)
- [Creating a Check by Hand](#creating-a-check-by-hand)
- [Check States and Thresholds](#check-states-and-thresholds)
- [The Connectivity Guard](#the-connectivity-guard)
- [DNS Expectations](#dns-expectations)
- [Redirects](#redirects)
- [Names on the Local Network](#names-on-the-local-network)
- [Checks Follow the Inventory](#checks-follow-the-inventory)
- [Health States](#health-states)
    - [Which Checks Count](#which-checks-count)
    - [The Rules](#the-rules)
    - [Servers and Hostnames](#servers-and-hostnames)
- [Hour Bars, Uptime and Response Time](#hour-bars-uptime-and-response-time)
- [Stale Checks and Stalled Monitoring](#stale-checks-and-stalled-monitoring)
- [History and Retention](#history-and-retention)

<a name="introduction"></a>
## Introduction

Herdbook watches your sites and servers itself: HTTP pages, certificates, TCP ports, DNS records and pings. A check belongs to a hostname or to a server, and through those to an environment and a project, so an outage is never just "a URL is down" but "Acme Shop's production is down, and this is where it runs".

Two ideas shape how monitoring behaves. The first is that **false alarms are worse than no alarms**: a check needs several failures in a row before it counts as down, and before any failure counts, Herdbook makes sure its own internet connection works. The second is that **there is one definition of "broken"**: the dashboard, the map, the status page and the status screen all ask the same question, answered in one place, as described under [Health States](#health-states).

What happens when a check goes down (who gets told, and how) is in [Alerts](alerts.md). A long outage of production can open an incident by itself: see [Draft Incidents From Checks](incidents.md#draft-incidents-from-checks).

<a name="how-a-check-runs"></a>
## How a Check Runs

The scheduler hands every check that is due to the queue once a minute. HTTP checks run concurrently in batches; the others run one at a time, each with a hard time limit, so one hanging target cannot hold up the rest. A dedicated queue worker does nothing but checks.

Each result is stored as soon as it is in, and moves the check's state along (see [Check States and Thresholds](#check-states-and-thresholds)). Only a switch between up and down becomes an *event*. A separate scheduled task sends alerts for new events every minute, and another one opens draft incidents for long outages.

A new check, or one whose type, target or settings changed, runs at the next round, not after its interval. **Check now** on a check runs it right away, even if it ran a moment ago, and shows the result.

<a name="check-types"></a>
## Check Types

| Type | Target | Up when | Default interval | Down after | Up again after |
|---|---|---|---|---|---|
| HTTP | `https://shop.example.com/` | 2xx (or the expected status) | every minute | 3 failures | 2 successes |
| HTTP with keyword | `https://shop.example.com/` | as HTTP, and the keyword is on the page | every minute | 3 | 2 |
| Certificate | `shop.example.com:443` | the certificate is valid for longer than the warning period | every 6 hours | 2 | 1 |
| TCP port | `db.example.com:3306` | a connection opens | every minute | 3 | 2 |
| DNS | `shop.example.com` | the name resolves, to the expected addresses | every 5 minutes | 2 | 1 |
| Ping | `203.0.113.10` | one echo comes back | every minute | 3 | 2 |

The defaults are filled in when you choose a type on a new check; you can change them. The interval can be every minute, 2, 5 or 15 minutes, every hour, every 6 hours or every day. Certificate and DNS answers are rarely flaky, so they go down after two failures and come back after one good result: with a six-hour interval, waiting for two good results would keep a fixed problem red for hours.

<a name="http"></a>
### HTTP

Herdbook requests the URL with a `GET`, with a total timeout of 10 seconds (5 for connecting) and the user agent `herdbook-monitor`. It follows up to three redirects. The check is up when the final answer is a 2xx; if a redirect chain does not end within three steps, the final 3xx counts as down, because a loop is not a working page.

With an **expected status code**, the answer must be exactly that code. An expected 3xx is checked as such, without following the redirect. To check that a name redirects to the right place, use **Must redirect to** instead: see [Redirects](#redirects).

The URL must start with `https://` or `http://`, have a valid port, and contain no username or password: secrets do not go into Herdbook, not even in a URL.

The certificate must be valid and trusted, or the request fails with a TLS error. **Accept any certificate** skips that verification, for a device with a self-signed certificate or one of a CA of your own, such as Caddy's on the local network: the page must still answer. See [Names on the Local Network](#names-on-the-local-network).

<a name="http-with-keyword"></a>
### HTTP With Keyword

The same as HTTP, plus a **keyword** that must appear in the page (case does not matter). Use it for pages that return 200 even when they are broken, such as an application that renders its own error page.

<a name="certificate"></a>
### Certificate

Herdbook opens a TLS connection to the host and port (443 when you leave the port out) and reads the expiry date of the certificate it presents. The check goes down when the certificate expires within **Start warning** days (14 by default), or has expired, or when no TLS connection can be made.

There is deliberately no separate "critical" threshold: certificates from Let's Encrypt and similar services are normally renewed 30 days before they expire, so one that gets within 14 days means renewal has failed, and that is what the warning is for. The check looks at the expiry date only, not at whether the chain is trusted.

A certificate that is about to expire is a warning, not an outage: the site still answers. See [Health States](#health-states).

<a name="tcp-port"></a>
### TCP Port

Opens a TCP connection to `host:port` within 5 seconds. Up when the connection opens; nothing is sent. Good for databases, mail servers, SSH, or anything else without an HTTP endpoint. IPv6 addresses go in brackets: `[2001:db8::10]:22`.

<a name="dns"></a>
### DNS

Looks up the A and AAAA records of the name at a public resolver (`1.1.1.1`), not at the host's own resolver: you get the answer the rest of the world gets. The check is down when the name has no address at all, or when it points to an address that is not expected. What is expected is described under [DNS Expectations](#dns-expectations). Without any expectation, the check is up as long as the name resolves.

Like a certificate check, a DNS check that is down is a warning, not an outage.

<a name="ping"></a>
### Ping

Sends one ICMP echo to the host or IP address and waits up to 5 seconds for the reply. Up when it comes back. Server checks are usually pings or TCP ports.

<a name="turning-on-monitoring"></a>
## Turning On Monitoring

**Turn on monitoring** on a hostname creates sensible default checks for it in one go:

| Check | Target | Alert |
|---|---|---|
| HTTP `shop.example.com` | `https://shop.example.com/` | Critical when the hostname points to a production environment; otherwise Normal |
| Certificate `shop.example.com` | `shop.example.com:443` | Normal |
| DNS `shop.example.com` | `shop.example.com` | Normal. Not created when the hostname is behind a proxy. |

A hostname that is **only on the local network** gets the HTTP check alone, one that accepts any certificate: see [Names on the Local Network](#names-on-the-local-network).

For a hostname that [redirects](inventory.md#hostnames) elsewhere, the HTTP check becomes a check of the redirect ("Redirect www.shop.example.com") with a Normal alert: the site it redirects to has its own critical check, so a server that goes down wakes you once, not once per name.

It is a button, not a setting: it creates the checks once, and you can change or delete them afterwards like any other. Pressing it again only adds the types the hostname does not have yet; existing checks stay as they are. Wildcard hostnames cannot be monitored.

<a name="creating-a-check-by-hand"></a>
## Creating a Check by Hand

Under **Inventory → Checks**, or from the checks tab of a hostname or server. A check belongs to exactly one hostname or one server. When you pick a hostname, the target is filled in for the chosen type; when you change the type, the target is reshaped to fit (`https://host/` for HTTP, `host:443` for a certificate, and so on). Leave the name empty and it becomes the type plus the host, such as "Ping 203.0.113.10".

The **Alert** level decides who hears about it: *Critical (wakes you up via Pushover)*, *Normal*, or *Silent (only shown in Herdbook)*. See [Alerts](alerts.md#alert-levels). A new check is Normal unless you choose otherwise.

**Pause** stops a check without losing its history; **Resume** starts it again. Both also work on a selection in the list. The menu shows how many active checks are down.

<a name="check-states-and-thresholds"></a>
## Check States and Thresholds

A check is in one of four states:

| State | Meaning |
|---|---|
| Not known yet | No result yet. |
| Up | Working. |
| Possibly down | It failed, but not yet often enough in a row to count as down. |
| Down | It failed **Down after** times in a row (3 by default). |

The rules:

```
Not known yet / Up ── 1st failure ──> Possibly down ── "Down after" failures in a row ──> Down
Down ── "Up again after" successes in a row ──> Up
```

A single success while *Possibly down* puts the check straight back to *Up*. A check that is *Down* stays down until it has had **Up again after** good results in a row (2 by default); a failure in between starts the count again. This keeps a flapping site from sending an alert every minute.

Only a switch between **Up and Down** is an event: that is what alerts and incidents start from. *Possibly down* is visible in the panel and on the dashboard, but never sends anything. A brand-new check whose first results are failures does count: going from *Not known yet* to *Down* is an event too.

While a check is not up, it is retried within five minutes, even when its interval is longer. A certificate check with a six-hour interval that fails once is confirmed (or cleared) within minutes, not hours.

The check page shows the current state and since when, the last response time and error, and the uptime over the last 24 hours, 7 days and 30 days. Its **Status changes** tab lists every switch between up and down, with the error, whether the alert went out, and the incident it opened or joined.

<a name="the-connectivity-guard"></a>
## The Connectivity Guard

When Herdbook runs on a machine at home or in an office, its own internet line is the most likely thing to fail. Without a guard, a dropped line would turn every check red at once and page everyone.

So before any failure counts, Herdbook asks "is it them, or is it us?". It requests a couple of well-known URLs (by default Cloudflare's `https://one.one.one.one/cdn-cgi/trace` and Google's `https://www.google.com/generate_204`). If any of them answers, with any status, name resolution, TCP and TLS of its own line work, and the failure is real. If none answers, the line is down: **nothing is recorded and nothing is sent**, and a warning goes to the log. The "offline" verdict is remembered for 30 seconds; a working line is not cached, so a line that just dropped is noticed at the next failure.

Alerts use the same guard: an alert that cannot be delivered while the line is down is held back and tried again, without counting as a failed attempt.

The reference URLs are set in `config/herdbook.php` (`checks.reference_urls`), see [Configuration](configuration.md).

<a name="dns-expectations"></a>
## DNS Expectations

A DNS check compares the answer with what the inventory says, so a migration that forgot a DNS record shows up by itself. The expected addresses are, in this order:

1. The hostname's own **Expected IP addresses**, when it has any.
2. Otherwise, the public IPv4 and IPv6 addresses of the servers with the role *App* or *Proxy* in the environment the hostname points to.
3. Nothing, when the hostname is **behind a proxy** (the record points at the proxy, not at your server) or when no addresses are known.

The comparison is per address family. If you expect only IPv4 addresses, AAAA records are not this check's business, and the other way around. The check goes down when the name resolves to an address that is not expected; a name that resolves to fewer of the expected addresses (one of two app servers) is still up.

<a name="redirects"></a>
## Redirects

A name such as `www.shop.example.com` often exists only to send visitors to `shop.example.com`. Checking it as a page would test the target site twice, and would not notice when www suddenly points at a parking page.

When a hostname has **Redirects to** set, its default HTTP check gets **Must redirect to** with the same name. Such a check does not follow the redirect. It is up when the answer is a 3xx whose `Location` points to that name (a relative `Location` stays on the same name). With an expected status code, that code must match too, and it must be a 3xx.

Existing HTTPS checks on a hostname follow its **Redirects to** field: set, change or clear it, and the HTTP checks on that name get the same expectation. Their name and alert level follow too, as long as they are still the defaults: a www name that was already monitored stops sending emergency alerts when you mark it as a redirect, and gets them back when you clear it. Checks you set up differently by hand are left alone: another redirect target, an expected status that is not a redirect, or a check over plain `http://` (which often redirects to https on the same name first).

The response time of a redirect check measures only the redirect, so it is left out of the response times on the dashboard and the status page.

<a name="names-on-the-local-network"></a>
## Names on the Local Network

Checks run on the host that runs Herdbook, so they reach whatever that host reaches, your local network included. A machine in the office is watched with a ping or a TCP port on its private address, just like a VPS on its public one, and its checks count for the environments that run on it.

A name that exists only in your local DNS, such as `nas.home.arpa`, needs a different set of checks. Turn on **Only on the local network** on the hostname (every name under a [local domain](inventory.md#domains) has it already), and **Turn on monitoring** creates just the HTTP check, set to **Accept any certificate**:

- Such a name usually has a self-signed certificate, or one of a CA of your own: Caddy's, or that of a NAS or a router. Your devices trust it, the host does not, so an ordinary HTTP check would fail with a TLS error.
- There is no certificate check. Caddy's own CA issues certificates that last about half a day and renews them by itself, so even the shortest warning period would keep the check red. A device with a certificate that does last can get a certificate check by hand: it reads the expiry date only, not whether the certificate is trusted.
- There is no DNS check. It asks a public resolver, which does not know the name.

A new HTTP check you create for a local hostname starts with **Accept any certificate** on. Checks that already exist follow the setting in part: when you mark the name local, its HTTP checks whose target is exactly the name (not those on an address) accept any certificate, and when you unmark it, they verify the certificate again, so a name that went public is not left unverified. Its certificate and DNS checks stay as they are: if you monitored the name before marking it local, delete them.

Herdbook's containers must be able to look the name up. They ask the DNS servers the host uses (your router, say), not the host's own hosts file. If they cannot, put the address in the target instead, such as `https://192.168.1.10/`.

This is also how Herdbook watches its own way in on the local network: add a name from `LAN_HOSTNAMES` as a hostname of the Herdbook project (see [Networking](networking.md#local-dns)). If `LAN_PORT` is not 443, put the port in the check's target, such as `https://herdbook.home.arpa:8443/`. The check notices when Caddy or the web server stops answering while the checks still run. A host that goes down as a whole cannot report itself: that is what the [heartbeat](alerts.md#heartbeat) is for.

If that check times out while the page loads fine on your devices, the host's firewall may keep its own containers away from `LAN_IP`. Try it from the container that runs the checks: `docker exec herdbook-queue-checks curl -sk -o /dev/null -w '%{http_code}\n' https://<LAN_IP>/up` (with `:<LAN_PORT>` after the address if it is not 443) should print `200`.

<a name="checks-follow-the-inventory"></a>
## Checks Follow the Inventory

Checks keep in step with what you change in the inventory, so you do not have to remember to update them:

| You change | What happens to the checks |
|---|---|
| Rename a hostname | Checks whose target is exactly the old name probe the new one. A rename to a wildcard pauses them. |
| Mark a hostname *Only on the local network*, or unmark it | Its HTTP checks whose target is exactly the name accept any certificate while it is local, and verify it again after. |
| Mark a domain *Only on the local network*, or unmark it | Its hostnames become local, or no longer local, with the same effect on their checks. |
| Change a server's address | Checks that probe the old address move to the new one. |
| Set a server to *Decommissioned* | Its own checks are paused. |
| Archive a project | The checks of its hostnames are paused. |
| Delete a project or an environment | The checks of the hostnames left behind are paused. |
| Pause or delete a critical check that is down | Its repeating Pushover emergency alert stops. |

Nothing switches checks back on by itself: some may have been paused on purpose.

<a name="health-states"></a>
## Health States

The dashboard tiles, the projects list, the status page, the map and the status screen all show how a project or environment is doing, and they all get the answer from the same place. A **project's** state is the state of its **production**: all of its environments of the kind *Production* together.

<a name="which-checks-count"></a>
### Which Checks Count

For an environment, these checks count:

- the checks of the hostnames that point to it;
- the checks of the servers it runs on;
- the checks of the servers those servers depend on, directly or through a chain.

The last rule is what makes [dependencies](inventory.md#dependencies-and-impact) useful: when `storage-1` stops answering pings, a project whose production runs on `app-2`, which mounts `storage-1` over NFS, shows the outage too, even though its own website check may not have noticed yet.

<a name="the-rules"></a>
### The Rules

The rules are applied from top to bottom; the first one that fits wins.

| State | When |
|---|---|
| Not monitored | There are no checks at all. A gap: something in production that nobody watches. |
| No monitoring needed | There are no checks, and the project says it **does not need monitoring**. |
| Paused | There are checks, but all of them are paused. |
| Outage | An active availability check (HTTP, HTTP with keyword, TCP port or ping) is *Down*. |
| Needs attention | An active certificate or DNS check is *Down*, or an active check is *Possibly down*, or has [no recent result](#stale-checks-and-stalled-monitoring). |
| Healthy | At least one active check is *Up*, and none of the above applies. |
| No data yet | There are active checks, but none of them has a result yet. |

A certificate that expires in 13 days, or a DNS record that points somewhere unexpected, is *Needs attention*, never *Outage*: the site still answers. Paused checks never count, except to tell *Paused* from *Not monitored*.

For *Outage* and *Needs attention*, Herdbook also says **since when**: the moment the oldest of the problems started (its last state change, or for a check without recent results, its last result). The tile and the status page show the first problem with its error.

The project status *Maintenance only* changes nothing here: it means the project is only looked after, not that maintenance is going on. If such a project goes down, that is an outage.

<a name="servers-and-hostnames"></a>
### Servers and Hostnames

On the [map](dashboard.md#the-map), servers and hostnames have a state of their own, from their own checks only, with the same rules. A server's state does not include the servers it depends on: the map shows those as separate nodes with their own color. A decommissioned server is gray. A domain shows its [renewal status](renewals.md#statuses) instead.

<a name="hour-bars-uptime-and-response-time"></a>
## Hour Bars, Uptime and Response Time

The hour bars on a tile cover the last 24 clock hours, the running hour last. Each bar is the share of good results of the **worst** active availability check in that hour. Averaging the checks would show a site that was down for an hour next to a ping that answered as a 50% hour; taking the worst shows it as the bad hour it was.

A bar is green from 98% good results, orange from 50%, and red below that. A single failed result in an hour of minute checks still reads as green. A bar without results is gray.

The **uptime** is the average of the bars that have results. Certificate and DNS checks are left out of both, since they do not measure whether the site answers.

The **response time** is the average of the HTTP checks that are up, each averaged over its good results of the last hour: a single measurement jumps from minute to minute. Redirect checks are left out.

The [status page](dashboard.md#the-project-status-page) of a project shows the same bars over 7, 30 or 90 days.

<a name="stale-checks-and-stalled-monitoring"></a>
## Stale Checks and Stalled Monitoring

Two safeguards catch monitoring that has quietly stopped:

- **No recent result.** An active check without a result for three of its rounds (plus two minutes) is *stale*: its state is old news. That happens when the queue worker, the scheduler or the internet line is in trouble. A stale check counts as *Needs attention* and is listed on the dashboard.
- **Checks have stalled.** When no check at all has produced a result within five minutes (or within the shortest active interval plus two minutes, if all checks run less often), the dashboard says so, the status screen says so, and the heartbeat is withheld, so whatever watches Herdbook from outside notices. See [Watching the Watcher](alerts.md#watching-the-watcher).

<a name="history-and-retention"></a>
## History and Retention

Herdbook keeps three layers of history:

| Layer | Kept | Used for |
|---|---|---|
| Raw results | 14 days | the hours not rolled up yet, the errors behind a bar on the status page, the 24-hour uptime on a check's page |
| Hourly statistics (good, failed, average/min/max response time) | 90 days | the hour bars, the 7-, 30- and 90-day views, the 7- and 30-day uptime |
| Daily statistics | for good | kept as a record; not shown in the panel |

Every hour, at five past, the results of the previous full hours are rolled up into hourly statistics and those into daily ones. If the scheduler or the database was down for a while, the next run catches up on the hours it missed. Running it again changes nothing. Old raw results and hourly statistics are removed nightly.

The retention periods are in `config/herdbook.php` (`checks.keep_results_days` and `checks.keep_hour_stats_days`). The status page cannot look back further than the hourly statistics go.
