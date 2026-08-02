# Plan — align the app chassis, widen the alert sources, declare the app in `gestion`

> **Status: agreed 2026-08-02, not started.** Nothing here has been built. Written in English,
> matching this repo's code, its PHPUnit test names and the standing rules on issue #2 — even though
> `README.md` and `CHANGELOG.md` are Spanish, because those are read by the clinic and this is not.

## Context

Three things arrived together and they turn out to share one seam.

1. **The in-app left column does not read like a Nextcloud app.** Not the global `side_menu` drawer
   (that is deliberate platform architecture, `gestion/docs/ARCHITECTURE.md:174`) — this app's own
   navigation.
2. **`Databases/apis/` now catalogues 87 probed Chilean health APIs.** Everything under
   *epidemiología* and *alertas* — the ISP entries especially — should be reviewed and used to
   improve speed, performance and freshness.
3. **News reads whatever it can load. Where it cannot, this app shows it with a cleaner display.**

And a fourth, discovered while mapping: **this app is invisible to `gestion`.** `grep -riI epidemiolog`
over the tracked repo returns **zero matches** — not in `provisioning/phases/12-apps.sh`, not in
`docs/`, not in `README.md`. It exists only as an untracked working tree inside a gitignored
directory. The decision taken is to declare it properly, which reverses AD-1.

### Established, so no session re-derives it

- This app is at **0.2.0**, 31 unit tests, 11/11 smoke, working tree clean on `main`.
- The frontend is already `@nextcloud/vue` (built at 9.9.0, Vue 3.5.40, Vite), data via
  `IInitialState`, contract on OCS with a generated `openapi.json`. No database, no migrations.
  Redis read-through cache.
- The wayfinder map is **issue #2**. Its 15 original tickets are closed; **#18–#21** remain.
- The reference checkout used throughout is
  `/home/dani/Descargas/CESFAM-Intranet/nextcloud-references/nextcloud-vue` (@nextcloud/vue 9.9.0)
  and `.../nextcloud-references/documentation` (the developer manual).

---

## The navigation defect, named precisely

`src/App.vue:3-19` places `NcAppNavigationList` in `NcAppNavigation`'s **default** slot. The library
branches on `$slots.list`
(`nextcloud-references/nextcloud-vue/src/components/NcAppNavigation/NcAppNavigation.vue`):

```
<div class="app-navigation__body" :class="{ 'app-navigation__body--no-list': !$slots.list }">
  <slot />                                    ← our items land here
</div>
<NcAppNavigationList v-if="$slots.list" class="app-navigation__list">
  <slot name="list" />                        ← never rendered
</NcAppNavigationList>
```

Everything below follows from that one slot choice:

- `& &__list { height: 100% }` — the library's *"always stretch the navigation list"* rule —
  **never applies**.
- `app-navigation__body--no-list` takes over instead (`flex: 1 1 auto; overflow: auto; height: 100%`),
  wrapping a `<ul class="app-navigation-list">` that also sets `overflow-y: auto`: **two nested
  scroll containers**.
- The library's legacy escape hatch `&__content > ul { …same styles as NcAppNavigationList }` does
  **not** match, because that `<ul>` is a grandchild of `__content`, not a child.
- Separately, `NcAppNavigation` emits a runtime warning through `watchEffect` because neither
  `ariaLabel` nor `ariaLabelledby` is set. `App.vue` sets neither.

Canonical form, from the library's own `<docs>` block:

```vue
<NcAppNavigation :aria-label="t('epidemiologia', 'Epidemiología')">
  <template #list>
    <NcAppNavigationItem … />
  </template>
</NcAppNavigation>
```

**A second suspect, to be confirmed on screen rather than assumed.** `isLegacy34 = major < 34`
(`nextcloud-vue/src/utils/legacy.ts`), so on NC 34 the navigation is `background-color: transparent`
and `NcContent` carries the frosted chrome (`--color-main-background-blur` + `backdrop-filter`),
with a `border-inline-start` divider and `--body-container-radius` cutouts on `.app-content`. If
core's bundled apps on 34.0.1 ship an **older** `@nextcloud/vue`, then Files and Epidemiología would
legitimately render two different navigation designs on the same instance. That is a measurement
nobody has taken.

---

## Phase 1 — Look before designing

Nothing gets built until the current state is on screen. The stack was down when this was written
(`docker ps` showed only `portainer`; `curl localhost:8180` returned `000`), so none of the above is
verified in the browser.

1. `make up`, wait for `occ status`.
2. Screenshot Epidemiología beside **Files** and **Calendar** at one width. "Looks different from
   official apps" needs an official app in the frame.
