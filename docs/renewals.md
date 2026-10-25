# Renewals

- [Introduction](#introduction)
- [What Renews](#what-renews)
- [The Renewal Fields](#the-renewal-fields)
- [Lead Time and Grace](#lead-time-and-grace)
- [Statuses](#statuses)
    - [An Example](#an-example)
- [Renewing](#renewing)
- [Stop Renewing](#stop-renewing)
- [Without an Expiry Date](#without-an-expiry-date)
- [Where Renewals Show Up](#where-renewals-show-up)
    - [The Renewal Calendar](#the-renewal-calendar)

<a name="introduction"></a>
## Introduction

Domains expire, hosting contracts run out, licenses need renewing. Herdbook does not renew anything for you, and it does not ask registrars or hosting companies for dates: you enter the expiry date, and Herdbook tells you when something needs your attention, whether that is confirming that an automatic renewal went through or deciding whether to renew at all.

Every renewable item works the same way, with the same fields, statuses and actions. What the renewals cost is described in [Costs](costs.md).

<a name="what-renews"></a>
## What Renews

| Item | Notes |
|---|---|
| Domains | Always have a renewal, except a domain that is [only on the local network](inventory.md#domains), such as `home.arpa`. |
| Servers | Only servers with a contract: a provider, an expiry date or a cancellation. A machine of your own has none (see [Contract or Own Hardware](inventory.md#contract-or-own-hardware)). |
| Subscriptions | Licenses, mail hosting, SaaS plans, storage: anything else you pay for. Created under **Inventory → Subscriptions**, with a provider, a plan, and optionally the project and client it is for. |
| Networks | The internet line of a network: it has a contract once you choose an **Internet provider** for the network. A LAN without a line of its own has none (see [Networks](inventory.md#networks)). |

<a name="the-renewal-fields"></a>
## The Renewal Fields

| Field | Meaning |
|---|---|
| Expiry date | The day it runs out. You fill it in, and **Renew** moves it on. |
| Term | How long one renewal lasts: 1, 3 or 6 months, or 1, 2 or 3 years. Domains, servers and networks start with a year, subscriptions with a month. |
| Renews automatically | Whether the provider renews by itself (on by default). If it does not, you have to decide each time. |
| Renewal lead time (days) | How many days before the expiry date the provider renews. Leave it empty to use the provider's. |

Dates are calendar days, and "today" is taken in the display timezone: the date of your renewals changes at midnight where you are, not at midnight UTC.

<a name="lead-time-and-grace"></a>
## Lead Time and Grace

The **lead time** is the first of these that is set:

1. the item's own **Renewal lead time (days)**;
2. the **Renewal lead time (days)** of its provider (the registrar for a domain, the provider for a server or subscription, the internet provider for a network);
3. the default of 60 days (`renewal.default_lead_days` in `config/herdbook.php`).

The lead time is capped at **half the term**. A monthly contract with a lead time of 60 days would otherwise never be *Active*, not even right after it was renewed; capped, it gets 15 days.

After the provider's renewal moment, Herdbook waits a **grace period** before it asks you to confirm the renewal: 14 days (`renewal.confirmation_grace_days`), but at most half the lead time, so that with a short lead time the request still comes well before the expiry date.

<a name="statuses"></a>
## Statuses

The status follows from the dates and is never stored: it changes with the calendar. The rules are applied from top to bottom; the first one that fits wins. *L* is the lead time and *G* the grace period.

| Status | When | What to do |
|---|---|---|
| Cancelled | You chose **Stop renewing**. | Nothing: no more reminders. |
| No date | There is no expiry date. | Fill one in. |
| Expired | The expiry date has passed. | If it was renewed after all, press **Renew**; if you let it go, **Stop renewing**. |
| Active | More than *L* days to go. | Nothing. |
| Decision needed | It does not renew automatically, and *L* days or fewer to go. | Decide: renew it at the provider and press **Renew**, or **Stop renewing**. |
| Renewal expected | It renews automatically, and the renewal moment has passed by less than *G* days. | Nothing yet: the provider is renewing about now. Press **Renew** when the confirmation or invoice arrives. |
| Unconfirmed | It renews automatically, and the renewal moment has passed by *G* days or more. | Check with the provider that it really renewed, then press **Renew**. |

*Renewal expected*, *Unconfirmed*, *Decision needed* and *Expired* **need attention**: those are the items on the dashboard and in the weekly digest.

<a name="an-example"></a>
### An Example

A domain expires on 12 March 2027, renews automatically, every year, with a lead time of 60 days. The grace period is 14 days.

| From | Status |
|---|---|
| (until 10 January 2027) | Active |
| 11 January 2027 (60 days before) | Renewal expected |
| 25 January 2027 (14 days later) | Unconfirmed |
| 13 March 2027 (the day after the expiry date) | Expired |

If the same domain did not renew automatically, it would go from *Active* to *Decision needed* on 11 January.

<a name="renewing"></a>
## Renewing

**Renew** moves the expiry date on by one term. It counts from the current expiry date, not from today, so recording a renewal a few days late shifts nothing. A date at the end of a month stays at the end: 29 February plus a year is 28 February.

The confirmation shows the old and the new date, such as "12 Mar 2027 → 12 Mar 2028". The renewal is recorded in the activity log as "Renewed until …". **Renew** is not available without an expiry date, or after you stopped renewing.

**Renew** is on the item's page, on each row of the lists of domains and subscriptions, and on the dashboard tables. To renew several items at once, select them in the list of domains, servers, subscriptions or networks and use the bulk action: the confirmation shows the new date of each, and items without a date or that were cancelled are skipped.

<a name="stop-renewing"></a>
## Stop Renewing

**Stop renewing** on an item's page marks it as *Cancelled*: you have cancelled it at the provider, or will let it run out. It sends no more reminders, and from then on its cost no longer counts per year; a monthly amount is still counted until the expiry date (see [Costs](costs.md)). The item shows when it was cancelled.

**Resume renewing** takes this back.

<a name="without-an-expiry-date"></a>
## Without an Expiry Date

An item with a contract but without an expiry date is *No date*. It never needs attention, but the renewal status column and filter show it, so you can find the ones you have not filled in yet.

Some things simply have no expiry date, and get no renewal status at all:

- a server of your own, without a provider or contract;
- a domain that is only on the local network;
- a monthly subscription that just runs on until you cancel it: leave its expiry date empty.

<a name="where-renewals-show-up"></a>
## Where Renewals Show Up

- **The dashboard band** counts what needs attention, and names the one due first. Its link opens the list of that kind, filtered on what needs attention.
- **The renewal calendar** on the dashboard (below).
- **Tables on the dashboard** list the domains, server contracts, subscriptions and internet contracts that need attention, with **Renew** on every row. The domains table is always there; the others only appear when something needs attention.
- **The weekly digest** sends the same list to you once a week: see [The Weekly Renewal Digest](alerts.md#the-weekly-renewal-digest).
- **Lists** of domains, servers, subscriptions and networks have a renewal status column, a filter per status, and a **Requires attention** filter.
- **The map** colors each domain by its renewal status, and the hostnames tab of a project shows the renewal status of each hostname's domain.

<a name="the-renewal-calendar"></a>
### The Renewal Calendar

The calendar on the dashboard is a timeline of the next 13 weeks, with a marker for every domain, server contract, subscription and internet contract that expires in that time, colored by its status. Markers that would overlap go on another row, so nothing hides behind something else. What has already expired (and was neither renewed nor cancelled) is listed in front of the line. Cancelled items are left out.

The calendar shows when; the tables below it are where you act. Click a marker to open the item.
