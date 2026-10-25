# Maintenance

- [Introduction](#introduction)
- [The Work](#the-work)
- [Logging Work You Did](#logging-work-you-did)
- [Scheduling Maintenance](#scheduling-maintenance)
- [Statuses](#statuses)
- [Completing or Calling Off](#completing-or-calling-off)
- [One Maintenance, Several Servers](#one-maintenance-several-servers)
- [When Was This Last Done?](#when-was-this-last-done)
- [On the Dashboard and the Status Page](#on-the-dashboard-and-the-status-page)

<a name="introduction"></a>
## Introduction

Servers need looking after: updates, reboots, the occasional look at the logs, and now and then a big upgrade to a new operating system or database version. The maintenance log keeps track of that, per server and per project, from a quick "installed updates and rebooted" to a planned upgrade with a step-by-step plan and a report afterwards.

You can write maintenance down afterwards (**Log**) or plan it ahead (**Schedule**). Either way it ends up in one list under **Knowledge → Maintenance**, and on the pages of the servers and projects it was for.

Maintenance is about work, not about a project's lifecycle. The project status *Maintenance only* means something else: that the project is no longer developed, only kept running (see [Projects](inventory.md#types-and-statuses)).

<a name="the-work"></a>
## The Work

A maintenance consists of one or more kinds of **work**:

| Work | For example |
|---|---|
| Updates | `apt upgrade` and the like |
| Reboot | A restart of the machine |
| Inspection | Logged in, read the logs, looked around |
| Upgrade | The big work: a new OS, PHP or database version |
| Other | Anything else |

The **title** is optional. Leave it empty and Herdbook makes one from the work, such as "Updates and reboot". A generated title follows along when you change the work later; a title you typed yourself stays as it is. Because generated titles repeat, search results and the list you pick from when linking show the date next to them.

A maintenance can be linked to **projects and servers** (not to domains, networks or clients). The person who wrote it down is recorded with it.

<a name="logging-work-you-did"></a>
## Logging Work You Did

On the **Maintenance** tab of a server or project, **Log** opens a short form: tick the work you did, check the time (it is set to now), and optionally write down what you did. It is saved as *Done* and linked to that server or project.

Work that is done cannot lie in the future: it would otherwise count as "last done" until that day came.

<a name="scheduling-maintenance"></a>
## Scheduling Maintenance

**Schedule** on the same tab opens the full form, with the server or project already linked and the status set to *Planned*. Fill in:

- the work and, if you like, a title;
- the **start**, and optionally an **end**, for a maintenance window;
- the **work plan**: what is going to happen, what goes down and how to roll back (markdown);
- later, **what was done**: what stood out and what is still to do (markdown).

You can also create maintenance from **Knowledge → Maintenance** directly and link it to any servers and projects under **Related to**.

<a name="statuses"></a>
## Statuses

| Status | Meaning |
|---|---|
| Planned | Still to happen. |
| Done | Carried out. Its start is when it was done. |
| Called off | Not going to happen. |
| Overdue | Not a status of its own: a planned maintenance whose end (or, without an end, its start) has passed without being completed or called off. |

*Overdue* is shown wherever the status is shown, in orange. The maintenance list has a filter for it, and the menu shows how many maintenances are overdue.

<a name="completing-or-calling-off"></a>
## Completing or Calling Off

**Complete** on a planned maintenance sets it to *Done* and asks when it was done and what was done. If you complete it before its planned time, it is taken to have started now, and a planned end that has not been reached yet is dropped.

**Call off** sets it to *Called off*. If you want to do it after all, edit it and set the status back to *Planned*.

Both actions are available on the maintenance itself, on the Maintenance tab of servers and projects, and on the dashboard.

<a name="one-maintenance-several-servers"></a>
## One Maintenance, Several Servers

Updating five servers in one evening is one maintenance, not five. Log or schedule it on one server, then use **Link** on the Maintenance tab of the others to attach the same maintenance (the newest are at the top, with their date). **Unlink** takes it off a server or project again without deleting it.

<a name="when-was-this-last-done"></a>
## When Was This Last Done?

From the maintenance that is done, Herdbook knows when each kind of work last happened on each server:

- The **server page** shows, per kind of work (updates, reboot, inspection, upgrade), when it was last done and how long ago, or *Not yet*. Next to it is the planned maintenance that is due first: an overdue one comes before an open window.
- The **server list** has a **Last updates** column you can sort on, so the servers that have gone longest without updates are easy to find.

Only maintenance linked to the server itself counts here: work logged on a project does not tell which of its servers was updated.

<a name="on-the-dashboard-and-the-status-page"></a>
## On the Dashboard and the Status Page

When anything is planned, the dashboard shows a **Planned maintenance** table: when, what, for which servers and projects, and its status, with the overdue ones at the top. You can complete or call off a maintenance right there. When nothing is planned, the table is not shown.

The [status page](dashboard.md#the-project-status-page) of a project draws maintenance as a band over its window above the uptime bars: blue, dashed while it is planned, orange when it is overdue. It shows the maintenance of the project and of the servers its production needs, so an update of a shared database server shows up on every project that uses it.

The activity log records changes to a maintenance, except its plan and report, which are long texts.

> [!NOTE]
> Alerts are not silenced during a planned maintenance window: a check that goes down during your work still alerts as usual. Pause the checks involved if you expect downtime (see [Monitoring](monitoring.md#creating-a-check-by-hand)).
