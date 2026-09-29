# Epidemiología

## What this is

Nextcloud 34 app for APS Conecta Gestión, surfacing what MINSAL, the ISP and the OPS/OMS publish
inside the intranet and linking to what cannot be. **It persists only what its publishers publish — the metadata of every fuente and one copy of each informe's document — and touches no patient data.** The alertas stay links to their publishers' own documents. UI in Spanish, everything else English ([`AGENTS.md`](AGENTS.md)).
AGPL-3.0-or-later; data from MINSAL, the ISP de Chile and the OPS/OMS.

| View | What it is | Where it comes from |
| --- | --- | --- |
| **Inicio** | One tile per alert sector, in navigation order — its latest item whole, and its own degraded state — then the informe's card | Everything below, already loaded |
| **Alertas vigentes** | The oficios in force, filterable by text, year and month | The page MINSAL publishes the in-force set on, at `degreyd.minsal.cl` |
| **Departamento de Epidemiología** | The alertas the Departamento de Epidemiología keeps in force, filterable the same way | Its own page at `epi.minsal.cl` |
| **Tablero ETI e IRAG** | The national dashboard, embedded | Power BI *"Publish to web"* |
| **EPIVIGILA** | What it is, how to get in, and the real access situation | A link, never embedded |
| **Circulación de Virus Respiratorios** | The ISP's weekly informe, by semana epidemiológica | The ISP's feed, persisted as a (year, semana) series; each informe's PDF kept once in the app's own storage |
| **Medicamentos (ANAMED)**, **Farmacovigilancia**, **Vigilancia de cosméticos** | The ISP's alerts in three of its categories, filterable by text, year and month | Each category's feed at `www.ispch.gob.cl`, every item it has shown kept in the app's table |
| **Alertas y actualizaciones** | The OPS/OMS's epidemiological alerts and updates, filterable also by tipo | Its list at `www.paho.org`, kept the same way |

## Status

**0.11.0, 2026-09-28.** Six alert sectors run on one pipeline — MINSAL's two in-force pages, three of the ISP's alert categories and the OPS/OMS's list — and the informe keeps its own series beside them, sharing the ISP host's brake ([ADR-0017](docs/adr/0017-six-alert-sectors-one-pipeline.md), which supersedes [ADR-0016](docs/adr/0016-the-isp-has-one-job.md)). Every fuente persists to the app's two tables and follows its publisher ([ADR-0014](docs/adr/0014-the-fuentes-follow-their-publishers.md), [ADR-0015](docs/adr/0015-the-fuentes-persist-and-the-fetcher-introduces-itself.md)); each informe's document is kept once and served by `/informe/{year}/{semana}`; an hourly job keeps an unopened install within about two hours of its publishers. Ten views ship, Inicio as one tile per sector, and the OCS contract is settled at one route ([ADR-0006](docs/adr/0006-one-ocs-route-keyed-by-source.md)). `main` is tagged `v0.11.0`; `info.xml`, `package.json` and an instance's `installed_version` all read 0.11.0.

**From a datacentre, four sectors have nothing to show, and say so.** `epi.minsal.cl` answers such a host with Cloudflare's 403 and the ISP does not answer it at all (measured 2026-09-27), so the Departamento de Epidemiología's and the ISP's three sectors show their warning and a link to their publisher's page, and the admin bell names each of them, and the informe, on days 2, 7 and 30. A clinic on a residential line reads all six.

Not done, and each for a reason: the OCS route carries no `#[CORS]`, which needs a concrete origin
rather than a wildcard; nothing propagates the establishment to this app, so it reads
`notify_groups` and its own probe counters and nothing else
([gestion ADR-0013](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0013-the-establishment-is-instance-configuration.md)); `scripts/smoke.sh` and
`scripts/layout.sh` are run by hand. The nine tags `v0.3.0`…`v0.11.0` have **no GitHub release page**
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
2. **Enable the app.** `make -C ../.. seed` from this clone runs `gestion`'s provisioning, which does it (phase 12), and so does, in the container,
   `docker exec -u www-data apsconecta-gestion-nextcloud-1 php occ app:enable epidemiologia`.
   It answers `epidemiologia … enabled`, or `epidemiologia already enabled` if it was on already.
3. **Prove the three background jobs registered** (in the container):
   `docker exec -u www-data apsconecta-gestion-nextcloud-1 php occ background-job:list | grep -E 'ProbeJob|RefreshJob|WarmJob'`.
   Exactly three rows are right — the probe that detects, the hourly warm pass that keeps an unopened install current, and the nightly deep pass that walks the back catalogues. **A missing row means the enable did not register it** — see *Things that bite* below.
