# Epidemiología

Nextcloud 34 app for APS Conecta Gestión. It surfaces the epidemiological information MINSAL
publishes inside the intranet, and links to what cannot be surfaced.

Five views:

| View | What it is | Where it comes from |
| --- | --- | --- |
| **Inicio** | What was published most recently, freshness first | Everything below, already loaded |
| **Alertas vigentes** | The oficios in force, filterable by text and by year | Public REST API on `epi.minsal.cl` |
| **Informe semanal** | The ETI/IRAG report for the current semana epidemiológica | Public REST API on `epi.minsal.cl` |
| **Alertas sanitarias ISP** | Recalls, falsifications, pharmacovigilance, device withdrawals | 24 hourly RSS feeds on `ispch.gob.cl` |
| **Tablero MINSAL** | The national dashboard, embedded | Power BI *"Publish to web"* |
| **EPIVIGILA** | What it is, how to get in, and the real access situation | A link, never embedded |

It only reads and links public information. **It does not copy or rehost state documents, it creates
no database tables, and it touches no patient data.**

The UI is in Spanish because clinicians read it. Everything else here — code, comments, docs, this
file — is in English; see [`AGENTS.md`](AGENTS.md).

## How it works

A read-through proxy with a cache, and no database:

- Pages are resolved **by slug, never by ID** — IDs change when MINSAL republishes.
- PDFs are **read from the anchors, never constructed**: the upload folder is not derivable from the
  semana epidemiológica (SE 29 sits under `2026/07`, SE 24 under `2026/06`).
- The cache refreshes hourly, but **a read that returns zero items is a failure, never a result**:
  the cache is not overwritten, the last good copy keeps being served behind a *problemas técnicos*
  banner, and whoever administers the instance is told. Without that rule, a change to MINSAL's page
  would replace 75 real alerts with an empty list that a clinician reads as "no hay alertas
  vigentes".
- **The ISP's rule is deliberately different**: one empty feed is normal, and only failing to reach
  any of the 24 is degradation. That disagreement is why `IspRepository` is a sibling of
  `MinsalRepository` rather than a second source inside it.
- A daily job at 03:00 asks only for the modification date (**859 bytes** against 44 KB) to
  **detect** that MINSAL changed. It exists to detect, not to warm the cache.

## For other apps

**One** read-only OCS route, with every source keyed by its id. **The `OCS-APIRequest` header is not
optional**: without it Nextcloud answers `412` before reaching the controller.

```
GET /ocs/v2.php/apps/epidemiologia/api/v1/sources?format=json
OCS-APIRequest: true

{ "ocs": { "meta": { "status": "ok", "statuscode": 200 },
           "data": {
             "alerts": { "updated_at": "2026-07-31T22:15:04",
                         "degraded": false,
                         "items": [ { "title": "OFICIO CP N°15844 / 2026 …",
                                      "url": "https://…pdf", "year": "2026" } ] },
             "reports":         { "…": "…" },
             "sanitary_alerts": { "…": "…" } } } }
```

The data sits inside `ocs.data`. With `format=xml` you get the same thing in XML, for free. **Each
source carries its own freshness and its own `degraded`**, so an ISP outage does not make MINSAL look
stale. Alerts also carry `month`; reports carry `se` (semana epidemiológica); sanitary alerts carry
`date` and `categories`.

**One route rather than one per source**, because that is how Nextcloud serves this kind of consumer
— `dashboard` returns `ocs.data` keyed by widget, `cloud/capabilities` keyed by app — and because
adding a source now adds a key rather than a route. See
[`docs/adr/0006`](docs/adr/0006-one-ocs-route-keyed-by-source.md).

The tablero is not in there: it is a constant URL, with nothing to fetch, parse, cache or degrade.

**The contract lives in [`openapi.json`](openapi.json)**, generated from the controller's attributes
and docblocks with `scripts/openapi.sh` — never by hand, because a hand-written spec drifts from the
code silently, which is worse than having none.

Reading through here beats reading MINSAL directly: a shared cache, an already-parsed format, and the
`degraded` flag.

### Why OCS and not a plain route

Because a browser on another origin cannot be served any other way — enabling CORS on a plain
`Controller` breaks the CSRF check. Rate limit: 120 requests per minute per user. No `#[CORS]` yet;
it needs a concrete origin. Full reasoning, and what the route replaced, in
[ADR-0006](docs/adr/0006-one-ocs-route-keyed-by-source.md).

**The routes before it are gone, not deprecated:** `/apps/epidemiologia/api/v1/…` since 0.2.0, and
`/ocs/…/api/v1/alerts` and `…/reports` since 0.3.0.

## Development

```sh
scripts/test.sh     # unit suite, inside the container (PHPUnit 11)
scripts/smoke.sh    # checks the wiring against the running instance
scripts/layout.sh   # checks the layout in a real browser, at three viewports
scripts/openapi.sh  # regenerates openapi.json from the controller
```

**Two of these run on every push and pull request** (`.github/workflows/ci.yml`): the unit suite, in
the same `nextcloud:34` image the instance runs, and the bundle check that rebuilds `src/` and
requires `js/` and `css/` to be unchanged.

`scripts/layout.sh` is the part of visual checking a machine can do: it drives headless chromium at
**360, 768 and 1366** and asserts three things per pane — nothing wider than the pane that holds it,
no two cards drawn on top of each other, and **nothing with content rendered at zero height**. That
third one exists because the other two cannot see it: a list with six rows and no height does not
overflow and does not overlap, and the landing pane shows no alerts. Seven of this app's fifteen
fixed defects were CSS or markup, and every one was found by a person looking at a screen.

