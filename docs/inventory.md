# Inventory

- [Introduction](#introduction)
- [The Model at a Glance](#the-model-at-a-glance)
- [Projects](#projects)
    - [Types and Statuses](#types-and-statuses)
    - [Monitoring Settings](#monitoring-settings)
    - [The Project Page](#the-project-page)
- [Environments](#environments)
- [Servers](#servers)
    - [Roles](#roles)
    - [Addresses and SSH](#addresses-and-ssh)
    - [Contract or Own Hardware](#contract-or-own-hardware)
    - [Server Status](#server-status)
- [Dependencies and Impact](#dependencies-and-impact)
- [Networks](#networks)
- [Domains](#domains)
- [Hostnames](#hostnames)
- [Providers](#providers)
- [Clients](#clients)
- [Linking Records Together](#linking-records-together)
- [Search](#search)
- [Activity Log](#activity-log)
- [Deleting Records](#deleting-records)

<a name="introduction"></a>
## Introduction

The inventory is what everything else in Herdbook hangs off: monitoring, incidents, maintenance, renewals and costs all point at something you entered here. You enter it by hand. Herdbook has no integrations with hosting or registrar APIs, which keeps it simple and works the same for every provider (some registries, such as those for `.nl` and `.eu`, do not publish expiry dates anyway).

The **project** is the center. A project has environments (production, staging, …), and each environment runs on one or more servers, each in a role. Hostnames point at an environment and belong to a domain. Everything else, from incident reports to subscriptions, links to the project or to one of those pieces.

The inventory lives in the **Inventory** menu group (projects, servers, domains, hostnames, checks, subscriptions and networks). Clients and providers live under **Settings**, because you set them up once and rarely touch them again.

<a name="the-model-at-a-glance"></a>
## The Model at a Glance

```
Client (optional)
  │
Project ──< Environment (Production, Staging, Testing, Development)
               ├──< Hostname ──> Domain ──> Provider (registrar, DNS)
               │       └──< Check
               └──>< Server   [role: App, Database, Storage, Mail, Proxy, Worker, Other]
                       ├──> Provider (hosting)
                       ├──> Network (optional) ──> Provider (internet)
                       ├──>< Server   [depends on: NFS, WireGuard, a database, …]
                       └──< Check

Incident · Document · Credential ──> related to projects, servers, domains,
                                     networks and clients
Maintenance ──> related to projects and servers
Subscription ──> Provider, Project, Client
```

`──<` is one-to-many, `──>` belongs to, `──><` is many-to-many.

A small example that the rest of this guide comes back to: the project **Acme Shop** has a *Production* environment that runs on `app-1` (role App) and `storage-1` (role Storage). `app-1` mounts its uploads from `storage-1` over NFS, so `app-1` *depends on* `storage-1`. The hostname `shop.example.com` points at Acme Shop's production, and `www.shop.example.com` redirects to it. Both belong to the domain `example.com`.

<a name="projects"></a>
## Projects

A project is anything you run: your own product, a client's application, a marketing website, an internal tool. It has a name and a slug (generated from the name, used on the command line, for example by `incidents:import --project=`), an optional client, a repository link, a stack (free tags such as Laravel or Filament), a short description and markdown notes.

Leave the client empty for a project of your own: it shows as *Own project*.

<a name="types-and-statuses"></a>
### Types and Statuses

The **type** says what kind of project it is: *Own product*, *Client project*, *Website* or *Internal*. It is there to filter and sort by; it changes no behavior.

The **status** does change behavior:

| Status | Meaning |
|---|---|
| Active | In use and being developed. |
| Maintenance only | Still running and looked after, but no longer developed. It is monitored exactly like an active project: if it goes down, that is an outage. It is *not* the same as planned [maintenance](maintenance.md). |
| Archived | No longer in use. Archiving pauses the checks of its hostnames, so it pages nobody. Archived projects have no tile on the dashboard and are not on the map or the status screen. Anything still paid for only archived projects shows up under "To sort out" on the [costs page](costs.md#to-sort-out). |

Taking a project out of the archive does not switch its checks back on by itself, since some may have been paused on purpose. Herdbook tells you how many are still paused, so you can resume them under **Inventory → Checks**.

<a name="monitoring-settings"></a>
### Monitoring Settings

Two switches on the project decide how it shows up in the overviews:

- **On the status screen** puts the project's name and the state of its production on the [status screen](status-screen.md). Anyone who can see that screen can read along, so only names and states go there.
- **Does not need monitoring** is for something that deliberately has no checks, such as a tool that runs on a machine of your own. Without checks, such a project shows *No monitoring needed* instead of *Not monitored*, and does not count as a gap: a production project whose checks you forgot is a gap, a deliberate choice is not. If you add checks after all, they count as usual. Such a project gets no tile on the dashboard (there is no production state to show) unless it has checks.

[Monitoring](monitoring.md#health-states) explains how the state of a project is worked out.

<a name="the-project-page"></a>
### The Project Page

The project page is where you work. It has three tabs at the top: **Overview**, **Status** (the [status page](dashboard.md#the-project-status-page): how production did over time) and **Edit**.

The overview shows the project's details, who to call during an outage (from its client), the [costs](costs.md#costs-on-project-and-client-pages) of everything it uses, and its notes. Below that are the tabs for its environments, hostnames, incidents, maintenance, documents, credentials and the activity log. You can add and edit environments right there, link existing incidents or documents, or create new ones that are linked to the project from the start.

The projects list has an **Attention** column that shows, per environment, whatever is wrong (an outage or something that needs attention) and the project's open incidents. It uses the same rules as the dashboard tiles, but per environment, so a staging outage shows up here too.

<a name="environments"></a>
## Environments

An environment sits between a project and its servers, because production and staging usually run on different machines, and one environment can be spread over several (an app server plus a storage server).

Each environment has a name (unique within its project), a kind (*Production*, *Staging*, *Testing* or *Development*), and optionally how it is deployed, the branch, the path on the server, notes and a sort order. You add environments on the project page.

The kind matters: the state of a project is the state of its **production** environments. Only checks that belong to production count towards the dashboard tile, the status page and the status screen, and only a critical check on production opens a [draft incident](incidents.md#draft-incidents-from-checks).

Under **Runs on** you list the servers the environment uses, each with a [role](#roles). The same server can appear twice with different roles (an app server that also runs the database), but not twice with the same role.

Outside its project, an environment reads as "Project · Environment", for example *Acme Shop · Production*. That is also how you pick one, for instance when you point a hostname at it: searching finds it by project name too.

Deleting an environment keeps its hostnames (they no longer point anywhere) but pauses their checks: they no longer watch anything.

<a name="servers"></a>
## Servers

A server is a VPS, a dedicated server or a machine on your own network. Besides its name, it has a status, a provider, its name at the provider, a plan and a location, its addresses, SSH access, specifications (vCPU, RAM, disk, operating system and version, backup policy), what it [costs](costs.md), its contract and markdown notes.

The server page brings together what you need when something is wrong with it: its addresses and SSH command, what **runs here** (every environment with its role), what is **affected by an outage**, its dependencies in both directions, when maintenance was last done, and its checks, incidents, maintenance, documents and credentials.

<a name="roles"></a>
### Roles

A role says what a server does for an environment: *App*, *Database*, *Storage*, *Mail*, *Proxy*, *Worker* or *Other*. The role is part of the "runs on" link, not of the server, since one server can play different roles for different environments.

Roles matter in one place: the servers with the role *App* or *Proxy* are the ones a hostname is expected to point at, so their public addresses become the expectation of its [DNS check](monitoring.md#dns-expectations).

<a name="addresses-and-ssh"></a>
### Addresses and SSH

A server has up to four addresses: public IPv4, public IPv6, a private address (WireGuard or LAN) and a Tailscale address. All of them are searchable: typing an IP address in the search bar answers "what runs on this address?".

The server page shows a ready-made SSH command, built from the SSH user and port and the first address there is, in this order: Tailscale, public IPv4, public IPv6, private. The private address comes last because on a VPS it is usually a WireGuard address that only its peers can reach; on a machine of your own it may be the only one there is.

When you change an address, the checks that probe the old address move to the new one, so a released IP that now belongs to someone else does not answer for your machine.

<a name="contract-or-own-hardware"></a>
### Contract or Own Hardware

A rented server has a contract: it expires, renews and costs money, exactly like a domain (see [Renewals](renewals.md)). A machine of your own, such as one in your office, has none.

Herdbook treats a server as having a contract when it has a provider, an expiry date, or was cancelled. A server without any of these shows no contract section, no renewal status and no "No date" warning. In the form, the contract section appears as soon as you pick a provider.

<a name="server-status"></a>
### Server Status

| Status | Meaning |
|---|---|
| Active | In use. |
| Maintenance | Being worked on. Informational: monitoring carries on as usual. |
| Decommissioned | Out of use. Setting this pauses the server's own checks. If it still has a contract or an amount, the costs page lists it under "To sort out". |

<a name="dependencies-and-impact"></a>
## Dependencies and Impact

Servers often need each other: an app server mounts storage over NFS, talks to a database on another machine, or reaches a peer through a WireGuard tunnel. On the server form you list, under **Depends on**, the servers this one needs and how ("via": NFS, WireGuard, Database, Redis, SMTP, or anything else you type). A server cannot depend on itself, and the same dependency cannot be listed twice.

The server page shows dependencies in both directions: what it **depends on**, and what it is **needed by**.

From these links Herdbook works out the impact of an outage. **Affected by an outage** on the server page lists every environment that goes down when this server does: the environments that run on it directly, and those that run on any server that depends on it, directly or through a chain. For an indirect one it shows the path:

```
Acme Shop · Production: runs on storage-1 (Storage)
Acme Blog · Production: via app-2 → NFS → storage-1
```

The same reasoning is used everywhere: a check on `storage-1` also counts towards the health of the projects on `app-2`, a critical outage of `storage-1` opens a draft incident for them, and clicking `storage-1` on the [map](dashboard.md#the-map) highlights everything that falls over with it. Chains are followed up to ten steps, and a circular dependency simply ends where it started.

<a name="networks"></a>
## Networks

A network is optional, and meant for machines of your own: the LAN in an office, for example. A VPS has its provider and needs no network. A server is in at most one network: where it physically stands.

A network has a name, an internet provider (a provider that offers *Internet*: who to call when the line is down), a location, a subnet, a gateway and the public IP address of the line, if it has a fixed one. The subnet is stored as its network address: `192.168.10.7/24` becomes `192.168.10.0/24`.

Under **Addresses** you list the other devices on the network that are not servers in Herdbook: the router, a NAS, a printer, each with its address, a name, an optional MAC address and a note. MAC addresses are stored in one notation, however you type them. The same address cannot be entered twice, also not in another notation (`fd00::1` and `fd00:0::1` are the same).

You put a server in a network from the server's own form. The network page then shows the servers (with their private address) and the devices in one list, sorted by address, and flags anything odd:

- **Duplicate address**: a server and a device share an address. This is flagged rather than refused, because the two forms cannot see each other.
- **Outside** the subnet: the address does not fall within the network's subnet.
- **No private IP**: a server in the network without a private address.

A network with an internet provider has an internet line, and that line can have an amount and a **Contract** of its own, with an expiry date and a term: the contract section appears in the form once you choose an internet provider. The line then counts in [Costs](costs.md) (for the projects of its servers) and in [Renewals](renewals.md), with its own table on the dashboard when it needs attention. A LAN without a line of its own has no contract.

The network page also shows what is **affected by an outage** of the whole network: the impact of all its servers going down at once. Incidents, documents and credentials can be linked to a network, such as the login of the router or a network diagram (keep the passwords themselves in your password manager, see [Knowledge](knowledge.md)). An incident linked to a network counts as an incident of every project whose production needs a server in it.

On the map, networks share the first column with the providers, and a server in a network hangs from it. The search bar finds a network by any of its devices' addresses, names or MAC addresses. Deleting a network keeps its servers (without a network) and removes its device addresses.

<a name="domains"></a>
## Domains

A domain is a registered name such as `example.com`. It has an optional client (empty: it is yours), a registrar, a DNS provider, what it [costs](costs.md), its [renewal](renewals.md) details and notes. Herdbook stores domain names in lower case.

You enter the expiry date yourself. The list of domains shows the renewal status of each, can be filtered by status, and has the **Renew** action per row and in bulk, plus a bulk action to set the same amount on many domains at once (one registrar often charges the same for all of them).

A domain's hostnames are listed, and can be added, on the domain's page.

A domain that exists only in your local DNS, such as `home.arpa` or `internal`, is registered nowhere: turn on **Only on the local network**. Such a domain may be a single label, without a dot. It then has no registrar, costs or renewal: whatever was filled in is cleared when you save (the activity log keeps it). Every hostname under it becomes [local](monitoring.md#names-on-the-local-network) too. Turning the setting off makes all its hostnames public again, so their HTTP checks verify the certificate and a mistaken click never leaves a public name unverified. The registrar, amount and expiry date were cleared, so fill them in again; with split DNS, mark the names that stay local again by hand.

<a name="hostnames"></a>
## Hostnames

A hostname is a name under a domain, such as `shop.example.com`. Every name with its own DNS record belongs here, including `www.shop.example.com` next to `shop.example.com`. The hostname must be the domain itself or end with it. A wildcard such as `*.example.com` is allowed for reference, but cannot be monitored.

A hostname has:

- **Points to environment**: the environment that answers on this name. This is what ties the hostname, and its checks, to a project.
- **Purpose**: free text such as website, admin, api or mail.
- **Redirects to**: for a name that only sends visitors elsewhere, such as `www.shop.example.com` → `shop.example.com`. Herdbook suggests existing hostnames, but any name will do. Monitoring then checks that the redirect points there instead of expecting a page (see [Redirects](monitoring.md#redirects)).
- **Behind a proxy (Cloudflare)**: the DNS record points at a proxy (such as Cloudflare), not at the server itself, so there is no DNS expectation and no DNS check.
- **Only on the local network**: the name is in your local DNS only, with a self-signed certificate or one of a CA of your own (such as Caddy's). **Turn on monitoring** then creates just a check of the page that accepts its certificate (see [Names on the Local Network](monitoring.md#names-on-the-local-network)).
- **Expected IP addresses**: overrides the addresses the DNS check expects. Leave it empty to use the public addresses of the App and Proxy servers of the environment.

You add hostnames under **Inventory → Hostnames**, or on the page of their domain. The hostnames tab of a project then lists every name that points to one of its environments, with its monitoring state (the worst state of its active checks, or *Off*) and the renewal status of its domain.

Hostnames are where monitoring starts: **Turn on monitoring** on a hostname creates its [default checks](monitoring.md#turning-on-monitoring).

When you rename a hostname, its checks follow: a check whose target is exactly the old name now probes the new one. Renaming to a wildcard pauses the checks instead.

> [!NOTE]
> Addresses such as `herdbook.<tailnet>.ts.net` are not hostnames in Herdbook's sense: a hostname belongs to a domain of yours and can have checks. Put such addresses in the notes of the project or environment. A name in your own local DNS, such as `nas.home.arpa`, can be a hostname: add its domain (`home.arpa`) with **Only on the local network** turned on ([Domains](#domains)).

<a name="providers"></a>
## Providers

A provider is any company you get something from. **Supplies** says what you get from it: *Hosting*, *Registrar*, *DNS*, *Mail*, *Internet* or *Other*, and the forms only offer providers of the right kind (a registrar for a domain, a hosting provider for a server, an internet provider for a network). Unticking a kind later does not break records that already use the provider.

A provider also has a website, a link to its control panel, support email and phone, notes, and a **Renewal lead time (days)**: how many days before the expiry date it renews automatically. That applies to all its domains, servers, subscriptions and internet lines unless an item says otherwise (see [Renewals](renewals.md#lead-time-and-grace)).

A provider cannot be deleted while servers, domains, subscriptions or networks still use it.

<a name="clients"></a>
## Clients

A client is kept deliberately small: a name, an **External link** to the client in your invoicing or CRM tool, who to call during an outage (name, phone, email) and notes. Herdbook is not a CRM and does not do invoicing: that stays in the tool you already use.

The outage contact is shown on every project of the client, so it is at hand when their site is down. The client page lists their projects, the [costs](costs.md#costs-on-project-and-client-pages) of what is for them, and linked incidents, documents and credentials.

A client with projects, domains or subscriptions cannot be deleted, because those would silently become your own. Archive the client instead: it disappears from the map's client filter, and anything still paid only for archived clients shows up under "To sort out" on the costs page.

<a name="linking-records-together"></a>
## Linking Records Together

Incidents, documents and credentials can be **related to** projects, servers, domains, networks and clients; maintenance to projects and servers. A link works both ways: the incident lists what it was about, and each of those pages lists the incident.

You can link from either side. On the incident (or document, or credential) form, pick the items under **Related to**. On a project, server or other page, the tab for incidents, documents or credentials lets you link an existing one, or create a new one that is linked from the start.

Environments and hostnames cannot be linked: link to the project and the server instead. A credential for the production database, for example, is linked to the project and to the server the database runs on.

<a name="search"></a>
## Search

The search bar at the top finds:

| What | By |
|---|---|
| Projects | name, slug, description |
| Servers | name, name at the provider, and every IP address |
| Domains and hostnames | name |
| Networks | name, subnet, gateway, public IP, and the address, name or MAC address of any device in it |
| Checks | name, target |
| Incidents, documents, maintenance | title (documents also by their content) |
| Credentials, subscriptions, clients, providers | name (subscriptions also by plan) |

<a name="activity-log"></a>
## Activity Log

Every change is logged: who changed what, and when, with the old and the new value. "Who changed the IP of this server last month?" has an answer. Each page has an **Activity** tab.

Some pages show the changes of their parts too, even after those parts were deleted: a project shows its environments and server links, a server its links and dependencies, a domain its hostnames, a network its devices. Long texts (document bodies, incident reports, maintenance plans) are not logged in full; documents keep [revisions](knowledge.md#revisions) instead.

<a name="deleting-records"></a>
## Deleting Records

Projects, servers, domains, hostnames and networks are deleted one at a time, never in bulk, and the confirmation says what goes with them:

- **A project** takes its environments with it. The checks of its hostnames are paused; the hostnames themselves stay. Linked incidents, maintenance, documents, credentials and subscriptions stay.
- **A server** that is still in use (in an environment or a dependency) cannot be deleted: set it to *Decommissioned*, or take it out of its environments and dependencies first. A server that is free goes with its checks and their history.
- **A domain** takes its hostnames and their checks with it.
- **A network** keeps its servers and removes its devices.
- **Providers and clients** cannot be deleted while something still uses them.

Links to incidents, documents, credentials and maintenance are removed with the record; the incidents and documents themselves stay.