4. **Open `http://localhost:8180/apps/epidemiologia`** (the host port `gestion/.env` publishes;
   `http://localhost/…` is the *in-container* base, which is why `scripts/smoke.sh` uses it and a
   browser cannot). Inicio shows one tile per alert sector, each with
   the latest thing its publisher posted. From the shell, `scripts/smoke.sh` asserts the same wiring and more.

## How it works

A read-through proxy over a cache and two of its own tables: the cache is the hot path, the tables the durable copy that an app update no longer takes away. Three rules are not guessable from the code:

- PDFs are **read from the anchors, never constructed**: no publisher's filenames are derivable
  from what identifies the document. The ISP appends a version suffix when it reissues a week
  (`…SE35-08-09-2026-V3`), and MINSAL pads two of its own hrefs with a trailing space inside the
  quotes — every parser reads what the publisher serves rather than what a rule would predict.
  A reissue replaces its own week, never a third row — the pair (year, semana) is the identity the
  series persists under.
- **Zero items is a failure, never a result** — the last good copy keeps being served behind a *problemas técnicos* banner, and nothing is written: not to the cache, not to the tables. A fuente with no last copy shows the banner alone, never *Sin alertas*. The six alert fuentes run through one pipeline, `AlertRepository`, one instance each, built from a `Fuente` by `AlertRepositories`: an **in-force** page (degreyd's, epi's) replaces its set, so a withdrawn alert vanishes only when the publisher's own set no longer names it; a **stream** (the ISP's categories, the OPS/OMS's list) keeps every item it has shown and walks two older pages once. The informe keeps its own class, `IspReportRepository`, because a series of kept documents is a rule the pipeline does not have.
- Three jobs, all `TimedJob`: the **probe** (daily) **detects** that a source changed and is the sole writer of the failure counts; the **warm pass** (`WarmJob`, hourly, `TIME_SENSITIVE`, so it also runs outside a maintenance window) keeps an unopened install current; the **deep pass** (`RefreshJob`, daily, `TIME_INSENSITIVE`) walks each stream's older pages and keeps the informes' documents. Only the probe writes counts.

## For other apps

**One** read-only OCS route, every source keyed by its id:
`GET /ocs/v2.php/apps/epidemiologia/api/v1/sources?format=json`, with `OCS-APIRequest: true` — **not
optional**, Nextcloud answers `412` without it before reaching the controller.

Parameters: `source` — and nothing else. The narrowing retired with the only fuente that ever had one: `category` and `olderThan` answer **400** on every source, not a confidently filtered list, so a consumer still paging learns the retirement loudly. Unknown `source` answers **404**; a blank parameter means "not asked". **Each source carries its own freshness, its own `degraded`, and `total`/`has_more`** — every answer is the whole set, so `total` is the fuente's own count and `has_more` is always false.
Rate limit 120/min per user; no `#[CORS]` yet, it needs a concrete origin.