3. In devtools, on `#app-navigation-vue`: is `.app-navigation__list` present? Is
   `.app-navigation__body--no-list` present? Is `.app-navigation` transparent while `.content` is
   frosted? Check the console for the `ariaLabel` warning.
4. Read core's shipped `@nextcloud/vue` version; compare with 9.9.0.

Output: a note recording which suspect is real. If the slot is the whole story, Phase 4 is a
five-line diff. If the version mismatch is real, that is a larger and different problem, and it gets
its own decision rather than being folded in here.

---

## Phase 2 — Source review

**Deliverable: a decision table, one row per candidate, each with an outcome. No code.**

Scope: every `catalog.yaml` entry with `scope: in` in categories `epidemiologia` (8), `alertas` (10)
and `tecnico` (3), plus the ISP entries under `medicamentos` / `prestadores`. `catalog.yaml` parses
as `{meta, apis}`, not a bare list.

Three answers this review owes, two of which are already visible:

- **MINSAL alerts and IRAG cannot be made faster.** `catalog.yaml`'s `epi-minsal-feed.notes` settles
  it: both pages are *curated PDF pages, not posts*; their `/feed/` returns **0 items**; the
  per-disease categories have `count: 1`. The only live signal is the page's `modified` field — which
  `ProbeJob` **already** uses, at 859 bytes against a 44 KB full fetch. Record this and stop looking.
  The remaining lever is cadence (`FRESH_FOR = 3600`, probe at 24 h), not transport.
- **The freshness win is a new source, not a faster one.** `ispch-alertas-anamed` fans out to 24
  hourly WordPress feeds, ~138 unique alerts, that the clinic currently receives nowhere.
- **Two things this app depends on are missing from the catalogue**, which makes the catalogue wrong
  about its own consumer. Add both:
  - `https://epi.minsal.cl/wp-json/wp/v2/pages` — what `MinsalRepository::API` actually calls.
  - The MINSAL ETI/IRAG Power BI report embedded via `PageController::TABLERO_HOST`.

Traps to carry forward — each was measured, not read:

| Trap | Consequence |
| --- | --- |
| `www.ispch.gob.cl` and `estadistica.ssmso.cl` serve **only the leaf certificate** | `ssl_verify_result=20`; `IClientService`, curl and Python all fail. Browsers hide it by chasing AIA. |
| Intermediates are `gsgccr6alphasslca2025` (ispch) and `gsrsaovsslca2018` (ssmso) | Follow the leaf's own AIA CA-Issuers URI at run time — that survives CA rotation; a vendored PEM does not. **Never `verify=False`.** |
| ISP's WAF returns an **empty reply** to a literal `curl/*` User-Agent | Reads exactly like a TLS fault. It answers 200 to anything else, News's UA included. |
| `ckan-defunciones-semana-epi` is declared `geoblocked` but answered 200 | The declared status wins whenever the code matches `probe_expect`. Re-probe from Chile. |
| `make verify` fails when the newest probe is over 30 days old | Last probe `2026-08-01`; red on **2026-08-31** unless re-probed. |

Written into `catalog.yaml` (`aps_use` / `notes` on reviewed entries, plus the two new ones) and a
summary issue here. `make verify` must stay green.

> `Databases/apis/` **is not a git repository** and nothing above it is either. Snapshot before any
> large edit — there is no undo.

---

## Phase 3 — Mockup

A self-contained HTML mockup using the real Nextcloud tokens, so it cannot lie about density:
`--default-font-size` 15px · `--default-line-height` 1.5 · `--default-grid-baseline` 4px ·
`--default-clickable-area` 34px · `--border-radius-element` 8px · `--border-radius-container` 12px ·
`--navigation-width` 300px · `--breakpoint-mobile` 1024px. Brand `#7f21fe` primary, `#5315a8`
background. Light theme only — the instance sets `enforce_theme=light` and `disable-user-theming=1`.

Two arrangements, side by side, because where the ISP alerts belong is a genuinely visual choice:

- **A — a fifth pane.** `Alertas ISP` beside `Alertas vigentes`. Two lists, two counters. MINSAL
  oficios and ISP product recalls stay separate, which is what they are.
- **B — merged.** One `Alertas vigentes` list with a source filter (`NcSelect`) beside the existing
  year filter. One place to look; `NcCounterBubble` then counts both sources.

While the mockup is open it should also answer **#20** — the four panes at 360px, which nobody has
ever looked at. The likeliest casualty is `ReportPane`'s `epi-week` hero card, whose `2.6em` number
sits beside a long title in a flex with `min-width: 220px`.

