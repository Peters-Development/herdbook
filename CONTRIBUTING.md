# Contributing to Herdbook

Thank you for considering a contribution. Bug reports, fixes, translations and ideas are all welcome.

## Before You Start

- **Bugs:** open an issue with what you did, what you expected and what happened instead. Include the version (the commit), and leave out hostnames, addresses and anything else from your own infrastructure.
- **Features:** open an issue first, so we can agree on the shape before you spend time on it. Herdbook stays deliberately small: one organization, a private network, no secrets in the database, no CRM. Read [Design Choices](README.md#design-choices) before proposing something that changes those.
- **Security issues:** never in a public issue. See [SECURITY.md](SECURITY.md).

## Development Setup

Everything runs in Docker; you do not need PHP on your machine. Follow [Trying It Locally](docs/installation.md#trying-it-locally), then:

```bash
docker compose exec app php artisan test --compact
docker compose exec app ./vendor/bin/pint --dirty
```

[docs/development.md](docs/development.md) explains the architecture, the conventions and the places that are the single source of truth for health, costs and alerts.

## Pull Requests

- One subject per pull request, with a short description of the why.
- **Every change is tested.** Add or update a PHPUnit feature test. No test may touch the network: use `Http::fake()` and the fake network probe.
- **Code is English**, user-facing text is translatable: write the English string in `__('…')` and add the Dutch to `lang/nl.json`. The test suite runs in Dutch on purpose, so a missing translation fails it. See [docs/translations.md](docs/translations.md).
- Run Pint before you push.
- A schema change is a new migration; never edit one that has been released.

## Translations

A new language is very welcome. [docs/translations.md](docs/translations.md) shows what to add: `lang/<locale>.json`, `lang/<locale>/formats.php`, Laravel's message files for the language, and its entry in `config/herdbook.php`.

## Developer Certificate of Origin

Every commit in a pull request must be signed off. With the sign-off you certify that you wrote the change, or otherwise have the right to submit it under the project's license, as stated in the [Developer Certificate of Origin](https://developercertificate.org) (below). Pull requests with commits that are not signed off cannot be merged.

Sign off by adding a line to the end of the commit message, with your real name and an email address you can be reached at:

```text
Signed-off-by: Jane Doe <jane@example.com>
```

Git adds it for you with `git commit -s`. Forgot it? `git commit --amend -s` fixes the last commit, and `git rebase --signoff main` fixes all commits on your branch; then push with `--force-with-lease`.

```text
Developer Certificate of Origin
Version 1.1

Copyright (C) 2004, 2006 The Linux Foundation and its contributors.

Everyone is permitted to copy and distribute verbatim copies of this
license document, but changing it is not allowed.


Developer's Certificate of Origin 1.1

By making a contribution to this project, I certify that:

(a) The contribution was created in whole or in part by me and I
    have the right to submit it under the open source license
    indicated in the file; or

(b) The contribution is based upon previous work that, to the best
    of my knowledge, is covered under an appropriate open source
    license and I have the right under that license to submit that
    work with modifications, whether created in whole or in part
    by me, under the same open source license (unless I am
    permitted to submit under a different license), as indicated
    in the file; or

(c) The contribution was provided directly to me by some other
    person who certified (a), (b) or (c) and I have not modified
    it.

(d) I understand and agree that this project and the contribution
    are public and that a record of the contribution (including all
    personal information I submit with it, including my sign-off) is
    maintained indefinitely and may be redistributed consistent with
    this project or the open source license(s) involved.
```

## License

Herdbook is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE). By submitting a contribution, you agree that it is licensed under the same terms.
