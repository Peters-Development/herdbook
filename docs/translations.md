# Translations

- [Introduction](#introduction)
- [Choosing a Language](#choosing-a-language)
- [Generated Text](#generated-text)
- [Dates, Times and Amounts](#dates-times-and-amounts)
- [How Translation Works](#how-translation-works)
- [Adding a Language](#adding-a-language)
- [Writing Translatable Code](#writing-translatable-code)
- [Contributing a Translation](#contributing-a-translation)

<a name="introduction"></a>
## Introduction

Herdbook's interface speaks **English** and **Dutch**. Every account picks its own language, and the installation has a default for everything that is not tied to an account. Adding a language takes a few files and one line in `config/herdbook.php`.

<a name="choosing-a-language"></a>
## Choosing a Language

`APP_LOCALE` in `.env` is the **default language** (`en` when it is not set; see [Configuration](configuration.md)). It is used for:

- every account that has not chosen a language of its own;
- the sign-in and two-factor pages, before Herdbook knows who you are;
- alerts, the weekly renewal digest and other messages sent by the scheduler;
- the [status screen](status-screen.md).

Each account can choose a different language under **Language** in its profile (the user menu, top right). *Default (English)*, or whatever the default is, follows `APP_LOCALE`. After saving, the page reloads in the new language. A test alert sent from your profile is in your own language.

<a name="generated-text"></a>
## Generated Text

Some text is written by Herdbook itself and stored, rather than translated each time it is shown. It is written in the language that is active when it is created, and stays that way:

| Text | Written in |
|---|---|
| Titles and timeline entries of [draft incidents](incidents.md#draft-incidents-from-checks) | the default language (they are created by the scheduler) |
| Errors of check results, such as "HTTP 503, expected 200" | the default language, also when you press **Check now** |
| Names of checks made by **Turn on monitoring**, or left empty | the language of whoever created them; a default name still follows a changed redirect in any language |
| Titles of maintenance that you left empty, such as "Updates and reboot" | the language of whoever saved it; such a title still follows the work when someone changes it in another language |

Changing a language later does not rewrite these. The activity log, on the other hand, is shown in the language of whoever reads it: field names, values and descriptions such as "Renewed until …" are translated when they are displayed. Only the names stored with an entry stay as they were written.

<a name="dates-times-and-amounts"></a>
## Dates, Times and Amounts

How dates, times and amounts read is part of the language, in `lang/<locale>/formats.php`:

| Key | English | Dutch |
|---|---|---|
| `date` | `12 Mar 2027` | `12-03-2027` |
| `datetime` | `12 Mar 2027 14:32` | `12-03-2027 14:32` |
| `day_month` | `12 Mar` | `12-03` |
| `day_month_time`, `day_month_time_zone` | `12 Mar 14:32`, with the zone | `12-03 14:32`, with the zone |
| `iso_weekday_day_month` | `Fri 12 Mar` | `vr 12-03` |
| `decimal_separator`, `thousands_separator` | `.` and `,` | `,` and `.` |
| `money` | `€1,234.56` | `€ 1.234,56` |

Date formats are PHP `date()` formats, except keys that start with `iso`, which use Carbon's `isoFormat()` and translate day and month names. `money` places the currency symbol (`:symbol`) and the number (`:amount`).

Two related settings are not part of the language: the **timezone** times are shown in (`HERDBOOK_DISPLAY_TIMEZONE`), and the **currency symbol** (`HERDBOOK_CURRENCY_SYMBOL`, default `€`). Herdbook does not convert currencies: all amounts are in that one currency.

<a name="how-translation-works"></a>
## How Translation Works

Herdbook follows Laravel's [JSON translation strings](https://laravel.com/docs/localization#using-translation-strings-as-keys):

- In the code, every text a user reads is written in **English**, inside `__('…')` or, for plurals, `trans_choice('…|…', $count)`. The English text is the key.
- The **Dutch** translations are in `lang/nl.json`: the English text on the left, the Dutch on the right. English needs no file of its own.
- Placeholders such as `:name` are filled in at runtime and stay the same in every language. Plural forms are separated by `|`.
- The formats per language are in `lang/<locale>/formats.php` (above).
- The languages Herdbook offers are listed in `config/herdbook.php`, under `locales`, each with its name in its own language.

Two sets of strings come from elsewhere:

- **Filament**, the admin framework, ships its own translations for its buttons, tables, forms and sign-in pages, in many languages.
- **Laravel**'s own messages (validation, authentication, password reset and pagination) are PHP files per language. `lang/nl/` holds the Dutch ones (`validation.php`, `auth.php`, `passwords.php`, `pagination.php`); the English ones come with the framework.

When a string has no translation in the chosen language, it is shown in English.

<a name="adding-a-language"></a>
## Adding a Language

Say you want to add German (`de`):

1. **The interface.** Copy `lang/nl.json` to `lang/de.json` and replace every Dutch value with German. Leave the keys (the English text) as they are, keep every `:placeholder`, and keep plural strings plural (with a `|`): how many forms you need follows the language.

2. **The formats.** Copy `lang/en/formats.php` to `lang/de/formats.php` and adjust the date formats, the separators, the place of the currency symbol, and the locale and day-month options the status screen hands to JavaScript's `Intl`. It must have the same keys as the English file.

3. **Laravel's messages.** Add `lang/de/validation.php`, `auth.php`, `passwords.php` and `pagination.php`. You can take them from the community project [Laravel Lang](https://github.com/Laravel-Lang/lang), or publish Laravel's English files with `php artisan lang:publish` and translate them.

4. **The language list.** Add the language to `locales` in `config/herdbook.php`, with its name in its own language:

    ```php
    'locales' => [
        'en' => 'English',
        'nl' => 'Nederlands',
        'de' => 'Deutsch',
    ],
    ```

5. **Filament.** Nothing to do if Filament ships the language, as it does for most. If it does not, Filament's own buttons and labels stay in English. Strings that Filament's translation lacks can be filled in under `lang/vendor/filament-*/<locale>/`, the way `lang/vendor/filament*/nl/` does for Dutch: those files are merged over Filament's own.

6. **Check it.** Run the tests (see [Development](development.md#tests)). They check that every language in the list has a `formats.php` with the same keys as English, and a translation file that is not empty. Completeness is only checked for Dutch: a new language with missing strings still passes, and shows those strings in English. Then sign in, choose the language in your profile, and walk through the pages: some strings only make sense in context.

To make it the default for everyone, set `APP_LOCALE=de` and recreate the app containers (see [Deployment](deployment.md)).

<a name="writing-translatable-code"></a>
## Writing Translatable Code

If you change Herdbook itself, every text a user can read must be translatable:

- Write the English text in `__('…')` (or `trans_choice()`), and add its Dutch translation to `lang/nl.json` in the same change.
- Never format a date with a fixed pattern such as `d-m-Y`: use `__('formats.date')` and the other keys in `formats.php`. Amounts go through the `Money` class, which follows the language and the currency symbol.
- Filament's static label properties (such as `$navigationLabel`) cannot call `__()`. Override the methods instead (`getNavigationLabel()`, `getModelLabel()`, `getTitle()`, …). The menu groups are the `App\Enums\NavigationGroup` enum for the same reason.
- Comments, log messages and command output stay in English and are not translated.
- The plural helpers in the status screen and the map know one and more only; a language with more plural forms needs them extended.

A test (`tests/Feature/TranslationsTest.php`) guards this. It fails when a string in the code has no Dutch translation, when a translation in `lang/nl.json` is no longer used, or when a translation has other placeholders than its key, or is a plural where its key is not (or the other way round). The test suite also runs in Dutch on purpose (`APP_LOCALE=nl` in `phpunit.xml`), so the assertions read the Dutch interface, and a forgotten translation shows up as a failing test rather than as English text in the middle of a Dutch page.

<a name="contributing-a-translation"></a>
## Contributing a Translation

A new language is very welcome. A pull request with `lang/<locale>.json`, `lang/<locale>/formats.php`, Laravel's message files for the language and the entry in `config/herdbook.php` is all it takes. Improvements to an existing translation are just as welcome.

Keep the terms consistent across the interface: if *Environment* is translated one way in the menu, use the same word in forms, alerts and the status screen. See [CONTRIBUTING.md](../CONTRIBUTING.md) for how to open a pull request.
