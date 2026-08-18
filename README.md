# Epidemiología

## What this is

Nextcloud 34 app for APS Conecta Gestión, surfacing what MINSAL and the ISP publish inside the
intranet and linking to what cannot be. **It rehosts no state documents, creates no database tables
and touches no patient data.** UI in Spanish, everything else English ([`AGENTS.md`](AGENTS.md)).
AGPL-3.0-or-later; data from the Departamento de Epidemiología (MINSAL) and the ISP de Chile.

| View | What it is | Where it comes from |
| --- | --- | --- |
| **Inicio** | What was published most recently, freshness first | Everything below, already loaded |
| **Alertas vigentes** | The oficios in force, filterable by text, year and month | Public REST API on `epi.minsal.cl` |
| **Informe semanal ETI e IRAG** | The weekly report, by semana epidemiológica | Public REST API on `epi.minsal.cl` |
| **Alertas del ISP** | Recalls, falsifications, pharmacovigilance, device withdrawals | 24 hourly RSS feeds on `ispch.gob.cl` |
| **Tablero ETI e IRAG** | The national dashboard, embedded | Power BI *"Publish to web"* |
| **EPIVIGILA** | What it is, how to get in, and the real access situation | A link, never embedded |

## Status

**0.8.0, 2026-08-08.** All six views ship and the OCS contract is settled at one route
([ADR-0006](docs/adr/0006-one-ocs-route-keyed-by-source.md)). `main` carries eight commits past
`v0.8.0`, listed under `[Unreleased]` in [`CHANGELOG.md`](CHANGELOG.md); `info.xml`, `package.json`
and an instance's `installed_version` all read 0.8.0.

