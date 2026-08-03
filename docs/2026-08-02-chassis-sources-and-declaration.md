# Plan — Inicio and the chassis, then REM as a shared source

> **Status: agreed 2026-08-02, not started.** Nothing here has been built. Revised the same day
> after a grilling session that changed the design substantially — the earlier version treated this
> as a slot fix plus a mockup; it is now four tracks with a dependency line between them.
> Written in English, matching this repo's code and its PHPUnit test names.

## Context

Four things arrived together and share one seam.

1. **The in-app left column does not read like a Nextcloud app.** Not the global `side_menu` drawer
   (deliberate platform architecture, `gestion/docs/ARCHITECTURE.md:174`) — this app's own navigation.
2. **`Databases/apis/` catalogues 87 probed Chilean health APIs.** Review everything under
   *epidemiología* and *alertas* — ISP especially — for speed, performance and freshness.
3. **News reads whatever it can load. Where it cannot, this app shows it with a cleaner display.**
4. **Epidemiology needs the diseases this CESFAM actually manages** — from REM.

Plus: this app is invisible to `gestion`. `grep -riI epidemiolog` over the tracked repo returns
**zero matches**. The decision taken is to declare it properly, which reverses AD-1.

### Established, so no session re-derives it

- This app is at **0.2.0**, 31 unit tests, 11/11 smoke, clean on `main`. Frontend already on
  `@nextcloud/vue` (built at 9.9.0), data via `IInitialState`, contract on OCS. No database.
- The wayfinder map is **issue #2**; its 15 original tickets are closed, **#18–#21** remain.
- Vocabulary is in [`CONTEXT.md`](../CONTEXT.md). Two decisions are in [`adr/`](adr/).

## The navigation defect, named precisely

`src/App.vue:3-19` places `NcAppNavigationList` in `NcAppNavigation`'s **default** slot. The library
branches on `$slots.list`:

```
<div class="app-navigation__body" :class="{ 'app-navigation__body--no-list': !$slots.list }">
  <slot />                                    ← our items land here
</div>
<NcAppNavigationList v-if="$slots.list" class="app-navigation__list">
  <slot name="list" />                        ← never rendered
</NcAppNavigationList>
```

Consequences, all from that one slot choice: the library's *"always stretch the navigation list"*
rule (`& &__list { height: 100% }`) **never applies**; `app-navigation__body--no-list` takes over
(`flex: 1 1 auto; overflow: auto`) wrapping a `<ul>` that also sets `overflow-y: auto`, giving **two
nested scroll containers**; and the legacy escape hatch `&__content > ul` does not match, because the
`<ul>` is a grandchild of `__content`, not a child. Separately, `NcAppNavigation` emits a runtime
warning because neither `ariaLabel` nor `ariaLabelledby` is set.

**A second suspect, to confirm on screen rather than assume.** `isLegacy34 = major < 34`, so on NC 34
the navigation is `background-color: transparent` and `NcContent` carries the frosted chrome. If
core's bundled apps on 34.0.1 ship an older `@nextcloud/vue`, Files and Epidemiología would
legitimately render two different navigation designs on the same instance. Nobody has measured it.

## What the grilling settled

| # | Decision |
|---|---|
| 1 | **Inicio** is a new first pane *inside* the app — not a Dashboard widget, not the intranet home |
| 2 | It shows **what MINSAL last published**, not "new to you" — no per-user state, no first-seen map |
| 3 | **No PDF thumbnails** — see [ADR-0002](adr/0002-no-pdf-rasterisation.md). SE-number cards instead |
| 4 | Inicio = **estado → 3 alertas → 3 informes → accesos**; the trust signal leads |
| 5 | Nav = **Inicio ungrouped, then `NcAppNavigationCaption is-heading` per institution**. This settles the old A/B question: **ISP gets its own pane** |
| 6 | **`analizador_rem` owns REM**; this app consumes over OCS — [ADR-0001](adr/0001-analizador-rem-owns-rem.md) |
| 7 | Disease card shows **Población en Control**; **PIV is an input** held by the REM owner; without PIV, absolute numbers only |
| 8 | **Track A ships first**, then B, then the disease pane |

### Measured this session — do not re-derive

- **DEIS's Datos Abiertos is machine-readable.** `deis.minsal.cl/wp-admin/admin-ajax.php?action=wp_ajax_ninja_tables_public_action&table_id=2889&target_action=get-all-data`
  returns 83 rows as JSON, of which **31 are REM datasets, 2009–2026**, each with a direct URL. The
  page renders that table client-side, so fetching the HTML shows nothing.
