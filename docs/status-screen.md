# Status Screen

- [Introduction](#introduction)
- [What It Shows](#what-it-shows)
    - [What It Never Shows](#what-it-never-shows)
- [Choosing Projects](#choosing-projects)
- [Creating a Link](#creating-a-link)
- [How It Behaves](#how-it-behaves)
    - [When the Connection Drops](#when-the-connection-drops)
    - [Reloading Itself](#reloading-itself)
    - [When Its Access Is Revoked](#when-its-access-is-revoked)
- [Managing Screens](#managing-screens)
- [Network Access](#network-access)
- [A Raspberry Pi Kiosk](#a-raspberry-pi-kiosk)
    - [Checking It Works](#checking-it-works)
- [How Many Projects Fit](#how-many-projects-fit)

<a name="introduction"></a>
## Introduction

The status screen is a page for a TV on the wall: one look tells you how your most important applications are doing. It lives at `/screen`, is readable from across the room and needs no sign-in. Instead, each display gets a link with a token of its own, which you can revoke at any time.

Because anyone who walks by can read along, the screen shows names and states only, never the details that would help someone attack your infrastructure.

<a name="what-it-shows"></a>
## What It Shows

- **A tile per project**, for the projects you [chose](#choosing-projects):
  - the state of its production environment (outage, needs attention, healthy, no data yet, paused, not monitored, no monitoring needed), and since when;
  - one bar per hour for the last 24 hours, the uptime over those hours, and the average response time;
  - its open incidents, with only their severity and since when. Incidents with the status *Watching* don't count.
- **A summary**: how many projects are in each state.
- **The footer**: whether the checks are still running (and when the last result came in), and how old the last good backup is.
- **A clock**, in Herdbook's display timezone.

The states come from the same place as the dashboard and the map, so the three always agree. [Health States](monitoring.md#health-states) explains what each one means.

The screen speaks the default language (`APP_LOCALE`), not the language of an account.

<a name="what-it-never-shows"></a>
### What It Never Shows

No hostnames, IP addresses, check names, incident titles or links. Incident titles are left out because an incident opened by a check carries a hostname in its title.

This is enforced where the data is made, not in the page: the API builds its answer field by field from a fixed list, and never sends a whole record. A test searches that answer for hostnames, IP addresses and check names. The page puts all text in as plain text, never as HTML.

<a name="choosing-projects"></a>
## Choosing Projects

A project appears on the screen when its **On the status screen** toggle is on (on the project's edit form). The tiles are sorted by name and keep their place, so you learn where each application hangs. An outage gets a color and a blinking frame, not another position. Archived projects never appear.

<a name="creating-a-link"></a>
## Creating a Link

Each display gets its own link, made on the command line:

```bash
docker/compose.sh exec app php artisan screens:create "Hallway"
```

The command prints the link once:

```
https://herdbook.<tailnet>.ts.net/screen#<token>
```

It is built from `APP_URL`. Herdbook only stores a hash of the token, so the link can't be shown again: store it nowhere else than on the display itself. Lost it? Revoke the screen and create a new one.

The token sits after the `#`, in the part of the address that browsers never send to a server. So it never ends up in a server log, and never leaves the browser as a referrer. The page sends it as a Bearer token with each request.

<a name="how-it-behaves"></a>
## How It Behaves

The page asks `/api/screen` for fresh data every 20 seconds, each request with a ten-second time-out. There is no session behind it, so a screen that stays open for weeks never gets signed out. All displays share one computation, which is at most 15 seconds old.

<a name="when-the-connection-drops"></a>
### When the Connection Drops

A screen frozen on green is worse than no screen. So when no good answer came in for two minutes, or the answers stopped changing, the screen turns gray and says since when it has had no connection. What it still shows is old, and it says so.

The colon in the clock blinks every second. If it stands still, the browser itself has hung.

The two minutes are measured on the browser's monotonic clock, so a device without a battery-backed clock (like a Raspberry Pi) can't confuse it when its clock jumps after boot.

<a name="reloading-itself"></a>
### Reloading Itself

The screen reloads itself when a deploy changed the page, and every night at 04:00. It only reloads right after Herdbook answered, so the browser never ends up stuck on its own error page, and at most three times an hour. The nightly reload also happens when the screen is gray.

<a name="when-its-access-is-revoked"></a>
### When Its Access Is Revoked

After `screens:revoke`, the next request is refused. The screen clears the projects off the wall, says its access was revoked and that it needs a new link, and from then on only asks once every five minutes.

<a name="managing-screens"></a>
## Managing Screens

| What | How |
|---|---|
| Which screens exist, and when each last asked for data | `docker/compose.sh exec app php artisan screens:list` |
| Take a screen's access away (a lost device, a leaked link) | `docker/compose.sh exec app php artisan screens:revoke "Office"` |
| Other projects on the screen | The **On the status screen** toggle on each project |

A screen that went quiet in `screens:list` is switched off, or lost its network. [Commands](commands.md#status-screens) has the details.

Each screen may ask 20 times a minute; wrong tokens have a limit of their own, so guessing can't lock out a real screen.

<a name="network-access"></a>
## Network Access

The display has to reach Herdbook like any other device.

**Over Tailscale**, give the display the tag `tag:display`, with a grant to `tag:herdbook` on port 443. The policy in [Tags and Grants](networking.md#tags-and-grants) has it already. A display then reaches the app, and nothing else on your tailnet.

**On your local network** (the `lan` profile), the display opens `https://<LAN_IP>/screen#<token>`, so change the address part of the link that `screens:create` printed if `APP_URL` is your tailnet name. The display's browser then has to trust Caddy's [root certificate](networking.md#trusting-the-root-certificate); the kiosk below takes the Tailscale route.

Smart TVs generally can't run Tailscale, which is why the recipe below uses a Raspberry Pi connected to the TV.

<a name="a-raspberry-pi-kiosk"></a>
## A Raspberry Pi Kiosk

You need a Raspberry Pi 4 or 5 with Raspberry Pi OS (Bookworm, with desktop), connected to the TV.

1. **Tailscale.** Make sure the policy has `tag:display` and its grant. Create an auth key with the tag `tag:display`: not ephemeral, not reusable, and pre-approved if your tailnet requires device approval. A tagged device doesn't expire.

2. **The Pi on your tailnet:**

   ```bash
   curl -fsSL https://tailscale.com/install.sh | sh
   sudo tailscale up --auth-key=<the key from step 1> --hostname=status-display
   ```

3. **A link for the screen**, on the host:

   ```bash
   docker/compose.sh exec app php artisan screens:create "Hallway"
   ```

4. **Sign in to the desktop automatically**, or nothing starts:

   ```bash
   sudo raspi-config nonint do_boot_behaviour B4
   ```

5. **A start script** that waits until Herdbook answers and then opens Chromium full screen. After a power cut, Tailscale isn't there straight away, and Chromium would otherwise stay on its own error page. Save it as `~/status-screen.sh` and make it executable with `chmod +x ~/status-screen.sh`:

   ```bash
   #!/bin/sh
   URL='https://herdbook.<tailnet>.ts.net/screen#<token>'
   until curl -fsS --max-time 5 https://herdbook.<tailnet>.ts.net/up > /dev/null; do sleep 5; done
   BROWSER=$(command -v chromium || command -v chromium-browser)
   # When the browser stops or crashes, start it again after five seconds.
   while true; do
       "$BROWSER" --kiosk --incognito --noerrdialogs --disable-infobars --no-first-run \
           --password-store=basic --disable-features=Translate --check-for-update-interval=31536000 "$URL"
       sleep 5
   done
   ```

   The single quotes around the link are needed because of the `#`. `--incognito` prevents the "restore pages?" question after a power cut. Newer images call the browser `chromium`, older ones `chromium-browser`; the script takes whichever is there. `/up` is Herdbook's health check: it answers without a sign-in.

6. **Start it with the desktop.** On Bookworm with the labwc desktop, add this line to `~/.config/labwc/autostart`:

   ```bash
   ~/status-screen.sh &
   ```

7. **Keep the screen on:**

   ```bash
   sudo raspi-config nonint do_blanking 1
   ```

   Then reboot.

<a name="checking-it-works"></a>
### Checking It Works

Pull the Pi's network cable: within two minutes the screen turns gray and says it has no connection. Plug it back in, and the screen recovers at its next request.

<a name="how-many-projects-fit"></a>
## How Many Projects Fit

With a handful of projects, four tiles fit side by side, on full HD and on 4K alike. As more projects join, the screen adds columns and makes the tiles smaller, so everything fits on one screen without scrolling. Beyond about thirty projects, the small print gets hard to read from a distance: choose only what really matters.
