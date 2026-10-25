# Dashboard and Map

- [Introduction](#introduction)
- [The Dashboard](#the-dashboard)
    - [The Status Band](#the-status-band)
    - [Project Tiles](#project-tiles)
    - [The Tables Below](#the-tables-below)
- [The Project Status Page](#the-project-status-page)
    - [Periods](#periods)
    - [The Timeline](#the-timeline)
    - [Clicking a Bar](#clicking-a-bar)
    - [Response Time, Per Check, and What Happened](#response-time-per-check-and-what-happened)
- [The Map](#the-map)
    - [Columns](#columns)
    - [States on the Map](#states-on-the-map)
    - [Pointing and Clicking](#pointing-and-clicking)
    - [Filters](#filters)

<a name="introduction"></a>
## Introduction

Herdbook has three ways of seeing everything at a glance. The **dashboard** answers "is anything wrong right now, and what needs me?". The **status page** of a project answers "how did production do over the last day, week or quarter?". The **map** answers "what is connected to what, and what falls over if this server goes down?".

All three take the state of projects, environments, servers and hostnames from the same place, so they never disagree. How that state is decided is explained in [Health States](monitoring.md#health-states).

For a display on the wall, see the [Status Screen](status-screen.md).

<a name="the-dashboard"></a>
## The Dashboard

The dashboard is the first page after signing in. From top to bottom: a band of numbers, a tile per project, and the tables where you act.

<a name="the-status-band"></a>
### The Status Band

Five numbers; the first four link to the list behind them. The band refreshes every 30 seconds.

| Number | Shows |
|---|---|
| Projects | How many projects have an outage or need attention, or *Healthy*, with "*x* of *y* healthy" below it. Projects that need no monitoring and have no checks are left out of the count: they are not a gap. |
| Checks up | Active checks that are up, out of all active checks, with what is down, how many certificate or DNS checks are failing, what is possibly down and what has no recent result. When the checks have stopped producing results altogether, it says since when. The link opens the down checks, when there are any. |
| Open incidents | How many incidents are open, the worst of them, and how many are being watched. |
| Renewals | How many renewals need attention, and which one is due first. The link opens that kind of item, filtered on what needs attention. |
| Backup | When the last successful backup ran, red when it is too old, gray when backups are not set up yet. See [Backup Check](alerts.md#backup-check). |

<a name="project-tiles"></a>
### Project Tiles

Every project that is not archived gets a tile, colored by the state of its production. Projects with an outage come first, then those that need attention, then the rest by name: what is wrong is at the top without you looking for it. A project that needs no monitoring and has no checks gets no tile, since there is no state to show.

A tile shows:

- the project's name, its state and its client (or *Own project*);
- the first problem, such as "HTTP shop.example.com: HTTP 503", and since when;
- its environments, each with a dot in its own state, so a staging outage is visible too;
- the servers production runs on, and, dimmed, the servers those depend on ("via storage-1");
- 24 hour bars, the uptime over 24 hours and the response time (see [Hour Bars, Uptime and Response Time](monitoring.md#hour-bars-uptime-and-response-time));
- the number of open and watched incidents.

Click a tile to open the project's [status page](#the-project-status-page). **On the map** above the tiles opens the [map](#the-map). The tiles refresh every 30 seconds.

<a name="the-tables-below"></a>
### The Tables Below

Below the tiles are the lists where you do something about it:

| Table | Shows | Action |
|---|---|---|
| Checks that need attention | Active checks that are down, possibly down, or without a recent result, worst first. Refreshes every 30 seconds. | Check now |
| Ongoing incidents | Open and watched incidents, newest first. | |
| Open action items | Follow-ups of all incidents, dated ones first; overdue ones in red. | Complete |
| Planned maintenance | Only when something is planned; overdue first. | Complete, Call off |
| Renewal calendar | The next 13 weeks: see [The Renewal Calendar](renewals.md#the-renewal-calendar). | |
| Domains, server contracts, subscriptions and internet contracts that need attention | The domains table is always there; the others only when something needs attention. | Renew |

<a name="the-project-status-page"></a>
## The Project Status Page

The **Status** tab of a project shows how its production did over time, in pictures rather than tables. This is where a dashboard tile leads.

At the top:

- **Now**: the current state, the first problem and since when;
- **Uptime** and **Response time** over the chosen period;
- **Open incidents**, and how many incidents there were in the period;
- **Planned maintenance**: the one due first, if any.

<a name="periods"></a>
### Periods

Choose **24 hours**, **7 days**, **30 days** or **90 days**. The first two show a bar per clock hour, the other two a bar per calendar day in the display timezone (so a day is sometimes 23 or 25 hours long, when the clocks change). The choice is part of the address (`?period=7d`), so you can link to it. Herdbook keeps hourly statistics for 90 days, so that is as far back as it goes. Only the 24-hour view refreshes itself, every minute.

<a name="the-timeline"></a>
### The Timeline

The **Availability** section is one timeline with up to three strips:

- **Maintenance**: a band over each maintenance window, blue, dashed while it is planned, orange when it is overdue.
- **Incidents**: a red band from the start of each incident to its resolution; an open incident runs until now.
- **The bars**: the same bars as the tile. Each hourly bar is the worst active availability check of that hour. A daily bar shows the average of its hours, but takes the color of its worst hour: twenty minutes of downtime makes a bad hour, not a 98% day.

Bands that would overlap get a row of their own, and each band links to its maintenance or incident. Maintenance and incidents are shown when they are linked to the project, or to a server its production needs; incidents also when they are linked to the network such a server stands in.

The **uptime** is the average of all hours in the period, so it is calculated the same way over every period, and a paused check is shown but not counted. A project without checks on production (for example one that needs no monitoring) shows only the time axis with its maintenance and incidents.

<a name="clicking-a-bar"></a>
### Clicking a Bar

Click a bar to see what went wrong in that hour or day: per check how many of its results failed, and the errors themselves, as long as the raw results are kept (14 days; for older bars only the counts are left). **What happened** below then shows only that period. Click the bar again, or **Close**, to go back.

The page remembers the start of the bar you opened, not its position, so the same hour stays open while the 24-hour view moves along. With the keyboard, the arrow keys move from bar to bar.

<a name="response-time-per-check-and-what-happened"></a>
### Response Time, Per Check, and What Happened

- **Response time**: a line of the average response time of the websites (HTTP checks) per bar. Redirect checks are left out, since they only time the redirect.
- **Per check**: a strip of bars for every check of production, including the servers it depends on, each with its own uptime, so you can see which check caused an orange hour. Certificate and DNS checks are listed too, after the availability checks, and paused checks are marked as paused.
- **What happened**: outages and recoveries, maintenance and incidents, as a timeline, newest first.

<a name="the-map"></a>
## The Map

**Map** in the menu draws your whole setup as columns of connected nodes, with the live state on every node. It shows what the lists cannot: what hangs together.

<a name="columns"></a>
### Columns

From left to right:

| Column | Nodes | Below the name |
|---|---|---|
| Provider / network | hosting providers and [networks](inventory.md#networks) | the number of servers, or the subnet (or location) of a network |
| Server | every server | its address |
| Environment | the environments, grouped per project | |
| Hostname | the hostnames | their purpose |
| Domain | the domains | the registrar and expiry date |

Lines connect each server to its provider or network (a server can have both), each environment to the servers it runs on, each hostname to its environment and its domain. [Dependencies](inventory.md#dependencies-and-impact) between servers are dotted arcs next to the server column, labeled with their kind (NFS, WireGuard). Registrars are not nodes of their own: they are named on the domain.

Each column is sorted so that as few lines cross as possible: nodes are placed near what they are connected to, and anything connected to nothing goes to the bottom. Each node always ends up in the same place, so you learn where to look. Archived projects are not on the map.

<a name="states-on-the-map"></a>
### States on the Map

| Node | Colored by |
|---|---|
| Server | its own checks (a decommissioned server is gray) |
| Environment | its [health](monitoring.md#health-states), including the servers it depends on |
| Hostname | its own checks |
| Domain | its [renewal status](renewals.md#statuses) |
| Provider, network | nothing |

The states refresh every minute, without redrawing the map.

<a name="pointing-and-clicking"></a>
### Pointing and Clicking

**Hover over a node** and everything connected to it, to the left and to the right, lights up: point at a provider to see its servers and what runs on them, or at a domain to see its hostnames and their environments.

**Click a server** to see what goes down with it: every environment that runs on it, or on a server that depends on it, lights up on the map, and a list next to it names them, with the path for the indirect ones ("via app-2 → NFS → storage-1") and a link to the server. Servers that depend on it but run no environment (a backup machine mounting its storage) light up too. This is a simulation, not a state, so it uses the selection color of the brand rather than red: a real outage is already red. Click the server again, press Escape, or use **Close** to clear it.

**Click any other node** to open its page; an environment opens its project.

<a name="filters"></a>
### Filters

- **Production only** leaves out staging, testing and development environments.
- **Project** shows one project.
- **Client** shows the projects of one client.

A filtered map shows the chosen environments and what they need: their servers, the servers those depend on, the providers and networks of those servers, and their hostnames and domains. The filters are part of the address, so you can bookmark, for example, the production map of one client.
