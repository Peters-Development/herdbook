# Knowledge

- [Introduction](#introduction)
- [Documents](#documents)
    - [Kinds](#kinds)
    - [Writing in Markdown](#writing-in-markdown)
    - [Revisions](#revisions)
- [Credential References](#credential-references)
    - [What Is Stored](#what-is-stored)
    - [What Is Never Stored](#what-is-never-stored)
    - [Linking to Vaultwarden](#linking-to-vaultwarden)

<a name="introduction"></a>
## Introduction

The **Knowledge** menu group holds what you know about your setup, as opposed to what it consists of: [incidents](incidents.md), [maintenance](maintenance.md), documents and credentials. This page covers the last two.

Documents are your runbooks, manuals and architecture notes, written in markdown and kept with every earlier version. Credentials are not passwords: they are references that tell you which login exists, for what, and where in your password manager to find it.

Both can be **related to** projects, servers, domains, networks and clients (see [Linking Records Together](inventory.md#linking-records-together)), so the runbook for a server and the login for its control panel are on the server's page when you need them.

<a name="documents"></a>
## Documents

A document has a title, a kind and a body. Create one from **Knowledge → Documents**, or from the documents tab of a project, server or other record, which links it from the start.

The search bar searches the titles and the content of all documents.

<a name="kinds"></a>
### Kinds

| Kind | For |
|---|---|
| Runbook | Step by step: what to do when something specific happens. Restoring a backup, failing over, rotating a certificate. |
| Manual | How something works or is used. |
| Architecture | How the pieces fit together, and why. |
| Note | Everything else. |

<a name="writing-in-markdown"></a>
### Writing in Markdown

The body is markdown, written in an editor with a formatting toolbar. It is shown as formatted text, but never as raw HTML: a pasted document cannot run a script in the page.

Uploading images is turned off, because there is nowhere Herdbook would serve them from. Link to images elsewhere instead.

<a name="revisions"></a>
### Revisions

Every time the body of a document changes, the previous version is kept as a revision, with who replaced it and when. The **Earlier versions** tab on a document lists them, newest first, with the first line of each. You can view any revision in full.

**Restore version** puts a revision back as the current body. The content it replaces is kept as a revision too, so restoring never loses anything: you can always go back to the version you just replaced.

Revisions are also why the activity log of a document records that the body changed, but not the full text.

<a name="credential-references"></a>
## Credential References

Herdbook stores **no secrets**. A credential in Herdbook is a reference: it says that a login exists, who or what it is for, and where the secret itself lives. The secret stays in your password manager, ideally [Vaultwarden](vaultwarden.md) or Bitwarden, where it is end-to-end encrypted.

Why not keep passwords in Herdbook, encrypted? Because encryption on the server only protects against a leaked database dump, not against someone who gets into the machine itself: the key would be right there. A password manager that encrypts on your own devices does not have that weakness, and Herdbook can still tell you exactly where to look.

<a name="what-is-stored"></a>
### What Is Stored

| Field | Example |
|---|---|
| Name | SSH deploy key for app-1 |
| Type | SSH key, Panel login, Database, API token or Other |
| Username | `deploy` |
| Host and port | `app-1.example.com`, `22` |
| Vaultwarden item | the id of the item in your vault |
| Notes | free text |

Link the credential to what it opens: a database login to the project and to the server the database runs on, a registrar login to the domains registered there, the router's admin login to the network.

<a name="what-is-never-stored"></a>
### What Is Never Stored

Passwords, private keys and tokens have no field in Herdbook, and never will. To catch mistakes, every free-text field (names, notes, descriptions, documents, incident reports, timeline entries, maintenance plans and reports) refuses anything that looks like a private key:

- a PEM private key block, such as `-----BEGIN OPENSSH PRIVATE KEY-----`, `-----BEGIN RSA PRIVATE KEY-----` or `-----BEGIN ENCRYPTED PRIVATE KEY-----`;
- a PuTTY private key file.

Public keys and certificates are fine: they are not secrets. The check cannot recognize every password, so the forms remind you where secrets belong.

<a name="linking-to-vaultwarden"></a>
### Linking to Vaultwarden

In the **Vaultwarden item** field, paste the link to the item from the address bar of your web vault (open the item and copy the URL), or just the item's id. Herdbook takes the item id out of the link and checks that it is a valid id; anything else is refused.

With `HERDBOOK_VAULT_URL` set to the address of your web vault (see [Configuration](configuration.md)), the credential page shows an **Open in Vaultwarden** link that opens the item directly, and the credentials tab on other pages has a button for it. Without it, Herdbook shows the item id, so you can still search for it.

Opening the link takes you to your vault, where you unlock it as usual: Herdbook itself never sees the secret.