Not done, and each for a reason: the OCS route carries no `#[CORS]`, which needs a concrete origin
rather than a wildcard; nothing propagates the establishment to this app, so it reads
`notify_groups` and its own probe counters and nothing else
([gestion ADR-0013](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0013-the-establishment-is-instance-configuration.md)); `scripts/smoke.sh` and
`scripts/layout.sh` are run by hand. The six tags `v0.3.0`…`v0.8.0` have **no GitHub release page**
and are not owed one: a clinic installs the tarball `gestion` vendors from a tag, and `gestion` bumps
it at a milestone rather than at every tag — that decision and the one command that builds the
tarball live in
[`gestion`'s `provisioning/apps/epidemiologia/VENDOR`](https://github.com/APS-Conecta/gestion/blob/main/provisioning/apps/epidemiologia/VENDOR).

## Quickstart

Four steps, on a machine already running the `gestion` stack.

1. **Clone this repo into `gestion/apps/epidemiologia`** (on the host). `gestion` gitignores
   `apps/*` and bind-mounts the directory at `/var/www/html/custom_apps`, so nothing is copied.
   Provisioning will not clone for you: with the directory absent it installs the vendored release
   tarball instead, and with a half-clone it stops and prints the `git clone` to run.
2. **Enable the app.** `make seed` in `gestion` does it (phase 12), and so does, in the container,
   `docker exec -u www-data apsconecta-gestion-nextcloud-1 php occ app:enable epidemiologia`.
   It answers `epidemiologia … enabled`, or `epidemiologia already enabled` if it was on already.
3. **Prove the daily probe registered** (in the container):
   `docker exec -u www-data apsconecta-gestion-nextcloud-1 php occ background-job:list | grep ProbeJob`.
   Exactly one row is right. **No row means the enable did not register it** — see *Things that
   bite* below.
4. **Open `http://localhost:8180/apps/epidemiologia`** (the host port `gestion/.env` publishes;
   `http://localhost/…` is the *in-container* base, which is why `scripts/smoke.sh` uses it and a
   browser cannot). Inicio lists what was published most recently,
   newest first. From the shell, `scripts/smoke.sh` asserts the same wiring and more.

## How it works

A read-through proxy with a cache, no database. Three rules are not guessable from the code:

- PDFs are **read from the anchors, never constructed**: the upload folder is not derivable from the
  semana epidemiológica (SE 29 sits under `2026/07`, SE 24 under `2026/06`).
- **For MINSAL, zero items is a failure, never a result** — the last good copy keeps being served
  behind a *problemas técnicos* banner. **For the ISP it is the opposite**: one empty feed is normal,
  only failing to reach all 24 is degradation. That disagreement is why `IspRepository` is a sibling
  of `MinsalRepository` rather than a second source inside it.
- The daily job **detects** that a source changed, it does not warm the cache: `TimedJob`, 24 h
  interval, `TIME_INSENSITIVE`, no fixed hour.

## For other apps

**One** read-only OCS route, every source keyed by its id:
`GET /ocs/v2.php/apps/epidemiologia/api/v1/sources?format=json`, with `OCS-APIRequest: true` — **not
optional**, Nextcloud answers `412` without it before reaching the controller.

Narrowing: `source`, `category`, `olderThan`. Unknown `source` answers **404**; a narrowing the fuente
cannot express answers **400**, not a confidently empty list — a category on MINSAL, which publishes
none; a category the ISP does not publish; a cursor that is not an instant. A blank parameter means
"not asked". **Each source carries its own freshness, its own `degraded`, and `total`/`has_more`** —
a narrowed response is capped at 100, so a consumer has to be able to tell a page from the whole set.
Rate limit 120/min per user; no `#[CORS]` yet, it needs a concrete origin.

The contract is [`openapi.json`](openapi.json), generated by `scripts/openapi.sh` from the controller,
never by hand; the one-route shape is [ADR-0006](docs/adr/0006-one-ocs-route-keyed-by-source.md). The
routes before it are **gone, not deprecated** — `/apps/epidemiologia/api/v1/…` since 0.2.0,
`/ocs/…/alerts` and `…/reports` since 0.3.0.

## Development

`scripts/test.sh` runs the unit suite inside the container (PHPUnit 11), `npm test` the frontend
logic (Vitest), `scripts/smoke.sh` the wiring against the running instance, `scripts/layout.sh` the
layout in a real browser at three viewports, `scripts/openapi.sh` regenerates `openapi.json`.

CI (`.github/workflows/ci.yml`) runs on `main` and pull requests, not on every push: the unit suite in
the same `nextcloud:34` image the instance runs, plus a bundle job that runs `npm test`, rebuilds
`src/` and requires `js/` and `css/` to be unchanged.

`scripts/layout.sh` drives headless chromium at **360, 768 and 1366**, after `npm install && npx
playwright install chromium` once. **360 px is only reachable this way** — a real browser window on a
dev machine will not go that narrow.

**`smoke.sh` and `layout.sh` stay manual, deliberately**: their assertions need a provisioned instance
with the app enabled, and their value *is* that they run against a real one. Run both before a
release. Fixtures are **real captured responses** from MINSAL and the ISP, so the parsers are proven
against what those sites actually publish, quirks included.

### Things that bite

- **`occ app:enable` on an already-enabled app does not register the background job.** If `smoke.sh`
  reports it missing, run `occ app:disable` and then `occ app:enable`.
- **Raising `<version>` in `info.xml` puts the whole instance into "requires upgrade".** `occ` drops
  into limited mode and the web routes stop answering until `occ upgrade` runs.
- **The build must emit ONE stylesheet.** Vite's default splits it into a stub that `@import`s a
  content-hashed chunk; Nextcloud's `?v=` cachebuster does not reach the `@import`, so a cached stub
  keeps importing a chunk the next build has deleted — stale CSS or none, silently, and it cost three
  reloads to work out, twice. `build.cssCodeSplit: false` prevents it.
- **The theme cannot be switched, and the platform will not say so.** `enforce_theme=light` here, so
  `occ user:setting <user> theming enabled-themes '["dark"]'` **succeeds and reads back** and changes
  nothing: `ThemesService::getEnabledThemes()` prepends the enforced theme. Forcing `data-theme-dark`
  in the DOM switches the content area but not the navigation, which looks like a broken dark theme.
- **`opcache.revalidate_freq` is 60 s**, so a PHP change can take a minute to show up.
- **Adding or changing attribute routes needs a container restart.** `CachingRouter` keeps the
  compiled routes for **an hour** in APCu, which is per-SAPI: clearing it from `occ` does nothing, the
  CLI and the web not sharing it. Symptom: `998 Invalid query` from OCS while `occ` lists the route.
- **Do not pipe `docker exec … | grep -q`**: `grep` closes the pipe on its first match, the writer
  dies of SIGPIPE, and under `pipefail` a passing check reports as a failure.

## The two traps this app pays for — both measured, both have cost a wrong diagnosis

- **`www.ispch.gob.cl` serves only its leaf certificate.** `ssl_verify_result=20` — Nextcloud's HTTP
  client, curl and Python all fail; browsers hide it by chasing the AIA. `gestion`'s provisioning
  phase `07-certs` imports the intermediate; without it this app is a no-op that reads as an outage.
  **Never `verify=False`.**
- **The ISP's WAF answers a literal `curl/*` User-Agent with an empty reply**, and 200 to anything
  else — which reads exactly like a TLS fault. `IClientService` sends its own UA, so the app is fine;
  do not "simplify" a reproduction down to curl and conclude the host is down.

## Installation

The quickstart's first two steps *are* the install; what differs on a real instance is updating. **No
migrations**: the app creates no tables, so there is nothing to run after a `git pull`. `js/` and
`css/` **are committed**, so deploying needs no Node on the server; anyone touching `src/` runs
`npm run build` and commits the result, and `scripts/smoke.sh` flags a bundle that has fallen behind.
Raising `<version>` in `info.xml` is not free — see *Things that bite*.

> **`node_modules` should not live in the webroot of a production instance.** `.htaccess` closes it
> off — Apache-dependent, and to *anyone*, authenticated or not; see its comments. The right fix is
> to build elsewhere and deploy only what is needed.

## Configuration

One setting is meant to be changed, and it has a working default; everything else in the app's
config is written by the app itself.

| Setting | What it does | Default | Where it lives |
| --- | --- | --- | --- |
| `notify_groups` | Which groups are told when a fuente has been unreachable for 2, 7 or 30 days | `["admin"]`, compiled in — a fresh install has no row | App config: `occ config:app:set epidemiologia notify_groups --value='["admin","epi"]' --type=array` |
| `probe_failures_<fuente>`, `probe_failing_since_<fuente>` | The consecutive-failure counts the notification and *Administración → Visión general* both read | absent until the first probe | App config, written only by `ProbeRecord` — **do not set by hand** |
| `EPI_CONTAINER`, `EPI_USER`, `EPI_BASE` | Which container, user and base URL the dev scripts talk to | `apsconecta-gestion-nextcloud-1`, `admin`, `http://localhost:8180` | Environment, read by `scripts/*.sh` |

Cache lifetimes are constants, not settings: one hour fresh, thirty days kept as the last good copy
(`MinsalRepository`, `IspRepository`). A setting there would let an instance configure itself into
showing an alerta MINSAL has withdrawn as though it were still vigente.

## Licence

AGPL-3.0-or-later — see [`LICENSE`](LICENSE).

This is not a free choice. The app compiles `@nextcloud/vue` (AGPL-3.0-or-later) into the
bundles under `js/`, along with Nextcloud packages under GPL-3.0-or-later, so the copyleft
is inherited from what it links.
[gestion ADR-0010](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0010-agpl-across-the-org.md)
records the organisation-wide decision that followed from it.

Because the AGPL's section 13 covers network use, staff who use this app over the intranet
are owed its source on request.

Third-party components and their notices: [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).