- **ZIPs live at** `https://repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_<year>.zip`, and
  the server **supports range requests** — so `Datos/SerieP2026.csv` alone is a **9.6 MB** fetch
  (2.8 s) instead of the 75 MB archive or 460 MB of other series.
- Each ZIP ships `Datos/Serie{A,BM,BS,D,P}<year>.csv` plus `Diccionarios/DICCIONARIO CODIGOS S*.xlsm`.
  CSV columns: `Mes;IdServicio;Ano;IdEstablecimiento;CodigoPrestacion;IdRegion;IdComuna;Col01…Col50`.
- **CESFAM Los Castaños (114302) has 440 Serie P rows at período 6**, out of 625,787 national.
  Serie A has 2,649 rows for us across months 1–5.
- **`Databases/GES/REMS/Datos/SerieP2026.csv` is stale, not empty-by-nature.** It is 373 bytes
  because it was downloaded 23 June; the manual says cortes are collected in junio/diciembre and
  **loaded during julio/enero**, and DEIS refreshed the ZIP on 24 July. It is now 63.7 MB.
- `datos.gob.cl` CKAN does **not** carry the REM series — four searches, zero hits. DEIS's own
  repository is the only source.
- The establishment list is republished **weekly** at the same open-data page.

---

# Track A — this repo, ships first

Depends on nothing outside this repo.

## A1 — Look before designing

The stack was down when this was written (`docker ps` showed only `portainer`). Nothing gets built
until the current state is on screen.

1. `make up`, wait for `occ status`.
2. Screenshot Epidemiología beside **Files** and **Calendar** at one width.
3. In devtools on `#app-navigation-vue`: is `.app-navigation__list` present? Is
   `.app-navigation__body--no-list` present? Is `.app-navigation` transparent while `.content` is
   frosted? Check the console for the `ariaLabel` warning.
4. Read core's shipped `@nextcloud/vue` version; compare with 9.9.0.

If the slot is the whole story, A3 is a small diff. If the version mismatch is real, that is a
different and larger problem and gets its own decision.

## A2 — Source review

**Deliverable: a decision table, one row per candidate, each with an outcome. No code.**

Scope: every `catalog.yaml` entry with `scope: in` in `epidemiologia` (8), `alertas` (10) and
`tecnico` (3), plus the ISP entries. Three answers it owes, two already visible:

- **MINSAL alerts and IRAG cannot be made faster.** `epi-minsal-feed.notes` settles it: both are
  curated PDF pages, not posts; their `/feed/` returns **0 items**. The only live signal is the
  page's `modified` field, which `ProbeJob` already uses at 859 bytes against a 44 KB fetch. Record
  it and stop looking. The remaining lever is cadence, not transport.
- **The freshness win is a new source.** `ispch-alertas-anamed` fans out to 24 hourly feeds, ~138
  unique alerts, that the clinic receives nowhere today.
- **Add the entries the catalogue lacks about its own consumer**: `epi.minsal.cl/wp-json/wp/v2/pages`
  (what `MinsalRepository::API` calls) and the MINSAL Power BI tablero — plus the DEIS REM
  repository, now that it is proven.

Traps to carry forward, all measured:

| Trap | Consequence |
| --- | --- |
| `www.ispch.gob.cl` and `estadistica.ssmso.cl` serve **only the leaf certificate** | `ssl_verify_result=20`; `IClientService`, curl and Python all fail. Browsers hide it by chasing AIA |
| Intermediates `gsgccr6alphasslca2025` (ispch), `gsrsaovsslca2018` (ssmso) | Follow the leaf's AIA CA-Issuers URI at run time — survives rotation. **Never `verify=False`** |
| ISP's WAF returns an **empty reply** to a literal `curl/*` User-Agent | Reads exactly like a TLS fault. 200 to anything else, News's UA included |
| `make verify` fails on a probe older than 30 days | Last probe `2026-08-01`; red on **2026-08-31** |

> `Databases/apis/` **is not a git repository**. Snapshot before any large edit — there is no undo.

## A3 — Chassis and Inicio

`src/App.vue`:

- Move the `NcAppNavigationItem`s into `<template #list>`; drop the hand-placed
  `NcAppNavigationList` — the library supplies it for that slot.