It needs `npm install && npx playwright install chromium` once. **360 px is only reachable this
way** — a real browser window on a dev machine will not go that narrow.

**`scripts/smoke.sh` and `scripts/layout.sh` stay manual, deliberately.** Their assertions need a
provisioned instance with the app enabled, an admin user and an app password — and their value *is*
that they run against a real one, so faking it in CI would test the fake. Run both before a release:
smoke after any change to routes, the CSP or the background job, and layout after any change to CSS
that affects how a pane is arranged.

`scripts/test.sh` covers the only things with real logic: parsing, ordering, the year, the semana
epidemiológica, degradation, accent folding, and the catalogue of sources. The fixtures are **real
captured responses** from MINSAL and the ISP, not invented markup — so the parsers are proven against
what those sites actually publish, quirks included.

`scripts/smoke.sh` checks what no unit test can see: that the app enables, that the daily job is
registered, that the OCS route answers **with its envelope** and refuses with `412` without the
header, that every source appears in the payload, that the old routes 404, that the icon is served,
that **the `js/` bundle matches `src/`**, and that **the CSP allows the tablero's domain**. That last
one is a single line in a controller; without it the tablero renders blank, and it looks like MINSAL
is down rather than like we broke it.

### Things that bite

- **`occ app:enable` on an already-enabled app does not register the background job.** If `smoke.sh`
  reports it missing, run `occ app:disable` and then `occ app:enable`.
- **Raising `<version>` in `info.xml` puts the whole instance into "requires upgrade".** `occ` drops
  into limited mode and the web routes stop answering until `occ upgrade` runs. A `git pull` that
  brings a version change needs that step behind it.
- **The build emits ONE stylesheet, `css/epidemiologia-style.css`, and it must stay that way.**
  Vite's default splits it into a stub that `@import`s a content-hashed chunk. Nextcloud versions the
  stub's URL with `?v=<cachebuster>`, but that query does not reach the `@import` — so a browser
  holding a cached stub keeps importing the PREVIOUS chunk name, a file the next build has already
  deleted, and renders the app with stale CSS or none at all, silently. It cost three reloads to
  work out, twice: the first time it was written down here, the second time it was read too late.
  `build.cssCodeSplit: false` in `vite.config.js` is what prevents it.
- **The theme cannot be switched, and the platform will not say so.** This instance sets
  `enforce_theme=light` and `disable-user-theming=1` (gestion, `provisioning/phases/15-branding.sh`
  — light theme only, an owner decision). Writing `occ user:setting <user> theming enabled-themes
  '["dark"]'` **succeeds and reads back**, and changes nothing: `ThemesService::getEnabledThemes()`
  prepends the enforced theme to whatever the user chose. Forcing it in the DOM half-works —
  `data-theme-dark` on `<body>` switches the content area but not the navigation — which looks
  exactly like a broken dark theme and is not. There is nothing to verify here; see the app's
  issue #19, closed on the same reasoning.
- **`opcache.revalidate_freq` is 60 s**, so a PHP change can take a minute to show up.
- **Adding or changing attribute routes needs a container restart.** `CachingRouter` keeps the
  compiled route collection for **an hour** in the *local* cache (APCu), and APCu is per-SAPI:
  clearing it from `occ` does nothing, because the CLI and the web do not share it. The symptom is
  `998 Invalid query` from OCS while `occ` lists the route quite happily.
- **Do not pipe `docker exec … | grep -q`**: `grep` closes the pipe on its first match, the writer
  dies of SIGPIPE, and under `pipefail` a passing check reports as a failure.

## The two traps this app pays for

Both measured, both look like something they are not, and both have cost a wrong diagnosis.

- **`www.ispch.gob.cl` serves only its leaf certificate.** `ssl_verify_result=20` — Nextcloud's HTTP
  client, curl and Python all fail; browsers hide it by chasing the AIA. The fix is importing the
  intermediate, which `gestion`'s provisioning phase `07-certs` does. Without that phase this app is
  a no-op that reads as an outage. **Never `verify=False`.**
- **The ISP's WAF answers a literal `curl/*` User-Agent with an empty reply**, and 200 to anything
  else. That reads exactly like a TLS fault. `IClientService` sends its own UA, so the app is fine —
  but do not "simplify" a reproduction down to curl and conclude the host is down.

## Installation

The directory goes in `gestion/apps/` (mounted at `custom_apps`) and is enabled with
`occ app:enable epidemiologia`. **There are no migrations to run**: the app creates no tables.

`js/` and `css/` **are committed**, so deploying stays a `git pull` and needs no Node on the server.
Anyone touching `src/` does have to run `npm run build` and commit the result — `scripts/smoke.sh`
reports it if the bundle falls behind.

> **`node_modules` should not live in the webroot of a production instance.** The app's `.htaccess`
> closes it off, but that depends on Apache and on `AllowOverride`; Nextcloud's own `.htaccess`
> whitelists the `js` and `mjs` extensions, so without that protection the ~300 MB of dependencies
> are served to any authenticated user. The right fix is to build elsewhere and deploy only what is
> needed.

## Licence

AGPL-3.0-or-later. Data source: Departamento de Epidemiología, MINSAL, and Instituto de Salud
Pública de Chile.