The seven ids, in presentation order: `alerts`, `epi_alerts`, `reports`, `isp_anamed`,
`isp_pharmacovigilance`, `isp_cosmetics`, `paho_alerts`. Every item carries `title`, `url`, `year` and
`month`; the informe's add `se`, the four streams' add `date` (the publisher's day) and the OPS/OMS's
add `tipo` (`alerta`, `actualizacion` or `otro`). A key a fuente does not have is absent, never null.

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

**Without an instance**, `scripts/layout.mjs` still runs checks 6, 10, 11 and 12 against the built
bundle: serve, on 127.0.0.1, a page at `/apps/epidemiologia/` that carries a synthetic
`#initial-state-epidemiologia-sources` (base64 JSON — include a missing fuente, one degraded with
nothing, one answered with nothing and one degraded with a last copy) and a `#epidemiologia` mount
beside the built `js/` and `css/`, then run `EPI_TOKEN=x node scripts/layout.mjs http://127.0.0.1:<port> admin`.
Check 7 and the informe's width at 360 px need Nextcloud's own CSS, so those two are judged on an
instance only.

**`smoke.sh` and `layout.sh` stay manual, deliberately**: their assertions need a provisioned instance
with the app enabled, and their value *is* that they run against a real one. Run both before a
release. Fixtures are **real captured responses** from MINSAL, the ISP and the OPS/OMS, so the parsers are proven
against what those sites actually publish, quirks included.

### Things that bite

- **`occ app:enable` on an already-enabled app does not register the background jobs.** If `smoke.sh`
  reports one missing, run `occ app:disable` and then `occ app:enable`. `occ upgrade` does register
  every one — that is how 0.11.0 adds `WarmJob`.
- **A job's time sensitivity cannot be changed in place.** `oc_jobs.time_sensitive` starts at 1, the
  first run of a `TIME_INSENSITIVE` job writes 0, nothing writes 1 again and an upgrade does not reset
  it — which is why the hourly pass is a new class rather than `RefreshJob` made sensitive. Nor does
  `occ background-job:list` print an interval: read `oc_jobs`, or `occ background-job:execute <id>`'s
  *Next execution* (Nextcloud 34, 2026-09-28).
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
  client, curl and Python all fail; browsers hide it by chasing the AIA. **The app carries the missing
  link itself**: `certs/gsgccr6alphasslca2025.crt` ships in this repo, and `CertificateBundle` merges
  it with the instance's own anchors for the ISP's own requests only — the informe's and its three
  alert feeds'. Verification stays fully on — a
  certificate in a CAfile is an *additional* anchor, not a way around checking one — so **never
  `verify=False`**, still. Measured on a host with no import, 2026-09-16: `cURL error 60` against the
  system anchors alone, `200` against the same anchors with that one file appended. No provisioning
  step stands between a fresh clone and a working fuente any more; on an instance with no writable
  scratch directory the merge is skipped and the ISP degrades exactly as it used to, which is the
  intended failure rather than a fatal inside a page render.

  **A shipped certificate still pins one issuer.** On 2026-09-15 the ISP's leaf was reissued under
  `GlobalSign GCC R6 AlphaSSL CA 2025`, while the certificate in the trust store was still the
  `GlobalSign RSA OV SSL CA 2018` one — so every request failed `cURL error 60` and the fuente was
  dead with nothing saying why. That is the same failure the shipped file will reproduce the next time
  the publisher rotates: **the ISP's leaf expires 2026-11-23**, and nothing here detects a change. When
  the ISP goes quiet, read the chain before assuming an outage:

  ```
  # what the ISP is signed by TODAY, and where its intermediate lives
  openssl s_client -connect www.ispch.gob.cl:443 -servername www.ispch.gob.cl </dev/null 2>/dev/null \
    | openssl x509 -noout -issuer -ext authorityInfoAccess
  # then, if it has changed:
  occ security:certificates:import <the CA Issuers .crt from that output>
  ```

  The `occ` import fixes one instance today; the durable fix is to replace
  `certs/gsgccr6alphasslca2025.crt` with the CA Issuers certificate from that same output, which
  [`certs/README.md`](certs/README.md) spells out, DER conversion included. Nothing hides the fault
  either way: the warning carries the transport error, so the cURL code is in `nextcloud.log`
  without turning the loglevel down to `debug`.
- **The ISP's WAF answers a literal `curl/*` User-Agent with an empty reply**, and 200 to anything
  else — which reads exactly like a TLS fault. The app names itself on every request (`APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)`, `AlertRepository::UA` and `IspReportRepository::UA`), so it is fine; do not "simplify" a reproduction down to curl and conclude the host is down.

## Installation

The quickstart's first two steps *are* the install; what differs on a real instance is updating. Raising `<version>` is now the delivery mechanism: it puts the instance into *requires upgrade* until `occ upgrade` runs, and that upgrade is how the migrations, the repair step and the background jobs reach an install. After a `git pull` that changed `<version>`, run `docker exec -u www-data apsconecta-gestion-nextcloud-1 php occ upgrade`, then restart the web container: the web workers cache the app's routes and state in APCu, which nothing in `occ` clears (see *Things that bite*). **0.11.0's migration rewrites `epidemiologia_alert`'s primary key, and Nextcloud has no down-migrations**: dump the database before that upgrade, because restoring the dump and reinstalling 0.10.0 is the only rollback. `js/` and
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
| `backfilled_<fuente>` | That a stream has walked its two older pages; deleting it makes the next deep pass walk them again | absent until that walk | App config, written only by `AlertRepository::converge()` — **do not set by hand** |
| `EPI_CONTAINER`, `EPI_USER`, `EPI_BASE` | Which container, user and base URL the dev scripts talk to | `apsconecta-gestion-nextcloud-1`, `admin`, `http://localhost:8180` | Environment, read by `scripts/*.sh` |

Cache lifetimes are constants, not settings: one hour fresh, thirty days kept as the last good copy, and a table behind each fuente that outlives the cache (`AlertRepository`, `IspReportRepository`). A setting there would let an instance configure itself into
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
