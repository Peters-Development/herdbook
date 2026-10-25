# Alerts

- [Introduction](#introduction)
- [Channels](#channels)
    - [Pushover](#pushover)
    - [ntfy](#ntfy)
    - [Email](#email)
- [Which Channel Is Used](#which-channel-is-used)
- [Alert Levels](#alert-levels)
- [What an Alert Says](#what-an-alert-says)
    - [Bundling](#bundling)
    - [Briefly Down](#briefly-down)
- [Delivery and Retries](#delivery-and-retries)
- [Testing Your Setup](#testing-your-setup)
- [The Weekly Renewal Digest](#the-weekly-renewal-digest)
- [Watching the Watcher](#watching-the-watcher)
    - [Heartbeat](#heartbeat)
    - [Backup Check](#backup-check)

<a name="introduction"></a>
## Introduction

When a check switches between up and down, Herdbook tells you. It can do so through three channels: **Pushover**, **ntfy** and **email**. Every channel is optional and works on its own, so one is enough; set up more than one and they back each other up.

An alert goes out through **the first channel that is set up and delivers, and only that one**. You do not get the same outage three times; you get it once, through the best channel that works at that moment.

The same channels carry the weekly renewal digest, the warning about an old backup, and anything you send yourself with `alerts:send`.

<a name="channels"></a>
## Channels

The channels are set up in `.env` (see [Configuration](configuration.md)), and each account decides in its **profile** (the user menu at the top right) whether it receives alerts through Pushover and by email. ntfy has one topic for the whole installation.

<a name="pushover"></a>
### Pushover

[Pushover](https://pushover.net) is the channel for waking someone up. On an iPhone it is the only one of these that can break through Do Not Disturb (with Pushover's critical alerts allowed on the phone).

1. Create an application on pushover.net and put its token in `PUSHOVER_APP_TOKEN`.
2. Each person adds their own **Pushover user key** in their profile (it is at the top of the Pushover app, or on pushover.net) and turns on **Receive alerts through Pushover**.

An urgent alert (a critical check went down) is sent as an emergency: it repeats every minute until someone acknowledges it in the Pushover app, for an hour at most. When the check recovers, or when you pause or delete it, the repeats stop. Every other message is a normal notification.

<a name="ntfy"></a>
### ntfy

[ntfy](https://ntfy.sh) sends push notifications to a topic you subscribe to in the ntfy app. Put the topic URL in `NTFY_URL`, for example `https://ntfy.sh/<a-long-random-topic>`, or the URL of a topic on an ntfy server of your own. For a protected topic, put an access token in `NTFY_TOKEN`.

Herdbook sets the priority by what the message is: 5 for an urgent alert (only when Pushover is not in front of it), 4 for a problem, 3 for a recovery or a digest.

> [!WARNING]
> On ntfy.sh, a topic is public: anyone who knows its name can read along. Alerts carry the names of checks and projects, and the digest the names and expiry dates of domains. Use a long random topic, or better, a reserved topic with `NTFY_TOKEN`, or your own ntfy server on your private network.

<a name="email"></a>
### Email

Set up the `MAIL_*` settings for your SMTP server, and each person turns on **Receive alerts by email** in their profile. Alerts go to the email address of the account, as plain text, with the alert's title as the subject. An urgent alert is marked as high priority, and every alert carries headers that keep out-of-office replies away.

A mailer that delivers to nobody does not count as a channel. With `MAIL_MAILER=log` or `array`, or a failover mailer that ends in `log`, an alert would be marked as sent while nobody receives it, so Herdbook treats email as not set up. The profile tells you when that is the case.

> [!TIP]
> Use an SMTP server that does not run on the servers you are watching, or the alert disappears exactly when that server is down. Email does not break through Do Not Disturb and can be delayed by greylisting; if you want to be woken at night, add Pushover.

<a name="which-channel-is-used"></a>
## Which Channel Is Used

The order depends on how urgent the message is:

| Message | Order |
|---|---|
| A critical check going down or coming back up | Pushover → ntfy → email |
| Everything else: normal checks, the renewal digest, the backup warning, `alerts:send` | ntfy → email → Pushover |

Herdbook takes the channels that are set up, in this order, and sends through the first. If that one fails, it tries the next. A channel counts as set up when it has what it needs: an application token and at least one account with a user key and Pushover turned on; an ntfy URL; a delivering mailer and at least one account with email turned on.

Pushover and email succeed as soon as at least one person received the message: resending to everyone because one key or address is wrong would alert the others twice. A channel that failed is skipped for the rest of that run (a mail server that hangs costs one timeout, not one per message) and tried again in the next.

<a name="alert-levels"></a>
## Alert Levels

Every check has an alert level:

| Level | Who hears about it |
|---|---|
| Critical (wakes you up via Pushover) | The critical order: Pushover first, as an emergency that repeats until acknowledged. |
| Normal | The normal order: ntfy first. |
| Silent (only shown in Herdbook) | Nobody. The change shows in Herdbook only. |

[Turning on monitoring](monitoring.md#turning-on-monitoring) makes the website check of a production hostname critical and everything else normal. Only a critical check on production can open a [draft incident](incidents.md#draft-incidents-from-checks).

Choose critical sparingly: it is for "a customer-facing site is down and someone should get up". A certificate that expires in two weeks can wait until morning.

<a name="what-an-alert-says"></a>
## What an Alert Says

Alerts are kept bare: which check, whether it went down or came back, and when. For example:

```
Down: Acme Shop · HTTP shop.example.com
12 Mar 2027 14:32
```

No error details and no page content: whoever reads a lock screen, and whoever runs the notification service, learns the name of a check, a state and a time, nothing more. The alert links to the check in Herdbook, where the details are. Keep that in mind when you name checks by hand.

<a name="bundling"></a>
### Bundling

When five or more checks change state in the same round, they become one message, such as "7 checks changed (5 down)", with a line per check. Twenty alarms at once help nobody; one message that says "something big is going on" does. Critical and normal checks are bundled separately, each through their own order. The threshold is `checks.group_alerts_from` in `config/herdbook.php`.

<a name="briefly-down"></a>
### Briefly Down

If a check went down and came back up before the down alert could go out (for example while the alert was being retried), you get one quiet message instead of an alarm followed by an all-clear: "Briefly down: Acme Shop · HTTP shop.example.com", with the time it was down.

<a name="delivery-and-retries"></a>
## Delivery and Retries

Alerts are sent every minute. A message that does not get out is tried again a minute later. While Herdbook's own internet line is down (see [The Connectivity Guard](monitoring.md#the-connectivity-guard)), failed attempts do not count: the alert simply waits.

After six hours without success, Herdbook gives up on it, and the alert is marked as *Failed* under **Status changes** on the check's page. Without any channel set up at all, events are marked as failed straight away, and a warning goes to the log.

<a name="testing-your-setup"></a>
## Testing Your Setup

Test every channel after you set it up, and again after changing the `MAIL_*` settings: otherwise you find out about a mistake on the day the channel in front of it fails.

**From your profile.** When `PUSHOVER_APP_TOKEN` is set, a bell next to **Pushover user key** sends a test to the key in the field, even before you save it and whether or not you receive alerts. Choose a normal alert or an emergency alert (which repeats until you acknowledge it, and breaks through Do Not Disturb if your phone allows critical alerts for Pushover). If Pushover refuses it, you see Pushover's own reason, such as a wrong key or a wrong application token.

**From the command line.** `alerts:send` sends a message through the same channels as the checks:

```bash
php artisan alerts:send "Test" "Does this arrive?"
php artisan alerts:send "Test" --via=mail
php artisan alerts:send "Test" --problem --critical
```

- Without options, it takes the normal order, like any other message.
- `--via=pushover`, `--via=ntfy` or `--via=mail` tries one channel only, even if another one comes first, and shows its error if it fails.
- `--problem` marks it as bad news (a higher ntfy priority); with `--critical` as well, it takes the critical order and is a Pushover emergency that repeats until acknowledged.

Run it in the app container (see [Commands](commands.md)).

<a name="the-weekly-renewal-digest"></a>
## The Weekly Renewal Digest

Once a week, Herdbook sends one message with the [renewals](renewals.md) that need attention: what is about to renew and should be confirmed, what you still have to decide on, and what has expired. Each line has the name, the renewal status, the expiry date and what the renewal costs:

```
3 renewals need attention
example.org: Expired (expires 2 Mar 2027)
example.com: Unconfirmed (expires 12 Mar 2027), €12.00 for 1 year
app-1: Decision needed (expires 30 Mar 2027), €24.00 per month
```

The one that expires first comes first. Amounts use the [currency symbol and number format](costs.md#amounts-and-periods) of the default language.

Every weekday at 08:00 (in the display timezone) Herdbook looks at the renewals. The digest goes out at most once per week, on the first of those mornings on which something needs attention and the message gets through: usually Monday, or Tuesday if Monday's attempt failed. When nothing needs attention, nothing is sent. The digest takes the normal order: ntfy, email, Pushover.

To send it now, also when this week's has gone out already: `php artisan renewals:digest --force`.

<a name="watching-the-watcher"></a>
## Watching the Watcher

Herdbook cannot tell you that Herdbook is down. A power cut, a dropped internet line, a full disk, or a stuck scheduler or queue worker all make it go quiet, and quiet looks just like "everything is fine". Two features cover that.

<a name="heartbeat"></a>
### Heartbeat

Set `HERDBOOK_HEARTBEAT_URL` to a URL that expects to be called regularly: a "dead man's switch" such as [healthchecks.io](https://healthchecks.io), or a monitor of your own on another machine. Herdbook calls it every five minutes. When the calls stop, that service alerts you.

The heartbeat is only sent while the checks are really running: if there are active checks but no fresh results (see [Stale Checks and Stalled Monitoring](monitoring.md#stale-checks-and-stalled-monitoring)), it is withheld. A stuck queue or a broken deploy then turns your external monitor red too, not only a dead machine. Leave the setting empty to turn the heartbeat off.

If your external monitor (or your own ntfy server) is only reachable on your private network, make sure the host running Herdbook can reach it; see [Networking](networking.md).

<a name="backup-check"></a>
### Backup Check

After a nightly backup without failures, the backup script touches a marker file in Herdbook's storage. Every morning at 09:00 Herdbook looks at it: when the last good backup is more than 26 hours old, it sends "Backup is out of date" with the time of the last successful backup, as a normal problem alert. Before the first good backup there is no marker, and Herdbook assumes backups are not set up yet rather than warning every day.

The dashboard shows the time of the last good backup as well. A failed backup itself is reported straight away by the backup script, through `alerts:send`, or directly to ntfy if the app cannot send it. Setting up backups is described in [Backups](backups.md).

> [!NOTE]
> If email is your only channel, turn on the heartbeat too. When the database is down, Herdbook cannot look up who receives email, so neither the checks nor the backup script can reach you through it.
