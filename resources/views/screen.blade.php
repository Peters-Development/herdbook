<!DOCTYPE html>
{{--
    The status screen for a wall display (StatusScreenController). Holds no
    data: the script below reads the token from the link (/screen#<token>)
    and asks /api/screen every 20 seconds. Plain HTML, CSS and JavaScript,
    no Livewire, readable from four metres. Text only goes in through
    textContent. A change to this file is a new version: open screens
    reload themselves. In the app's default language (APP_LOCALE): the
    texts for the script are rendered into it below.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Status') }} · Herdbook</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('fonts/ibm-plex/ibm-plex.css') }}">
    <style>
        :root {
            --ink: #0e0f11;
            --panel: #17181b;
            --line: #2a2c30;
            --text: #f6f6f6;
            --muted: #9a9ca0;
            --tag: #fdc700;
            --ok: #22c55e;
            --warn: #fb923c;
            --bad: #ef4444;
            --off: #6b6e73;
            --gap: clamp(0.75rem, 1.2vw, 1.5rem);
        }

        * {
            box-sizing: border-box;
            margin: 0;
        }

        html,
        body {
            height: 100%;
        }

        body {
            display: flex;
            flex-direction: column;
            gap: var(--gap);
            padding: var(--gap) calc(var(--gap) * 1.5);
            overflow: hidden;
            background: var(--ink);
            color: var(--text);
            font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
            /* Follows the width without a cap, so a 4K TV reads like full HD. */
            font-size: clamp(14px, 1.05vw, 48px);
            -webkit-font-smoothing: antialiased;
            cursor: none;
        }

        header,
        footer {
            display: flex;
            align-items: center;
            gap: calc(var(--gap) * 1.5);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.6em;
            font-family: 'IBM Plex Sans Condensed', 'IBM Plex Sans', sans-serif;
            font-size: 1.4em;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .brand img {
            height: 1.5em;
        }

        .brand span {
            color: var(--muted);
            font-weight: 500;
        }

        .summary {
            flex: 1;
            font-size: 2em;
            font-weight: 600;
            text-align: center;
        }

        .summary.ok { color: var(--ok); }
        .summary.warn { color: var(--warn); }
        .summary.bad { color: var(--bad); }
        .summary.off { color: var(--muted); }

        .clock {
            font-size: 2.2em;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .clock.tick .colon {
            opacity: 0.25;
        }

        .notice {
            display: none;
            padding: 0.7em 1em;
            border: 2px solid var(--warn);
            border-radius: 0.5rem;
            background: color-mix(in srgb, var(--warn) 14%, var(--panel));
            font-size: 1.3em;
            font-weight: 600;
        }

        .notice.shown {
            display: block;
        }

        .notice.bad {
            border-color: var(--bad);
            background: color-mix(in srgb, var(--bad) 16%, var(--panel));
        }

        /*
         * Tiles of a fixed shape, about four in a row on a full HD screen.
         * More apps than fit: the script (layout()) adds columns until all of
         * them do, and the text shrinks with the tiles. Never a scroll bar.
         */
        main {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(var(--columns, 4), minmax(0, 1fr));
            grid-auto-rows: min-content;
            align-content: start;
            gap: var(--gap);
            min-height: 0;
            overflow: hidden;
        }

        .empty {
            grid-column: 1 / -1;
            padding: 3em;
            color: var(--muted);
            font-size: 1.5em;
            text-align: center;
        }

        /*
         * Sizes inside follow the tile's width (container units refer to the
         * tile from its children on), so text and bars shrink with it.
         */
        .tile {
            --tone: var(--off);
            container-type: inline-size;
            aspect-ratio: 2.1;
            min-height: 0;
            overflow: hidden;
            border: 2px solid var(--line);
            border-left: 6px solid var(--tone);
            border-radius: 0.6rem;
            background: var(--panel);
        }

        .tile-body {
            display: flex;
            flex-direction: column;
            gap: 2cqi;
            height: 100%;
            padding: 4cqi 5cqi;
        }

        .tile.up { --tone: var(--ok); }
        .tile.degraded { --tone: var(--warn); }
        .tile.down {
            --tone: var(--bad);
            position: relative;
            border-color: var(--bad);
            background: color-mix(in srgb, var(--bad) 18%, var(--panel));
        }

        /* The alarm frame pulses by opacity: cheap to draw on a Pi. */
        .tile.down::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            box-shadow: inset 0 0 0 3px var(--bad);
            pointer-events: none;
            animation: alarm 2s ease-in-out infinite;
        }

        @keyframes alarm {
            50% { opacity: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .tile.down::after { animation: none; }
        }

        .name {
            overflow: hidden;
            font-size: max(11px, 7cqi);
            font-weight: 600;
            line-height: 1.15;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* One line: a long "since" is cut short rather than pushing the numbers out. */
        .state {
            display: flex;
            align-items: baseline;
            gap: 0.7em;
            min-width: 0;
        }

        .state .since {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .label {
            color: var(--tone);
            font-size: max(10px, 5cqi);
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .tile.paused .label,
        .tile.unwatched .label,
        .tile.unmonitored .label,
        .tile.unknown .label {
            color: var(--muted);
        }

        .since,
        .numbers,
        .incident {
            color: var(--muted);
            font-size: max(9px, 3.6cqi);
            font-variant-numeric: tabular-nums;
        }

        .incident {
            color: var(--text);
            font-weight: 600;
        }

        .incident:empty {
            display: none;
        }

        .bars {
            display: flex;
            gap: 3px;
            flex: none;
            height: 6cqi;
            margin-top: auto;
        }

        .bar {
            flex: 1;
            border-radius: 2px;
            background: var(--line);
        }

        .bar.ok { background: var(--ok); }
        .bar.warn { background: var(--warn); }
        .bar.bad { background: var(--bad); }

        footer {
            justify-content: space-between;
            color: var(--muted);
        }

        footer .item {
            display: flex;
            align-items: center;
            gap: 0.5em;
        }

        .dot {
            width: 0.7em;
            height: 0.7em;
            border-radius: 50%;
            background: var(--off);
        }

        .dot.ok { background: var(--ok); }
        .dot.warn { background: var(--warn); }
        .dot.bad { background: var(--bad); }

        /* No contact: what is on the screen is old news, and must look it. */
        body.stale main,
        body.stale .summary,
        body.stale footer {
            filter: grayscale(1);
            opacity: 0.45;
        }

        body.stale .tile.down::after {
            animation: none;
            opacity: 0;
        }
    </style>
</head>
<body>
    <header>
        <div class="brand"><img src="{{ asset('favicon.svg') }}" alt="">Herdbook <span>{{ __('Status') }}</span></div>
        <div class="summary off" id="summary">{{ __('Connecting…') }}</div>
        <div class="clock" id="clock"><span id="clock-hours">--</span><span class="colon">:</span><span id="clock-minutes">--</span></div>
    </header>

    <div class="notice" id="notice" role="alert"></div>

    <main id="tiles" aria-live="polite"></main>

    <footer>
        <div class="item"><span class="dot" id="monitoring-dot"></span><span id="monitoring">{{ __('Checks: not known yet') }}</span></div>
        <div class="item"><span class="dot" id="backup-dot"></span><span id="backup">{{ __('Backup: not known yet') }}</span></div>
        <div class="item"><span id="updated">{{ __('Not updated yet') }}</span></div>
    </footer>

    <script>
        (() => {
            'use strict';

            const VERSION = @json($version);
            const ENDPOINT = @json(route('screen.status', absolute: false));
            const POLL_MS = 20_000;
            const REVOKED_POLL_MS = 300_000;
            const TIMEOUT_MS = 10_000;
            const STALE_MS = 120_000;
            const RELOADS_PER_HOUR = 3;
            const RELOAD_HOUR = 4;
            const TILE_RATIO = 2.1;
            const DEFAULT_TIMEZONE = @json(config('herdbook.display_timezone'));
            const INTL_LOCALE = @json(__('formats.intl_locale'));

            /* The texts, translated: :name placeholders, and "one|more" as in trans_choice(). */
            const TEXT = @js([
                'connecting' => __('Connecting…'),
                'noAccess' => __('No access'),
                'noAccessCode' => __('No access code'),
                'noProjects' => __('No projects chosen'),
                'outages' => __(':count outage|:count outages'),
                'attention' => __(':count needs attention|:count need attention'),
                'noMonitoringNeeded' => __('No monitoring needed'),
                'allHealthy' => __('All healthy'),
                'partlyHealthy' => __(':up healthy · :rest without data'),
                'noResults' => __('No check results'),
                'yesterday' => __('yesterday at :time'),
                'minutes' => __(':minutes min'),
                'hoursMinutes' => __(':hours h :minutes min'),
                'days' => __(':count day|:count days'),
                'since' => __('since :time · :duration'),
                'incidentSince' => __(':incident · open since :time'),
                'uptime' => __(':percent in 24 hours'),
                'empty' => __('No projects on the status screen yet. Turn on ":field" for a project in Herdbook.', ['field' => __('On the status screen')]),
                'checksUnknown' => __('Checks: unknown'),
                'checksStalled' => __('Checks stalled'),
                'checksRunning' => __('Checks running'),
                'checksRunningLast' => __('Checks running · last :time'),
                'backupUnknown' => __('Backup: unknown'),
                'backupAt' => __('Backup :time'),
                'backupTooOld' => __('Backup :time · too old'),
                'backupNotSetUp' => __('Backup: not set up'),
                'updated' => __('Updated :time'),
                'noToken' => __('This link is missing its access code. Make one with screens:create.'),
                'revoked' => __('Access revoked: this screen needs a new link.'),
                'offlineSince' => __('No connection to Herdbook since :time. What is shown below is out of date.'),
                'offline' => __('No connection to Herdbook.'),
                'stalledSince' => __('The checks have not run since :time: the status below is out of date.'),
                'stalled' => __('The checks have stopped running: the status below is out of date.'),
            ]);

            /* In one pass: a value that holds a colon is not replaced again. */
            function fill(line, values) {
                return String(line).replace(/:(\w+)/g, (match, name) => (name in values ? String(values[name]) : match));
            }

            function t(key, values = {}) {
                return fill(TEXT[key] ?? key, values);
            }

            /* One and more only: a language with more plural forms needs this extended. */
            function choice(key, count, values = {}) {
                const forms = String(TEXT[key] ?? key).split('|');

                return fill(count === 1 ? forms[0] : forms[forms.length - 1], { count, ...values });
            }

            const element = (id) => document.getElementById(id);

            const token = (() => {
                try {
                    return decodeURIComponent(location.hash.slice(1));
                } catch (error) {
                    return location.hash.slice(1);
                }
            })();

            // Durations run on the monotonic clock: a Pi has no battery clock,
            // and its wall clock jumps when NTP catches up.
            const loadedAt = performance.now();
            let data = null;
            let anchor = null;
            let lastOk = null;
            let lastOkServer = null;
            let generatedAt = null;
            let generatedChanged = performance.now();
            let revoked = false;
            let reloadedOn = null;
            let reloadTriedAt = null;
            let drawnMinute = null;
            const tiles = new Map();
            const formatters = new Map();
            const percent = new Intl.NumberFormat(INTL_LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // A 24-hour clock in every language, as in the app (formats.php).
            const FORMATS = {
                time: { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
                seconds: { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' },
                day: { year: 'numeric', month: '2-digit', day: '2-digit' },
                date: @json(trans('formats.intl_day_month')),
                hour: { hour: 'numeric', hourCycle: 'h23' },
            };

            function serverNow() {
                return anchor ? anchor.server + (performance.now() - anchor.perf) : Date.now();
            }

            /* Intl formatters are costly on a Pi: one per kind, kept. */
            function formatter(kind) {
                const timeZone = data?.timezone ?? DEFAULT_TIMEZONE;
                const key = timeZone + ' ' + kind;

                if (!formatters.has(key)) {
                    formatters.set(key, new Intl.DateTimeFormat(INTL_LOCALE, { timeZone, ...FORMATS[kind] }));
                }

                return formatters.get(key);
            }

            function format(ms, kind) {
                return formatter(kind).format(new Date(ms));
            }

            /* One part of a moment ("hour", "minute"), whatever the language puts between them. */
            function part(ms, kind, type) {
                return formatter(kind).formatToParts(new Date(ms)).find((piece) => piece.type === type)?.value;
            }

            /* "14:32", "yesterday at 14:32" or "21 Oct 14:32", in the page's language. */
            function moment(ms) {
                const now = serverNow();

                if (format(ms, 'day') === format(now, 'day')) {
                    return format(ms, 'time');
                }

                if (format(ms, 'day') === format(now - 86_400_000, 'day')) {
                    return t('yesterday', { time: format(ms, 'time') });
                }

                return format(ms, 'date') + ' ' + format(ms, 'time');
            }

            function duration(ms) {
                const minutes = Math.max(0, Math.floor((serverNow() - ms) / 60_000));

                if (minutes < 60) {
                    return t('minutes', { minutes });
                }

                if (minutes < 48 * 60) {
                    return t('hoursMinutes', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
                }

                return choice('days', Math.floor(minutes / 1440));
            }

            function timeoutSignal(ms) {
                if (typeof AbortSignal.timeout === 'function') {
                    return AbortSignal.timeout(ms);
                }

                const controller = new AbortController();
                setTimeout(() => controller.abort(), ms);

                return controller.signal;
            }

            /*
             * Old news: no good answer for two minutes (also when there never
             * was one since the page loaded), or answers whose content stopped
             * changing (the host answers, but its data does not move on).
             */
            function isStale() {
                if (!token) {
                    return false;
                }

                const now = performance.now();

                return now - (lastOk ?? loadedAt) > STALE_MS || (lastOk !== null && now - generatedChanged > STALE_MS);
            }

            /* The fields this page relies on: anything else counts as no answer. */
            function isValid(fresh) {
                return fresh !== null && typeof fresh === 'object'
                    && Number.isFinite(Date.parse(fresh.now))
                    && typeof fresh.generated_at === 'string'
                    && typeof fresh.version === 'string'
                    && fresh.summary !== null && typeof fresh.summary === 'object'
                    && fresh.monitoring !== null && typeof fresh.monitoring === 'object'
                    && fresh.backup !== null && typeof fresh.backup === 'object'
                    && Array.isArray(fresh.projects)
                    && fresh.projects.every((project) => project !== null && typeof project === 'object'
                        && project.key !== undefined
                        && typeof project.name === 'string'
                        && typeof project.state === 'string'
                        && typeof project.label === 'string'
                        && Array.isArray(project.hours)
                        && Array.isArray(project.incidents));
            }

            function accept(fresh) {
                const now = performance.now();

                revoked = false;
                lastOk = now;
                lastOkServer = Date.parse(fresh.now);
                anchor = { server: lastOkServer, perf: now };

                if (fresh.generated_at !== generatedAt) {
                    generatedAt = fresh.generated_at;
                    generatedChanged = now;
                }

                data = fresh;
            }

            async function poll() {
                let next = POLL_MS;

                try {
                    const response = await fetch(ENDPOINT, {
                        headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
                        cache: 'no-store',
                        signal: timeoutSignal(TIMEOUT_MS),
                    });

                    if (response.status === 401) {
                        // No names on the wall of a screen that lost its access.
                        revoked = true;
                        data = null;
                        next = REVOKED_POLL_MS;
                    } else if (response.ok) {
                        const fresh = await response.json();

                        // Before the shape check: a page that no longer understands
                        // the answers after a deploy must still pick up the new one.
                        if (typeof fresh?.version === 'string' && fresh.version !== VERSION) {
                            reload();
                        }

                        if (isValid(fresh)) {
                            accept(fresh);
                        }
                    }
                } catch (error) {
                    // Offline, a time-out or a broken answer: keep the last
                    // state, isStale() turns the screen grey in time.
                } finally {
                    setTimeout(poll, next);
                }

                draw();
            }

            /*
             * Only straight after the page itself answered: a reload while the
             * host is down would leave Chromium on its own error page for good.
             */
            async function reload() {
                let recent = [];

                try {
                    recent = JSON.parse(sessionStorage.getItem('herdbook-reloads') || '[]').filter((at) => Date.now() - at < 3_600_000);
                } catch (error) {
                    recent = [];
                }

                if (recent.length >= RELOADS_PER_HOUR) {
                    return false;
                }

                try {
                    const response = await fetch(location.pathname, { cache: 'no-store', signal: timeoutSignal(TIMEOUT_MS) });

                    if (!response.ok) {
                        return false;
                    }

                    try {
                        sessionStorage.setItem('herdbook-reloads', JSON.stringify([...recent, Date.now()]));
                    } catch (error) {
                        // Without storage the cap is gone, the reload is not.
                    }

                    location.reload();

                    return true;
                } catch (error) {
                    // Not reachable now: next time.
                    return false;
                }
            }

            function text(node, value) {
                if (node.textContent !== value) {
                    node.textContent = value;
                }
            }

            function tile(project) {
                const key = String(project.key);
                let entry = tiles.get(key);

                if (!entry) {
                    const part = (tag, className, parent) => parent.appendChild(Object.assign(document.createElement(tag), { className }));
                    const root = Object.assign(document.createElement('section'), { className: 'tile' });
                    const body = part('div', 'tile-body', root);
                    const name = part('div', 'name', body);
                    const state = part('div', 'state', body);

                    entry = {
                        root,
                        name,
                        label: part('span', 'label', state),
                        since: part('span', 'since', state),
                        incident: part('div', 'incident', body),
                        bars: part('div', 'bars', body),
                        numbers: part('div', 'numbers', body),
                    };
                    tiles.set(key, entry);
                }

                const className = 'tile ' + project.state;

                if (entry.root.className !== className) {
                    entry.root.className = className;
                }

                text(entry.name, project.name);
                text(entry.label, project.label);

                const since = Date.parse(project.since);
                text(entry.since, Number.isFinite(since) ? t('since', { time: moment(since), duration: duration(since) }) : '');

                const incident = project.incidents[0];
                const incidentSince = Date.parse(incident?.since);
                text(entry.incident, incident
                    ? (Number.isFinite(incidentSince) ? t('incidentSince', { incident: incident.label, time: moment(incidentSince) }) : incident.label) + (project.incidents.length > 1 ? ' (+' + (project.incidents.length - 1) + ')' : '')
                    : '');

                while (entry.bars.children.length < project.hours.length) {
                    entry.bars.append(document.createElement('span'));
                }

                while (entry.bars.children.length > project.hours.length) {
                    entry.bars.lastChild.remove();
                }

                project.hours.forEach((tone, index) => {
                    const bar = 'bar ' + tone;

                    if (entry.bars.children[index].className !== bar) {
                        entry.bars.children[index].className = bar;
                    }
                });

                const numbers = [];

                if (typeof project.uptime === 'number') {
                    numbers.push(t('uptime', { percent: percent.format(project.uptime) + '%' }));
                }

                if (typeof project.response_ms === 'number') {
                    numbers.push(project.response_ms + ' ms');
                }

                text(entry.numbers, numbers.join(' · '));

                return entry.root;
            }

            /*
             * As many columns as tiles of a comfortable width fit (about four
             * at any resolution); when the rows would run off the screen, one
             * column more, until every tile fits.
             */
            function layout(count) {
                const main = element('tiles');

                if (count === 0 || main.clientWidth === 0) {
                    return;
                }

                const gap = parseFloat(getComputedStyle(main).columnGap) || 0;
                const comfortable = Math.max(260, window.innerWidth * 0.22);
                let columns = Math.max(1, Math.floor((main.clientWidth + gap) / (comfortable + gap)));

                while (columns < count) {
                    const width = (main.clientWidth - gap * (columns - 1)) / columns;
                    const rows = Math.ceil(count / columns);

                    if (rows * (width / TILE_RATIO) + gap * (rows - 1) <= main.clientHeight) {
                        break;
                    }

                    columns++;
                }

                if (main.style.getPropertyValue('--columns') !== String(columns)) {
                    main.style.setProperty('--columns', String(columns));
                }
            }

            function summarise() {
                const summary = element('summary');

                if (!data) {
                    summary.className = 'summary off';
                    text(summary, revoked ? t('noAccess') : (token ? t('connecting') : t('noAccessCode')));

                    return;
                }

                // A project that needs no checks is not one "without data".
                const total = data.projects.length - (Number(data.summary.unmonitored) || 0);
                const down = Number(data.summary.down) || 0;
                const degraded = Number(data.summary.degraded) || 0;
                const up = Number(data.summary.up) || 0;

                if (data.projects.length === 0) {
                    summary.className = 'summary off';
                    text(summary, t('noProjects'));
                } else if (down > 0) {
                    summary.className = 'summary bad';
                    text(summary, choice('outages', down) + (degraded > 0 ? ' · ' + choice('attention', degraded) : ''));
                } else if (degraded > 0) {
                    summary.className = 'summary warn';
                    text(summary, choice('attention', degraded));
                } else if (total === 0) {
                    summary.className = 'summary off';
                    text(summary, t('noMonitoringNeeded'));
                } else if (up === total) {
                    summary.className = 'summary ok';
                    text(summary, t('allHealthy'));
                } else if (up > 0) {
                    // Not "all": paused, unwatched or not yet measured apps say nothing.
                    summary.className = 'summary ok';
                    text(summary, t('partlyHealthy', { up, rest: total - up }));
                } else {
                    summary.className = 'summary off';
                    text(summary, t('noResults'));
                }
            }

            /* What comes from the data: on a new answer, and once a minute for the durations. */
            function draw() {
                try {
                    summarise();

                    const main = element('tiles');
                    const projects = data?.projects ?? [];
                    const keys = new Set(projects.map((project) => String(project.key)));

                    for (const [key, entry] of tiles) {
                        if (!keys.has(key)) {
                            entry.root.remove();
                            tiles.delete(key);
                        }
                    }

                    if (!data) {
                        main.replaceChildren();
                        element('monitoring-dot').className = 'dot';
                        element('backup-dot').className = 'dot';
                        text(element('monitoring'), t('checksUnknown'));
                        text(element('backup'), t('backupUnknown'));
                        text(element('updated'), '');

                        return;
                    }

                    layout(projects.length);

                    if (projects.length === 0) {
                        main.replaceChildren(Object.assign(document.createElement('p'), {
                            className: 'empty',
                            textContent: t('empty'),
                        }));
                    } else {
                        // In a fixed order (by name): a tile never moves.
                        main.querySelector('.empty')?.remove();
                        projects.forEach((project, index) => {
                            const node = tile(project);

                            if (main.children[index] !== node) {
                                main.insertBefore(node, main.children[index] ?? null);
                            }
                        });
                    }

                    const monitoring = data.monitoring;
                    const lastResult = Date.parse(monitoring.last_result_at);
                    element('monitoring-dot').className = 'dot ' + (monitoring.stalled ? 'bad' : 'ok');
                    text(element('monitoring'), monitoring.stalled
                        ? t('checksStalled')
                        : (Number.isFinite(lastResult) ? t('checksRunningLast', { time: moment(lastResult) }) : t('checksRunning')));

                    const backup = data.backup;
                    const lastBackup = Date.parse(backup.last_success_at);
                    element('backup-dot').className = 'dot ' + (Number.isFinite(lastBackup) ? (backup.stale ? 'bad' : 'ok') : '');
                    text(element('backup'), Number.isFinite(lastBackup)
                        ? t(backup.stale ? 'backupTooOld' : 'backupAt', { time: moment(lastBackup) })
                        : t('backupNotSetUp'));

                    text(element('updated'), lastOkServer !== null ? t('updated', { time: format(lastOkServer, 'seconds') }) : '');
                } catch (error) {
                    console.error(error);
                }
            }

            /* Every second: the clock, the notice and whether the screen is old news. */
            function tick() {
                try {
                    const now = serverNow();

                    text(element('clock-hours'), part(now, 'time', 'hour') ?? '--');
                    text(element('clock-minutes'), part(now, 'time', 'minute') ?? '--');
                    // The colon blinks: a frozen browser shows within a second.
                    element('clock').classList.toggle('tick', Math.floor(performance.now() / 1000) % 2 === 0);
                    document.body.classList.toggle('stale', isStale());

                    const notice = element('notice');

                    if (!token) {
                        notice.className = 'notice shown bad';
                        text(notice, t('noToken'));
                    } else if (revoked) {
                        notice.className = 'notice shown bad';
                        text(notice, t('revoked'));
                    } else if (isStale()) {
                        notice.className = 'notice shown bad';
                        text(notice, lastOkServer !== null ? t('offlineSince', { time: moment(lastOkServer) }) : t('offline'));
                    } else if (data?.monitoring.stalled) {
                        const lastResult = Date.parse(data.monitoring.last_result_at);
                        notice.className = 'notice shown';
                        text(notice, Number.isFinite(lastResult) ? t('stalledSince', { time: moment(lastResult) }) : t('stalled'));
                    } else {
                        notice.className = 'notice';
                    }

                    // Once a night a fresh page, also for a screen gone grey: reload()
                    // first checks that the host answers. A failed try is repeated
                    // every five minutes within that hour.
                    const today = format(now, 'day');

                    if (Number(part(now, 'hour', 'hour')) === RELOAD_HOUR && reloadedOn !== today && performance.now() > 3_600_000
                        && (reloadTriedAt === null || performance.now() - reloadTriedAt > 300_000)) {
                        reloadTriedAt = performance.now();
                        reload().then((reloading) => {
                            if (reloading) {
                                reloadedOn = today;
                            }
                        });
                    }

                    const minute = Math.floor(now / 60_000);

                    if (minute !== drawnMinute) {
                        drawnMinute = minute;
                        draw();
                    }
                } catch (error) {
                    console.error(error);
                }
            }

            setInterval(tick, 1_000);

            // A notice that appears or goes takes room from the tiles: lay them out again.
            new ResizeObserver(() => layout(data?.projects.length ?? 0)).observe(element('tiles'));
            tick();

            if (token) {
                poll();
            }
        })();
    </script>
</body>
</html>
