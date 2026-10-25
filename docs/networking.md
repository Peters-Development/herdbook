# Networking

- [Introduction](#introduction)
- [Tailscale](#tailscale)
    - [Tailnet Settings](#tailnet-settings)
    - [Machine Names](#machine-names)
    - [Tags and Grants](#tags-and-grants)
    - [Auth Keys](#auth-keys)
    - [How the Sidecar Works](#how-the-sidecar-works)
    - [Services on Your Tailnet](#services-on-your-tailnet)
    - [Verifying Nothing Listens on the LAN](#verifying-nothing-listens-on-the-lan)
- [Local Network](#local-network)
    - [How It Works](#how-the-lan-profile-works)
    - [Setting Up](#setting-up-the-local-network)
    - [Trusting the Root Certificate](#trusting-the-root-certificate)
    - [Local DNS](#local-dns)
    - [Who Can Reach It](#who-can-reach-it)
    - [Verifying](#verifying-the-local-network)
- [Both at Once](#both-at-once)
- [What Herdbook Itself Reaches](#what-herdbook-itself-reaches)

<a name="introduction"></a>
## Introduction

Herdbook is built for a private network. It knows which servers you run, where you sign in and what breaks when, so it doesn't belong on the open internet. You choose how to reach it with `COMPOSE_PROFILES` in `docker/box.env` ([Profiles](deployment.md#profiles)):

- **`tailscale`**: only devices on your tailnet reach it, at home and on the road. Nothing is published on the host at all.
- **`lan`**: every device on your local network reaches it over HTTPS, without Tailscale, through Caddy with a certificate authority of its own.
- **Both**: `tailscale,lan`.

Either way, signing in takes a password and a second factor. The network is the first line, not the only one.

<a name="tailscale"></a>
## Tailscale

With the `tailscale` profile, Herdbook joins your tailnet as a node of its own, called `herdbook`, and `tailscale serve` publishes it on `https://herdbook.<tailnet>.ts.net` with a real certificate. No port is opened on the host or in your router.

<a name="tailnet-settings"></a>
### Tailnet Settings

In the Tailscale admin console, on the **DNS** page, turn on **MagicDNS** and **HTTPS Certificates**. Without both, the `*.ts.net` names and their certificates don't exist. The same page shows your tailnet's domain (`<tailnet>.ts.net`), which the `vaultwarden` profile needs as `TAILNET_DOMAIN`.

<a name="machine-names"></a>
### Machine Names

Tailscale gets the HTTPS certificates from a public certificate authority, and every certificate it issues ends up in the public [Certificate Transparency](https://certificate.transparency.dev) logs, machine name included. Anyone can search those logs. That is why the nodes have neutral names, fixed in `docker/docker-compose.prod.yml`: `herdbook` for the app and `vault` for the vault. Never give a node a client's or a project's name.

<a name="tags-and-grants"></a>
### Tags and Grants

Each node gets a tag, and grants decide who may reach which tag. **Add** these to your existing policy file (Access controls in the admin console); don't replace the policy:

```json
{
  "groups": {
    "group:herdbook": ["you@example.com"]
  },
  "tagOwners": {
    "tag:herdbook": ["autogroup:admin"],
    "tag:vault": ["autogroup:admin"],
    "tag:herdbook-host": ["autogroup:admin"],
    "tag:backup": ["autogroup:admin"],
    "tag:display": ["autogroup:admin"]
  },
  "grants": [
    { "src": ["group:herdbook"], "dst": ["tag:herdbook", "tag:vault"], "ip": ["tcp:443"] },
    { "src": ["tag:herdbook-host", "group:herdbook"], "dst": ["tag:backup"], "ip": ["tcp:8000"] },
    { "src": ["tag:display"], "dst": ["tag:herdbook"], "ip": ["tcp:443"] }
  ]
}
```

| Tag | Which node | Needed for |
|---|---|---|
| `tag:herdbook` | The app's sidecar (`herdbook`) | The `tailscale` profile |
| `tag:vault` | The vault's sidecar (`vault`) | The `vaultwarden` profile |
| `tag:herdbook-host` | The host itself, when it runs Tailscale | A backup to a NAS, and services on your tailnet |
| `tag:backup` | The NAS that runs the backup server | A [backup on a NAS](backups.md#on-a-nas) |
| `tag:display` | A wall display | The [status screen](status-screen.md#network-access) |

The first grant lets the people in `group:herdbook` reach the app and the vault on HTTPS. The second lets the host write its backup to the NAS, and lets you reach the NAS from your own machine: without it you can neither test the backup from your laptop nor restore it when the host is gone. The third lets a wall display reach the app. Leave out what you don't use.

> [!WARNING]
> If your policy still has an "everything may reach everything" rule (`"src": ["*"], "dst": ["*"]`), everyone on your tailnet can still reach Herdbook. These grants only restrict something once that broad rule is gone or narrowed. Think of housemates, family members or other devices on the same tailnet.

<a name="auth-keys"></a>
### Auth Keys

Each sidecar signs in to your tailnet once, with an auth key. Create them in the admin console under **Settings → Keys**: one with the tag `tag:herdbook`, and one with `tag:vault` if you use the vault. Make them **not ephemeral** and **not reusable**. If your tailnet requires device approval, make them pre-approved as well.

Put them in `docker/box.env` as `TS_AUTHKEY_HERDBOOK` and `TS_AUTHKEY_VAULT`. After the first start, the node identity lives in a Docker volume (`herdbook_ts-herdbook-state`, `herdbook_ts-vault-state`) and the keys are no longer needed: [remove them](deployment.md#removing-the-auth-keys). Tagged nodes don't expire.

You only need a new key when the state volume is lost, for example on a new host. Remove the old node in the admin console first; otherwise the new one is called `herdbook-1`, and `APP_URL` no longer matches.

<a name="how-the-sidecar-works"></a>
### How the Sidecar Works

- The sidecar `ts-herdbook` runs the official Tailscale image with kernel networking (`/dev/net/tun`), and advertises `tag:herdbook`.
- nginx (`web`) shares the sidecar's network namespace and listens on its loopback only, `127.0.0.1:80`. Nothing on the tailnet reaches plain HTTP.
- `tailscale serve` takes HTTPS on port 443 and proxies it to that loopback port. The configuration is `docker/tailscale/herdbook/serve.json`, which also turns **Funnel off**: Herdbook is never published to the internet through Tailscale.
- nginx tells PHP that every request arrived over HTTPS, and Laravel trusts the proxy on `127.0.0.1` only, so it sees the client's real tailnet address in `X-Forwarded-For`. Herdbook doesn't use the `Tailscale-User-*` headers for anything: signing in always takes the password and the second factor.

The vault works the same way with its own sidecar `ts-vault`, on a Docker network of its own, so the vault can't reach PHP or MySQL.

<a name="services-on-your-tailnet"></a>
### Services on Your Tailnet

The app containers reach the tailnet through the host. That matters when a check targets a machine on your tailnet, or when the heartbeat monitor or your own ntfy server lives there. Then:

1. Run [Tailscale on the host](deployment.md#tailscale-on-the-host), with `tag:herdbook-host`.
2. Add a grant from `tag:herdbook-host` to that machine and port, like the one to `tag:backup` above.

MagicDNS names don't always resolve inside the containers, depending on how the host resolves names. If one doesn't, use the machine's tailnet IP address instead.

The grants of `tag:herdbook-host` are also the place to limit what the containers may reach on your tailnet, rather than firewall rules on the host.

<a name="verifying-nothing-listens-on-the-lan"></a>
### Verifying Nothing Listens on the LAN

With only the `tailscale` profile, the host publishes nothing:

```bash
sudo ss -ltnp | grep -v 127.0.0       # no docker-proxy or nginx
curl -m 5 http://<LAN_IP>             # from another device on your network: must fail
```

Then turn Tailscale off on your phone and open `https://herdbook.<tailnet>.ts.net`: it must not load. With Tailscale on, it loads, and you can sign in with your second factor.

<a name="local-network"></a>
## Local Network

With the `lan` profile, every device on your local network reaches Herdbook over HTTPS, without Tailscale. That suits a household or an office where not every device can run Tailscale.

<a name="how-the-lan-profile-works"></a>
### How It Works

- **Caddy** publishes HTTPS on `LAN_IP:LAN_PORT`, and on that one address only.
- Its certificates come from **its own certificate authority** (`tls internal`), for `LAN_IP` and every name in `LAN_HOSTNAMES`. HTTPS is not optional here: over plain HTTP, your password, your second factor and the session cookie would cross the network readable for anyone.
- Caddy forwards to a second nginx, `web-lan`, which only listens on the internal Docker network.
- Port 80 is not published, so there is no redirect from HTTP to HTTPS: always type `https://`.
- The CA and its certificates live in `DATA_DIR/caddy`, and are part of the [backup](backups.md#what-is-in-a-backup).

<a name="setting-up-the-local-network"></a>
### Setting Up

1. Give the host a fixed address on your network, for example with a DHCP reservation in your router.
2. In `docker/box.env`, set:
   - `COMPOSE_PROFILES=lan`, or `tailscale,lan` (with `,vaultwarden` if you use the vault);
   - `LAN_IP` to that fixed IPv4 address. `docker/compose.sh` refuses anything else, including `0.0.0.0`, which would publish HTTPS on every network of the host, the tailnet included;
   - optionally `LAN_HOSTNAMES`: local names for the host, separated by **spaces**, such as `herdbook.home.arpa` ([Local DNS](#local-dns));
   - `LAN_PORT` if 443 is taken.
3. Without Tailscale, set `APP_URL` in `.env` to the address you use, and recreate the app containers ([Changing Settings](deployment.md#changing-settings)).
4. Run `docker/up.sh`.

If `LAN_PORT` is not 443, put the port in the address and in `APP_URL`: `https://<LAN_IP>:<LAN_PORT>`.

Caddy gets a separate start in `docker/up.sh`: when it can't bind `LAN_IP` (the host got another address, or something else holds the port), the rest of the stack still starts, so a problem on your network never stops the checks.

<a name="trusting-the-root-certificate"></a>
### Trusting the Root Certificate

Your browser trusts Caddy's certificates once the CA's root certificate is installed on the device. Copy it from the host:

```bash
sudo cat /srv/herdbook-data/caddy/caddy/pki/authorities/local/root.crt > herdbook-root.crt
```

Then install it on every device that opens Herdbook:

| Device | How |
|---|---|
| macOS | Double-click the file to open Keychain Access and add it to the **System** keychain. Open the certificate, expand **Trust** and choose **Always Trust**. |
| iPhone, iPad | Send the file with AirDrop or by email and open it. Install the profile under **Settings → Profile Downloaded**, then turn on full trust for it under **Settings → General → About → Certificate Trust Settings**. |
| Android | **Settings → Security → Encryption & credentials → Install a certificate → CA certificate**. The menu names differ per brand. Chrome then trusts it; Firefox only when you allow third-party certificates in its settings. |
| Windows | Double-click the file, choose **Install Certificate → Local Machine**, and place it in **Trusted Root Certification Authorities**. |

After that, `https://<LAN_IP>` and your `LAN_HOSTNAMES` load without a warning. The root certificate stays valid for about ten years; Caddy renews the certificates it issues by itself.

> [!WARNING]
> Whoever holds the key of this CA can impersonate **any website** to the devices that trust its root. The key lives on the host (`DATA_DIR/caddy`, readable for root only) and in the encrypted backup, which a NAS that [prunes it](backups.md#on-a-nas) can read too. If the host or the NAS is compromised, or the host is gone, remove the root certificate from your devices. If you'd rather not have a CA of your own on your devices, use only the `tailscale` profile.

<a name="local-dns"></a>
### Local DNS

`https://<LAN_IP>` always works. For a friendlier name, add a DNS record in your router (or your local DNS server) that points a name such as `herdbook.home.arpa` to `LAN_IP`, and put that name in `LAN_HOSTNAMES`. `home.arpa` is the domain reserved for home networks. Caddy issues a certificate for every name in the list, so run `docker/up.sh` after changing it.

To have Herdbook watch this way in, add the name as a hostname with **Only on the local network** and turn on monitoring ([Names on the Local Network](monitoring.md#names-on-the-local-network)).

<a name="who-can-reach-it"></a>
### Who Can Reach It

Everyone on the same network as the host sees the sign-in page; signing in takes the password and the second factor. Keep in mind:

- Docker opens published ports past `ufw`, so a `ufw` rule on the host doesn't stop them.
- A firewall rule in your router only helps between networks (VLANs): traffic within one network never passes the router.

So, to keep guests and gadgets away:

- put the guest Wi-Fi and IoT devices in a VLAN of their own, with a rule that keeps them away from `LAN_IP`;
- or put the host in a VLAN of its own, with a rule that only lets your own network reach `LAN_IP` on `LAN_PORT`;
- or filter on the host itself, in the `DOCKER-USER` iptables chain, which Docker leaves to you. For example, to allow only `192.168.1.0/24`:

  ```bash
  sudo iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdst <LAN_IP> --ctorigdstport <LAN_PORT> ! -s 192.168.1.0/24 -j DROP
  ```

  A rule like this is gone after a reboot. Make it permanent in a `DOCKER-USER` block in `/etc/ufw/after.rules`, as the [ufw-docker](https://github.com/chaifeng/ufw-docker) project describes. Don't use `iptables-persistent`: on Ubuntu it removes `ufw`, and it would also save Docker's own rules with addresses that go stale. A rule on the host is not part of the backup, so set it up again on a [new host](backups.md#moving-to-another-host).

<a name="verifying-the-local-network"></a>
### Verifying

```bash
sudo ss -ltnp | grep -v 127.0.0       # only <LAN_IP>:<LAN_PORT> (docker-proxy), nothing else from Docker
```

From a device with the root certificate, `https://<LAN_IP>` loads without a warning and you can sign in with your second factor. From the guest network, it doesn't load.

The vault stays on the tailnet, also with the `lan` profile.

<a name="both-at-once"></a>
## Both at Once

`COMPOSE_PROFILES=tailscale,lan` runs both ways in side by side: `web` behind the Tailscale sidecar and `web-lan` behind Caddy, from the same image and the same nginx configuration. Set up both as described above.

`APP_URL` can only hold one address. Choose the tailnet name: links in alerts then work wherever you are, as long as Tailscale is on.

<a name="what-herdbook-itself-reaches"></a>
## What Herdbook Itself Reaches

Herdbook doesn't only receive traffic. From the host, it reaches out to:

- **The targets of your checks**: anything the host can reach, including addresses on your local network and your tailnet. That is deliberate ([Security](security.md#what-checks-can-reach)).
- **Two reference URLs** (`https://one.one.one.one/cdn-cgi/trace` and `https://www.google.com/generate_204` by default) when a check fails, to tell whether the host's own internet connection is down ([Configuration](configuration.md#checks)).
- **Your alert channels**: the Pushover API, your ntfy server and your SMTP server.
- **Your heartbeat URL**, every five minutes, if you set one.

The browser side stays on Herdbook's own origin: fonts, icons and scripts are served by Herdbook itself, and the vault doesn't fetch website icons either.