Components come from what is already installed. Of the 99 in `@nextcloud/vue` 9.9.0, the ones this
work can reach for without adding a dependency: `NcAppNavigationCaption` (`is-heading`) to group
MINSAL from ISP, `NcAppNavigationSearch` in the `#search` slot, `NcAppNavigationSettings` in
`#footer`, `NcChip` for a source tag, `NcDateTime` for relative ages, and the
`NcEmptyContent` / `NcNoteCard` / `NcListItem` already in use. **No new dependency, and no charting
library** — nothing in this plan charts anything.

Gate: A or B is picked from the mockup before any component is written.

---

## Phase 4 — Fix the chassis

Small and mechanical once Phase 1 says which suspect is real. In `src/App.vue`:

- Move the `NcAppNavigationItem`s into `<template #list>` and drop the hand-placed
  `NcAppNavigationList` — the library supplies it for that slot.
- Add `:aria-label="t('epidemiologia', 'Epidemiología')"`, which kills the runtime warning.
- Keep `v-show` on the panes. The comment at `App.vue:24-28` is right: `v-if` re-pays Power BI's
  15–20 s paint on every tab switch.
- `NcCounterBubble` stays without `raw` only while the count is 75. If arrangement B wins the count
  grows and `raw` becomes required, or 1042 renders as "1 K".

Then `npm run build` and **commit `js/` and `css/`** — that is this app's deploy contract, and
`scripts/smoke.sh` asserts the bundle matches `src/`.

---

## Phase 5 — ISP alerts

Only after Phase 2's table and Phase 3's pick.

Reuse rather than rebuild. `lib/Service/MinsalRepository.php` already holds every rule this needs:
`IClientService` plus `ICacheFactory->createDistributed()`, `FRESH_FOR = 3600`, `KEEP_FOR = 30 days`,
https-only URL validation in `parse()`, and the rule that **zero items is a failure, never a
result** — that last one is the whole reason a clinician can trust the list. An RSS feed parses
differently from a WordPress page, so the parser differs; the caching, degradation and notification
rules must not.

- `ProbeJob` takes the ISP source through the same `[2, 7, 30]` backoff and the same
  `probe_failures_*` / `probe_failing_since_*` counters. `MinsalReachable` (`ISetupCheck`) reads
  those counters and must still never touch the network — it runs **synchronously** while an
  administrator waits on Administración → Visión general.
- 24 feeds × 10 items, ~13% overlap. Dedupe by link. `robo-hurto` returns 0 items and four others
  return 1, so **an empty feed is normal here** and must not trip the degraded banner. That is a
  different rule from MINSAL's, and writing it down is the point.
- Extend `openapi.json` with `scripts/openapi.sh`, never by hand.

Two release traps, both already in `CHANGELOG.md`: bumping `<version>` in `info.xml` puts the
**whole instance** into "requires upgrade" until `occ upgrade` runs, and adding attribute routes
needs a **container restart** — `CachingRouter` caches the compiled route collection for an hour in
APCu, which is per-SAPI, so clearing it from `occ` does nothing. The symptom is OCS returning
`998 Invalid query` while `occ` lists the route happily.

---

## Phase 6 — Declare the app in `gestion`

Four pieces. The first stands alone and is worth doing regardless of the rest.

**6a — the CA lever. Do this first; it unblocks News too.** A new provisioning phase — that repo's
contract is *a new capability is a new phase file, never an edit to another epic's file*, and phases
sort by **glob, so the prefix must stay two digits**. It follows each leaf's AIA CA-Issuers URI and
runs `occ security:certificates:import`, which **appends** to Nextcloud's shipped bundle
(`CertificateManager::createCertificateBundle()`), so `IClientService` picks it up via
`getAbsoluteBundlePath()`. No Dockerfile, no `update-ca-certificates`, no rebuild. It lands in the
data volume (`data/files_external/rootcerts.crt`): survives a restart, not a volume wipe — so the
phase must be idempotent and re-runnable, like every other phase.

This is the root-cause fix. The 24 ISP feeds fail for News and would fail for this app for the same
reason; one lever in provisioning fixes both consumers, and no app code is involved.

**6b — declare the app.** `provisioning/phases/12-apps.sh` is the entire install surface and its
inventory is one line (`APPS="groupfolders side_menu eurooffice"`), driven by `ensure_vendored_app`,
which wants a tarball plus a `VENDOR` file (`version=`, `sha256=`, `url=`) under
`provisioning/apps/<id>/`. This app is a **git repo, not a store tarball**, so the shape is a real
decision — a vendored tarball cut from a release tag, or a separate phase that expects the
directory. Take it explicitly; do not let it be settled by whichever line is easier to write.
Enablement uses the existing `lib.sh` helpers (`app_restrict_to_groups`, `app_config_set`); this app
already reads a `notify_groups` config key.