- Add `:aria-label="t('epidemiologia', 'Epidemiología')"`.
- Group with `NcAppNavigationCaption is-heading`: **Inicio** ungrouped at top, then *MINSAL*
  (Alertas vigentes · Informe semanal · Tablero), *ISP* (Alertas sanitarias), *Notificación*
  (EPIVIGILA).
- Reserve the `#footer` slot for settings, per `components.rst`: *"the bottom of the navigation is
  reserved for the settings entry"*.
- Keep `v-show` on the panes. `App.vue:24-28` is right: `v-if` re-pays Power BI's 15–20 s paint on
  every tab switch.
- `NcCounterBubble` stays un-`raw` only while the count is 75.

New `InicioPane.vue` — freshness and any degraded notice first, then the 3 newest alerts with a link
to the full list, then the 3 newest informes as SE cards, then Tablero and EPIVIGILA as buttons.
Everything comes from `IInitialState`; **no new backend call**, because the repository already sorts
newest-first (that is what `sort()`'s 9-inversion fix exists for).

Mockup first, in HTML, on the real tokens so it cannot lie about density: `--default-font-size` 15px
· `--default-line-height` 1.5 · `--default-grid-baseline` 4px · `--default-clickable-area` 34px ·
`--border-radius-element` 8px · `--border-radius-container` 12px · `--navigation-width` 300px ·
`--breakpoint-mobile` 1024px. Light theme only. It also has to answer **#20** — the panes at 360px,
never looked at; the likeliest casualty is `ReportPane`'s `epi-week` card, whose `2.6em` number sits
beside a long title in a flex with `min-width: 220px`.

Then `npm run build` and **commit `js/` and `css/`** — `scripts/smoke.sh` asserts the bundle matches
`src/`.

## A4 — ISP alerts

Reuse rather than rebuild. `MinsalRepository` already holds every rule this needs: `IClientService`
plus `ICacheFactory->createDistributed()`, `FRESH_FOR = 3600`, `KEEP_FOR = 30 days`, https-only
validation in `parse()`, and the rule that **zero items is a failure, never a result**. An RSS feed
parses differently from a WordPress page, so the parser differs; the caching, degradation and
notification rules must not.

- `ProbeJob` takes ISP through the same `[2, 7, 30]` backoff and the same counters.
  `MinsalReachable` must still never touch the network — it runs **synchronously** while an admin
  waits on Administración → Visión general.
- 24 feeds × 10 items, ~13% overlap. Dedupe by link. `robo-hurto` returns 0 items and four others
  return 1, so **an empty feed is normal here** and must not trip the degraded banner. That is a
  different rule from MINSAL's, and writing it down is the point.
- Extend `openapi.json` with `scripts/openapi.sh`, never by hand.

Two release traps, both already in `CHANGELOG.md`: bumping `<version>` puts the **whole instance**
into "requires upgrade" until `occ upgrade` runs; and adding attribute routes needs a **container
restart**, because `CachingRouter` caches the compiled collection for an hour in APCu, which is
per-SAPI. The symptom is OCS returning `998 Invalid query` while `occ` lists the route happily.

---

# Track B — `analizador_rem`, the REM owner

Per [ADR-0001](adr/0001-analizador-rem-owns-rem.md). Not this repo.

**B1 — Review the dataset before depending on it.** `tools/validate_against_deis.php` exists for
exactly this: *"two independent artifacts describing the same thing, so the second one can audit the
first."* It has **never been able to audit Serie P**, because the `SerieP2026.csv` it reads was
empty. Now it can. Its path constant is also stale (`Databases/REMS/Datos/` vs the actual
`Databases/GES/REMS/Datos/`). Known-suspect alongside it: Serie BS has **no arithmetic gate** (51% of
mapped cells stored unverified); `meta_spec_2026.json` maps by label because the OOTT fixes cells
against REM 2025 while the planillas are 2026; Meta VII is 15% in the decreto and 12% in the OOTT;
`ARQUITECTURA.md` still describes `periodo` as `'2026-07'` when what shipped is two columns; and
`package.json` says 0.0.1 against `info.xml` 0.2.0.

**B2 — Install it on the gestión instance.** Today it exists only on a disposable one.

**B3 — DEIS ingester.** A second path into the existing schema: HEAD the ZIP for `last-modified`,
range-extract only the member needed, filter to our DEIS, project into `rem_fact` via the existing
cell map. **Provenance is first-class** — a gated planilla figure and an ungated DEIS figure must
never be indistinguishable. Cadence: Serie P is worth checking in **late julio and late enero**, per
the manual's load window, not continuously.

**B4 — The narrow OCS read API** (that app's own Phase 4), plus **B5 — PIV input** in `IAppConfig`.
Build only what the first consumer needs.

---

# Track C — `gestion`

**C1 — the CA lever, first; it unblocks News too.** A new provisioning phase (a new capability is a
new phase file, and phases sort by **glob**, so the prefix stays two digits) that follows each leaf's
AIA CA-Issuers URI and runs `occ security:certificates:import`, which **appends** to Nextcloud's
shipped bundle so `IClientService` picks it up. No Dockerfile, no rebuild. It lands in the data
volume, so the phase must be idempotent.

**C2 — declare this app.** `12-apps.sh` is the whole install surface and its inventory is one line,
driven by `ensure_vendored_app` (tarball + `VENDOR`). This app is a **git repo, not a store tarball**,
so the shape is a real decision — vendored tarball from a release tag, or a phase that expects the
directory. Take it explicitly.

**C3 — correct three false statements**, all load-bearing and all wrong today:
`docs/ARCHITECTURE.md` *"zero custom PHP in v1"*; `apps/README.md` *"v1 ships none (AD-1)"*;
`README.md:178` *"No custom app lives here yet"*. This reverses **AD-1**, so it is an ADR in
`gestion`, not an edit. **AD-9 is not reversed** — this app already reaches Nextcloud only through
`OCP\…`, and the ADR should say so.

**C4 — one section, not a new document.** `gestion` has no app-UI standard; the only four mentions of
`@nextcloud/vue` are `THEMING-MODEL.md:158`, `MAPEO.md:44` and two comments in `server.css`. A3
produces one rule worth writing down — use the library's slots as documented, take spacing from
`--default-grid-baseline`, never a raw pixel — and its home is beside THEMING-MODEL's rule 1.

---

# Track D — the disease pane

Last, because it needs B2–B5. In this repo: a pane per Serie P programme showing **Población en
Control** at its corte with its source named; the four with decreed prevalence constants (DM2, HTA,
asma, EPOC) also show the ENS figures by age band as national reference; **cobertura efectiva appears
only once PIV is loaded**, and until then the card says so rather than showing a number it cannot
justify. Vocabulary and the traps around it are in [`CONTEXT.md`](../CONTEXT.md).

---

## Verification

| What | How |
| --- | --- |
| Navigation reads like an official app | Screenshot beside Files and Calendar at one width; `.app-navigation__list` present in the DOM; no `ariaLabel` warning in the console |
| Mobile | The panes plus Inicio at 360px — closes **#20** |
| App still correct | `scripts/test.sh` (31) and `scripts/smoke.sh` (11) green; smoke already asserts the bundle matches `src/`, the old route 404s, OCS 412s without its header, and the CSP allows the tablero domain |
| Catalogue still honest | `make verify` green in `Databases/apis/` |
| ISP reachable from the server | Fetch an `ispch.gob.cl` feed from inside the container through the app's own path — **not** with a literal `curl/*` UA |
| News unblocked by the same fix | `occ news:opml:import` then `occ news:updater:update-user`; the 24 ISP feeds land instead of vanishing. `FeedServiceV2::create()` throws its fetched items away, so the update step is required |
| REM figures are real | A Serie P total shown in the UI matches the row for DEIS 114302 in DEIS's own published extract |
| Provisioning idempotent | `make seed` twice changes nothing but the four brand image registrations |
| Nothing quietly undeclared | `make divergence` no longer names `epidemiologia` |

## Deliberately not in this plan

- **No charts we draw, and no CKAN datasets.** `ckan-eno`, `ckan-rem20` and `ckan-defunciones` stay
  recorded and unused. The disease pane shows counts and a decreed constant, not a curve.
- **No incidencia.** No source produces it at this grain — see `CONTEXT.md`.
- **No PDF thumbnails** — [ADR-0002](adr/0002-no-pdf-rasterisation.md).
- **No dark theme.** `enforce_theme=light`. **#19** stays a verification, not a build.
- **No CORS.** **#18** is blocked on a product fact, not on engineering.
- **No dashboard widget.** Considered and rejected; adding it later is additive.
- **No `node_modules` deployment fix.** **#21** is real and separate.
- **No multi-establishment and no regional comparison.** The DEIS extract is national and would
  permit it; `analizador_rem` lists multi-CESFAM as an explicit non-goal, and nothing asked for here
  needs it.
- **The global `side_menu` app is untouched.**
