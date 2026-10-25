# Costs

- [Introduction](#introduction)
- [Amounts and Periods](#amounts-and-periods)
- [Subscriptions](#subscriptions)
- [When You Pay](#when-you-pay)
    - [Monthly Amounts](#monthly-amounts)
    - [Yearly Amounts](#yearly-amounts)
    - [To Decide](#to-decide)
- [Per Year and the Next 12 Months](#per-year-and-the-next-12-months)
- [Who It Is For](#who-it-is-for)
    - [Shared Costs](#shared-costs)
- [The Costs Page](#the-costs-page)
- [To Sort Out](#to-sort-out)
- [Costs on Project and Client Pages](#costs-on-project-and-client-pages)
- [Filling In Amounts](#filling-in-amounts)

<a name="introduction"></a>
## Introduction

Everything that renews also costs money: domains, server contracts, subscriptions and the internet lines of your networks. Herdbook adds it up, so you can answer questions such as "what does all of this cost per year?", "what is coming up this month and this quarter?", "what does this client's setup cost us?" and "what do we pay this provider?".

It is an overview, not bookkeeping. Herdbook does not know what you have already paid, does not send invoices and has no one-off costs. Invoicing stays in the tool you use for it.

<a name="amounts-and-periods"></a>
## Amounts and Periods

Each domain, server, subscription and network can have an **amount** and a **period**: per month or per year. An amount without a period says nothing about a month or a year, so it does not count until it has one.

Amounts are **excluding VAT**, everywhere: the forms and the costs page say so.

All amounts are in one currency, with the symbol set by `HERDBOOK_CURRENCY_SYMBOL` (default `€`; see [Configuration](configuration.md)). Herdbook does not convert between currencies. Where the symbol goes and which separators are used follows the language of the interface: `€1,234.56` in English, `€ 1.234,56` in Dutch.

Herdbook calculates with exact decimals, never with floating point numbers, and rounds each payment to the cent.

<a name="subscriptions"></a>
## Subscriptions

Subscriptions are everything else you pay for that is not a domain or a server: licenses, mail hosting, a SaaS plan, storage. Find them under **Inventory → Subscriptions**. A subscription has a name, a provider (you can create a new one from the form), a plan, the **project** and **client** it is for, notes, an amount and a [renewal](renewals.md). Leave the client empty to use the client of the project, or, without a project, to make it your own.

A monthly subscription that simply runs on until you cancel it needs no expiry date, and then has no renewal status. A new subscription starts with a term of one month.

On the forms of domains, servers, subscriptions and networks, the term follows the period of the amount: choose "per year" and a term of one month becomes one year, choose "per month" and a term of one year becomes one month. A term you chose yourself (such as two years) is left alone.

<a name="when-you-pay"></a>
## When You Pay

Herdbook works out from the period and the renewal dates in which month each amount is paid.

<a name="monthly-amounts"></a>
### Monthly Amounts

A monthly amount is paid **every month**, the current month in full.

- After you [stop renewing](renewals.md#stop-renewing), it is paid until the expiry date, including the whole month the expiry date falls in. One month too many is better than one too few.
- Without an expiry date, it simply runs on.
- When it has expired without being renewed or cancelled, it probably still runs, so it keeps counting.

<a name="yearly-amounts"></a>
### Yearly Amounts

A yearly amount is paid **at every renewal**: on the expiry date, and every term after it. One renewal costs the yearly amount times the term in years. A domain registered for two years at €12.00 per year costs €24.00 every two years; a yearly amount of €10.00 on a monthly term costs €0.83 every month.

- After you stop renewing, nothing more is paid.
- Without an expiry date, Herdbook cannot tell in which month you pay, so the amount counts in the yearly total but in no month. The costs page lists it under [To Sort Out](#to-sort-out).
- A renewal whose date passed before the current month without being recorded falls in no month (it is listed under To Sort Out as expired); the renewals after it do.

<a name="to-decide"></a>
### To Decide

Something that does not renew automatically will only cost money if you decide to renew it. Its payments count as **to decide**: shown separately in the numbers and in orange in the chart, so you can tell what is certain from what is up to you.

For a monthly amount, only the months from the month the contract expires count as to decide: the months before it you pay in any case.

<a name="per-year-and-the-next-12-months"></a>
## Per Year and the Next 12 Months

Herdbook shows two kinds of totals, and they answer different questions.

**Per year** is what everything costs on a yearly basis: every item with an amount that has not been cancelled, a monthly amount twelve times. It is the figure to compare from year to year.

**The next 12 months** (this month and the eleven after it), **this month** and **this quarter** (this month up to the end of the calendar quarter) are what you will actually pay in that time, payment by payment. They differ from the yearly figure in a few ways:

| | Per year | Next 12 months |
|---|---|---|
| Cancelled items | not counted | counted until they run out (monthly amounts) |
| A two-year term | half the renewal cost per year | the full renewal, if it falls in the period |
| A yearly amount without an expiry date | counted | in no month |
| A renewal that should have happened before this month | counted | in no month |

Neither knows what was already paid: when you record a renewal, the expiry date moves on to the next term, and that is the payment Herdbook shows.

<a name="who-it-is-for"></a>
## Who It Is For

Totals are also grouped **by provider**, **by project** and **by client**, per year. An item belongs to:

| Item | Projects | Clients |
|---|---|---|
| Server | the projects of the environments that run on it | the clients of those projects; without projects, your own |
| Domain | the projects its hostnames point to | the domain's own client, or your own |
| Subscription | its project | its own client, or else the client of its project, or your own |
| Network | the projects of the servers in it | the clients of those projects; without projects, your own |

Items for no project are grouped under **No project**, items of your own under **Own**, and items without a provider under **No provider**.

<a name="shared-costs"></a>
### Shared Costs

A server that runs three projects is **shared**. Dividing its cost evenly between them would be a guess, so Herdbook does not: a shared item is counted once, under **Shared**, and also shown with each of the projects (or clients) it is for, as "+ €920.00 shared with others". A project that only uses shared items shows "shared only". That way the totals add up, and every project still shows everything it depends on.

<a name="the-costs-page"></a>
## The Costs Page

**Costs** in the menu shows the whole picture:

- **Per year** at the top: the yearly total, the average per month, and how many items it counts.
- **This month**, **This quarter** and **Next 12 months**: what you pay in that period, and how much of it is to decide. Click one to list its payments below.
- **A chart of the next 12 months**: per month, stacked, what is paid every month, the renewals, and what is to decide. Click a month to list its payments; click it again to close it. The chosen period is part of the address, so you can link to it.
- **The payments** in the chosen period: the renewals with their date, term, status and amount, and the monthly amounts with the amount per month, the number of months and the total. For the renewal that is due now, the [renewal status](renewals.md#statuses) is shown when it asks something of you; *Decision needed* reads as *To decide* here, as in the chart, and is gray while it is not due yet.
- **By provider**, **By project** and **By client**: the yearly total of each group, most expensive first, each of which opens to show its items. Open groups stay open when you choose another period.
- **To sort out**: see below.

<a name="to-sort-out"></a>
## To Sort Out

Until these are sorted out, the numbers are not quite right. The costs page lists:

- items **without an amount or period**, per kind, with a link to the list filtered on them: they count nowhere;
- items that **expired** and were neither renewed nor cancelled;
- **decommissioned servers** that are still paid for: cancel the contract, or for a machine of your own without a contract, remove the amount;
- items that are **only used by archived** projects or clients, but keep running (never an internet line: the network needs it whatever runs there);
- **yearly amounts without an expiry date**, which count in the yearly total but in no month. For a server without a contract, choose a provider to be able to fill one in; for a network, an internet provider.

Cancelled items, machines of your own without a contract and domains that are only on the local network are not "without an amount": there is nothing to count.

<a name="costs-on-project-and-client-pages"></a>
## Costs on Project and Client Pages

The page of a project and of a client has a **Costs** section: what it costs per year (and on average per month), the items that are its own, the items it shares with others (not divided: they count under *Shared*), and what is for it but has no amount yet. These are the same groups as on the costs page.

**Add subscription** opens the subscription form with the project or client already filled in, and **All costs** opens the costs page.

<a name="filling-in-amounts"></a>
## Filling In Amounts

The lists of domains, servers, subscriptions and networks have a **Costs** column (it sorts by the yearly amount, so monthly and yearly amounts compare) and a **Without an amount** filter. Many domains at one registrar cost the same, so the domains list has a bulk action **Set amount**: select the domains, and give them one amount and period at once. Local domains in the selection are skipped: they cost nothing.

A new domain starts with a yearly amount, a new server, subscription or network with a monthly one. A network's amount is what its internet line costs; its contract appears once you choose an internet provider.