**6c — correct three false statements.** All three are load-bearing and all three are wrong today:

- `docs/ARCHITECTURE.md` — *"zero custom PHP in v1"*
- `apps/README.md` — *"v1 ships none (AD-1); the first is the REM app"*
- `README.md:178` — *"No custom app lives here yet"*

This reverses **AD-1**, so it is an ADR rather than an edit: `docs/adr/0003-…`, in the shape of 0001
and 0002. **AD-9 is not reversed** — a custom app may reach Nextcloud only through `OCP\…` public
APIs, this app already complies, and the ADR should say so plainly.

**6d — the doc that does not exist.** `gestion` has no app-UI standard. The only four mentions of
`@nextcloud/vue` anywhere in it are `docs/THEMING-MODEL.md:158` (*"No component restyling, no SCSS,
no core edits, no @nextcloud/vue fork"*), `themes/apsconecta/MAPEO.md:44`, and two comments in
`server.css`. Phase 4 produces exactly one rule worth writing down — use the library's slots as
documented, take spacing from `--default-grid-baseline`, never a raw pixel — and its home is a short
section in `THEMING-MODEL.md` beside rule 1 (`:root` is inert, because Nextcloud scopes its variables
to `body[data-theme-light]` and a server theme loads first, so a Vue app cannot rely on the theme
overriding Nextcloud tokens). One section, not a new document.

---

## Files this touches

**This repo** — `docs/2026-08-02-chassis-sources-and-declaration.md` (this file), `src/App.vue`,
`src/components/AlertsPane.vue` (a new pane or a source filter), `lib/Service/MinsalRepository.php`,
`lib/BackgroundJob/ProbeJob.php`, `lib/Controller/PageController.php`, `openapi.json` (generated),
`js/` and `css/` (built, committed), `tests/unit/`, `scripts/smoke.sh`.

**`Databases/apis`** — `catalog.yaml` only; `exports/` is generated. Unversioned: snapshot first.

**`APS-Conecta/gestion`** — a new `provisioning/phases/NN-*.sh` for the CA import, `12-apps.sh` or a
second new phase for the app, `docs/adr/0003-*.md`, and the three corrections above.

---

## Verification

| What | How |
| --- | --- |
| Navigation renders like an official app | Screenshot beside Files and Calendar at one width; `.app-navigation__list` present in the DOM; no `ariaLabel` warning in the console |
| Mobile | The same four panes at 360px — closes **#20**, never looked at |
| App still correct | `scripts/test.sh` (31 tests) and `scripts/smoke.sh` (11 checks) green. Smoke already asserts the bundle matches `src/`, the old route 404s, OCS 412s without its header, and the CSP allows the tablero domain |
| Catalogue still honest | `make verify` green in `Databases/apis/` — it fails on a probe older than 30 days |
| ISP reachable from the server | Fetch an `ispch.gob.cl` feed from inside the container through the app's own path — **not** with a literal `curl/*` UA, which the WAF answers with an empty reply |
| News unblocked by the same fix | `occ news:opml:import` then `occ news:updater:update-user`; the 24 ISP feeds land instead of vanishing. `FeedServiceV2::create()` throws its fetched items away, so the update step is required, not optional |
| Provisioning idempotent | `make seed` twice changes nothing but the four brand image registrations, which rewrite by design |
| Nothing quietly undeclared | `make divergence` no longer names `epidemiologia` |

---

## Deliberately not in this plan

- **No charts, no local datasets, no `oc_*` tables.** The CKAN sources (`ckan-eno`, `ckan-rem20`,
  `ckan-defunciones`, all CC0) stay recorded and unused. `SPEC.md` rules them out and nothing asked
  for here needs them. They become relevant the day someone asks "how are *we* doing versus the
  región", which is a different product question.
- **No dark theme.** `enforce_theme=light`, `disable-user-theming=1`. **#19** stays open as a
  verification, not a build.
- **No CORS.** **#18** is blocked on a product fact — where the dashboard lives — not on engineering.
- **No dashboard widget.** `IAPIWidgetV2` was considered and rejected; adding it later is additive.
- **No `node_modules` deployment fix.** **#21** is real and separate; the app's own `.htaccess`
  holds meanwhile.
- **The global `side_menu` app is untouched.** The complaint was about this app's own column, and
  `side_menu` is deliberate platform architecture.
