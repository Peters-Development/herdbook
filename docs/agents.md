# Agents

- [Introduction](#introduction)
- [What an Agent Can Do](#what-an-agent-can-do)
    - [What It Gets](#what-it-gets)
    - [What It May Write](#what-it-may-write)
    - [Changes Made Meanwhile](#changes-made-meanwhile)
- [Turning It On](#turning-it-on)
- [A Token per Agent](#a-token-per-agent)
    - [Managing Agents](#managing-agents)
- [Connecting Claude Code](#connecting-claude-code)
    - [The Token in Your Keychain](#the-token-in-your-keychain)
    - [The Server](#the-server)
    - [The Certificate](#the-certificate)
    - [Permissions](#permissions)
    - [Checking It Works](#checking-it-works)
    - [Disconnecting](#disconnecting)
- [Renewing a Token](#renewing-a-token)
- [Security](#security)
    - [Undoing What an Agent Did](#undoing-what-an-agent-did)
- [Troubleshooting](#troubleshooting)

<a name="introduction"></a>
## Introduction

An agent such as [Claude Code](https://code.claude.com) can look things up in Herdbook and keep its maintenance log, without clicking through the panel. It keeps incidents too: it sees what is going on and what happened last time, opens an incident when something breaks, and writes down what it tries and finds as it goes. It can find a server, see when it was last updated, plan work, and write down what it did. It talks to Herdbook's MCP server (the [Model Context Protocol](https://modelcontextprotocol.io)) at `/mcp`, with a token of its own.

Herdbook remains the place where people work: an agent sees less than a person and may do less. What an agent creates (maintenance, incidents, timeline entries, action items) is signed with its account and its name, such as "Ada via deploy-agent", and the activity log names the agent for every change it makes to a maintenance (not to which servers and projects it is linked to, which isn't logged for people either).

<a name="what-an-agent-can-do"></a>
## What an Agent Can Do

| Tool | What it does | Token |
|---|---|---|
| `search_inventory` | Finds projects, servers and hostnames the way an agent knows them: by name, by the git remote of the repository it works in, by the output of `hostname -f`, or by the address it connects to. A server shows when updates, a reboot, an inspection and an upgrade were last done on it. | read |
| `list_maintenance` | Lists maintenance: planned (overdue included), overdue, done or called off, for some servers or projects, of some kind of work. | read |
| `get_maintenance` | One maintenance with its plan and report. | read |
| `list_incidents` | Lists incidents: the unresolved ones unless it asks for others, of some projects (as far as Herdbook counts them for a project: also through the servers its production needs) or servers, of some severity, since some day. | read |
| `get_incident` | One incident with its impact, root cause, report, timeline (its latest entries, as many as fit in one answer) and action items. | read |
| `open_incident` | Opens an incident: what is wrong, how severe, when it started and was detected, its impact and what is known so far. Herdbook tells the agent to look for an unresolved one of the same servers or projects first, as its checks may have opened one, and the answer names those. | write |
| `add_timeline_entry` | Adds an entry to an incident's timeline: what was tried, found or done, and when. Only added, also to a resolved incident. | write |
| `add_incident_action` | Adds an action item to an incident, with a due date if there is one. | write |
| `complete_incident_action` | Marks an action item as done. | write |
| `update_incident` | Changes an incident: its title, severity, status (also to reopen it), times, impact, root cause, report, or servers and projects. | write |
| `resolve_incident` | Resolves an incident: when (default now), and its root cause and report if it knows them. | write |
| `schedule_maintenance` | Plans maintenance. | write |
| `log_maintenance` | Writes down maintenance that was done. | write |
| `update_maintenance` | Changes the work, title, times, plan, report or servers and projects of planned or done maintenance. Not its status, and nothing that was called off. | write |
| `complete_maintenance` | Completes planned maintenance, with the same defaults as **Complete** in the panel. A report it gives replaces the one there is. | write |
| `cancel_maintenance` | Calls off planned maintenance (its status becomes `cancelled`). Maintenance that is done can't be called off. | write |

There is no tool that deletes anything. An agent with a read token doesn't see the write tools at all.

<a name="what-it-gets"></a>
### What It Gets

What a tool returns is built field by field, so a new column never reaches an agent by itself. An agent gets names (of projects, servers, hostnames, providers, clients, domains, networks and accounts), a server's operating system, and links to the pages in Herdbook. It gets maintenance with its plan and report, cut at 10,000 characters each, with who created it and who last changed its plan and report, through which agent, as far as the activity log records it: a plan someone else rewrote doesn't pass for its creator's. It gets incidents the same way, with their impact, root cause, report, timeline and action items. It never gets the notes of servers, projects and the rest of the inventory, IP addresses, provider hostnames (they often hold the address), repository URLs (they may hold credentials), SSH details, costs, credential references or the contact details of clients. Where people or Herdbook's checks wrote an address into what an agent reads (a check is named after its target: "Ping 192.0.2.10"), it reads as `[address of app-1]` or `[address]`. That finds the addresses Herdbook knows and IP addresses in the usual forms; one written in an unusual form can get through.

It can still *find* a server by its address or its provider hostname, and a project by its git remote: those are compared whole (a provider hostname also by its first label), never in part, so they can't be spelled out character by character. A guessed address can be confirmed, though: at 120 requests a minute, an agent can try every address of a /24 in a few minutes.

<a name="what-it-may-write"></a>
### What It May Write

The same rules as the forms apply, with the same messages: work that is done can't lie in the future, an end can't come before the start, and times lie between 1970 and 2038. On top of that, an agent:

- links what it writes to at least one server or project;
- writes no passwords, tokens or private keys: text that looks like one is refused (a long value after `DB_PASSWORD=` or `Authorization: Bearer`, known token formats, a password in a URL). This guards against accidents, such as pasted command output, not against an agent that means to get a secret through;
- writes no images or HTML in plans and reports: an image would be loaded the moment a person opens or edits the page, and could carry data along in its address;
- writes no masks such as `[address of app-1]`: written back, a mask would take the place of the address it stands for. An agent that wants to change a text it read with masks writes one of its own, or asks you to change it in Herdbook;
- links to no other site: a link could lead somewhere else than it says. Links into Herdbook pass (a path such as `/servers/1`, or `APP_URL`), and so do the links of the text it replaces, so an agent can add a step to your plan without breaking your links. A web address between backticks is fine, and so is an email address, as in `ssh deploy@app-1.example.com`. An agent can still move one of your links under other words;
- writes at most 10,000 characters per plan or report, and doesn't overwrite a plan or report that is longer (as it read it, masked), whoever wrote it: it would lose what it couldn't read;
- writes an incident's impact, its timeline entries (at most 1,000 characters) and its action items (at most 255) as plain text: no passwords, tokens or masks, and moments that lie in the past;
- changes the status only by completing or calling off, and doesn't change maintenance that was called off.

Times are ISO 8601, such as `2026-11-12T22:00`, in minutes as in the forms: seconds are dropped. Without an offset, they are in the display timezone (`HERDBOOK_DISPLAY_TIMEZONE`). Answers carry the offset, so a mistake shows at once.

<a name="changes-made-meanwhile"></a>
### Changes Made Meanwhile

Every maintenance an agent reads comes with a `version`, a fingerprint of everything that can be changed, its servers and projects included. To change a maintenance or an incident, or to complete or resolve one with a report or root cause (or clear one), the agent gives that version back. If someone changed the maintenance since, the agent is told to read it again, and nothing is saved. Completing without a report, and calling off, only need the maintenance to be still planned.

It works the other way too. An edit form or a **Complete** modal left open in the panel doesn't undo what an agent (or another tab) did meanwhile: saving refuses, keeps what the agent wrote, and says the maintenance was changed meanwhile, or that it is no longer planned.

<a name="turning-it-on"></a>
## Turning It On

The MCP server is off until you turn it on in `.env`:

```dotenv
HERDBOOK_MCP_ENABLED=true
```

Then recreate the app containers ([Applying Changes](configuration.md#applying-changes)). While it is off, `/mcp` answers 404, so switching it off again shuts every agent out at once, and keeps their tokens for when it is back on.

<a name="a-token-per-agent"></a>
## A Token per Agent

Each agent gets a token of its own, for an account that has set up two-factor authentication: create the account with `users:create`, sign in once and set it up. Then, on the host (not through an agent):

```bash
# An agent that can only read
docker/compose.sh exec app php artisan agents:create ada@example.com ada-laptop

# An agent that can also write
docker/compose.sh exec app php artisan agents:create ada@example.com deploy-agent --write
```

- The name shows as "via deploy-agent". It starts with a letter or digit, is made of letters, digits, `.`, `_` and `-`, and is unique across all accounts.
- A token expires after 90 days, or after `--days` (1 to 365).
- The command prints the token once, with the address of the MCP server (from `APP_URL`). Herdbook only keeps a hash of the token.
- **Never create a token through an agent, and never paste one into a conversation**: it would go to the agent's model provider and stay in the agent's history. Copy it from your terminal into your keychain.
- **A token works without the account's second factor.** That is why only an account with two-factor authentication gets one, and why a token stops working while the account has none.

<a name="managing-agents"></a>
### Managing Agents

| What | How |
|---|---|
| See every agent, its access, when it expires and when it was last used | `agents:list` |
| Take one away | `agents:revoke deploy-agent` |
| Take away every agent of an account | `agents:revoke --user=ada@example.com` |
| Take away every agent at once | `agents:revoke --all`, or turn the MCP server off |
| An account lost its second factor | `users:reset-two-factor` revokes its tokens too. Turning two-factor authentication off in the profile only pauses them until it is set up again. |

A new password leaves the tokens working: revoke them yourself if the account may be compromised. [Commands](commands.md#agents) has the details.

<a name="connecting-claude-code"></a>
## Connecting Claude Code

On the machine where Claude Code runs: store the token, add the server, trust the certificate if you use the local network, and decide what it may do without asking.

Agents that run in the cloud can't reach Herdbook on your private network; this is for agents on your own machines. Over Tailscale, a machine you're signed in to as a member of `group:herdbook` reaches Herdbook already. A tagged machine, such as a server or a VM, belongs to no user: give its tag a grant to `tag:herdbook` on port 443, like `tag:display` ([Tags and Grants](networking.md#tags-and-grants)).

<a name="the-token-in-your-keychain"></a>
### The Token in Your Keychain

Keep the token out of files. On macOS, put it in your keychain; `security` asks for it, so it stays out of your shell history:

```bash
security add-generic-password -U -a herdbook -s herdbook-mcp -w
```

Then a small script hands it to Claude Code. Save it as `~/.config/herdbook/mcp-headers.sh` (`mkdir -p ~/.config/herdbook` first) and make it executable (`chmod +x`):

```sh
#!/bin/sh
token=$(security find-generic-password -a herdbook -s herdbook-mcp -w) || exit 1
printf '{"Authorization": "Bearer %s"}\n' "$token"
```

On Linux with a desktop keyring (GNOME Keyring, KWallet), store it with `secret-tool` instead:

```bash
secret-tool store --label='Herdbook MCP' service herdbook-mcp account herdbook
```

and replace the `security` line in the script with:

```sh
token=$(secret-tool lookup service herdbook-mcp account herdbook) || exit 1
```

On a machine without a keyring, the script can read the token from a file only you can read (`chmod 600`), outside any repository.

This keeps the token safe at rest (in backups, in dotfiles, from an accidental commit), not from code that runs as you, the agent included.

<a name="the-server"></a>
### The Server

Add Herdbook for your user, so it is available in every project, with the script as its `headersHelper` (an absolute path; `/home/you` on Linux):

```bash
claude mcp add-json --scope user herdbook '{"type": "http", "url": "https://herdbook.<tailnet>.ts.net/mcp", "headersHelper": "/Users/you/.config/herdbook/mcp-headers.sh"}'
```

The address is the one `agents:create` printed: the address you open Herdbook on, with `/mcp` after it. Over Tailscale that is as above. On your local network it is `https://<LAN_IP>/mcp` or `https://<one of your LAN_HOSTNAMES>/mcp`, with `:<LAN_PORT>` if that isn't 443 ([Networking](networking.md)); if `APP_URL` is your Tailscale address, `agents:create` prints that one instead.

Never put the token itself in Claude Code's configuration, on the command line or in a project's `.mcp.json`: the configuration is a plain file, the command line ends up in your shell history, and `.mcp.json` is meant to be committed.

<a name="the-certificate"></a>
### The Certificate

Over Tailscale the certificate is a public one. On your local network it comes from Caddy's own certificate authority: [trust its root certificate](networking.md#trusting-the-root-certificate) on the machine, and Claude Code trusts it as well, as it uses the system's certificates. If it doesn't (an npm install on Node older than 22.15), set `NODE_EXTRA_CA_CERTS` to the path of `herdbook-root.crt` before you start it. Never set `NODE_TLS_REJECT_UNAUTHORIZED=0`: the certificate is what keeps the token from going to a host that only pretends to be Herdbook.

<a name="permissions"></a>
### Permissions

Let the agent read without asking, and have it ask before it writes. In `~/.claude/settings.json`:

```json
{
  "permissions": {
    "allow": [
      "mcp__herdbook__search_inventory",
      "mcp__herdbook__list_maintenance",
      "mcp__herdbook__get_maintenance",
      "mcp__herdbook__list_incidents",
      "mcp__herdbook__get_incident"
    ],
    "ask": [
      "mcp__herdbook__schedule_maintenance",
      "mcp__herdbook__log_maintenance",
      "mcp__herdbook__update_maintenance",
      "mcp__herdbook__complete_maintenance",
      "mcp__herdbook__cancel_maintenance",
      "mcp__herdbook__open_incident",
      "mcp__herdbook__add_timeline_entry",
      "mcp__herdbook__add_incident_action",
      "mcp__herdbook__complete_incident_action",
      "mcp__herdbook__update_incident",
      "mcp__herdbook__resolve_incident"
    ]
  }
}
```

An `ask` rule wins over any `allow`, also over a broad `mcp__herdbook__*`. If you'd rather not be asked when an agent only adds something new (`schedule_maintenance`, `log_maintenance`, `add_timeline_entry`, `add_incident_action`), move those to `allow`; keep the tools that change what is there, and `open_incident`, in `ask`.

Don't count on these rules in a session that runs without permission prompts (`--dangerously-skip-permissions`): give such a session a read token. If you need one, add a second server for it, such as `herdbook-read`, with a read token in a keychain item and a script of its own.

<a name="checking-it-works"></a>
### Checking It Works

- Run the script by hand: it must print `{"Authorization": "Bearer hb_…"}`.
- `claude mcp list` shows `herdbook` as connected.
- Ask Claude Code which servers Herdbook knows.
- On the host, `agents:list` shows when the agent was last used.

<a name="disconnecting"></a>
### Disconnecting

`claude mcp remove --scope user herdbook` removes the server from Claude Code, and `security delete-generic-password -a herdbook -s herdbook-mcp` (on Linux `secret-tool clear service herdbook-mcp account herdbook`) the token from your keychain. Then revoke the agent on the host with `agents:revoke`.

<a name="renewing-a-token"></a>
## Renewing a Token

Nothing warns you before a token expires: the agent just can't connect any more. `agents:list` shows when each one does.

- **Expired:** run `agents:create` again with the same account and name, and `--write` if it could write; it replaces the expired one.
- **About to expire, lost, or needs other access:** `agents:revoke` it first, then create it again.

Then store the new token with the [same command](#the-token-in-your-keychain) as the first time, and start Claude Code again: it reads the token when it connects.

<a name="security"></a>
## Security

- **A token is a key without a second factor.** Treat it like a password.
- **What an agent reads leaves your network.** Everything a tool returns becomes part of the conversation, which goes to the agent's model provider and stays in its local history (for Claude Code, under `~/.claude`). That is why tools return no addresses, notes or contact details, but names, plans and reports are sent as written.
- **Plans and reports are written by people and other agents.** Herdbook tells agents to treat them as data, to act on a plan only when you ask, and to tell you when a plan was changed by someone other than its creator, but that is no guarantee: a text can try to steer an agent. Don't let an agent that carries out a plan run without asking for permission, so you see each command before it reaches your servers.
- **An agent acts within its token.** A misled agent with a write token can plan, change, complete or call off maintenance, and open incidents and add to them: an open SEV1 colours the dashboard and the status screen red. It can't delete anything, and the activity log names it and keeps the plans and reports it replaced, but changes to its servers and projects aren't logged.
- **Revoking.** `agents:revoke` (one, an account's, or all), or switch the MCP server off. A backup restored from before a revoke brings the token back: revoke it again ([Disaster Recovery](backups.md#disaster-recovery)).

The [Security](security.md#agents) page puts this in the context of the rest of Herdbook.

<a name="undoing-what-an-agent-did"></a>
### Undoing What an Agent Did

| What an agent did | How to undo it |
|---|---|
| Created maintenance | Delete it in the panel. |
| Called it off | Edit it and set the status back to *Planned*. |
| Completed it | Set the status back to *Planned*; the times it had before are in the activity log. |
| Changed its work, title or times | The old values are in the activity log. |
| Replaced or cleared a plan or report | The activity log keeps the text it replaced: **Previous text** on that entry shows it, to copy back in the edit form. |
| Changed its servers or projects | Not logged, as for people. |
| Opened an incident, added a timeline entry or an action item | Delete it in the panel. |
| Completed an action item | **Reopen** it in the panel. |
| Resolved an incident, or changed its status or severity | Set it back in the edit form; the activity log has what it was. |
| Replaced or cleared an incident's root cause or report | **Previous text** on that entry in the activity log, as for maintenance. |

<a name="troubleshooting"></a>
## Troubleshooting

A tool's errors come in the language of the token's account; a refused connection comes in the default language (`APP_LOCALE`).

| What you see | Why |
|---|---|
| Claude Code reports the connection as failed or rejected (`claude mcp list`) | Herdbook answered 401: the token is unknown, revoked or expired ([Renewing a Token](#renewing-a-token)). Or the script failed: run it by hand. It must be executable, its path absolute, and the keychain item there. |
| 403 | The account has no two-factor authentication (set it up again), or the request came from a browser, which is always refused. |
| 404 | The MCP server is off (`HERDBOOK_MCP_ENABLED`), or the address is wrong. Opening `/mcp` in a browser tells which: 404 while it is off, 401 while it is on. |
| 429 | More than 120 requests a minute, or too many wrong tokens; it passes within a minute. |
| A certificate error | See [The Certificate](#the-certificate). |
| A tool is missing | The token can only read: revoke it and create it again with `--write` ([Renewing a Token](#renewing-a-token)). |
| "This maintenance changed since you read it" | A person, another agent or a form changed it after the agent read it. The agent reads it again with `get_maintenance` and decides again. |
| "This text is longer than an agent reads whole" | The plan or report is over 10,000 characters: change it in Herdbook itself. |
