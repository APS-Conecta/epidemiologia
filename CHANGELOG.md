# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Semantic versioning, still on `0.x`: **an incompatible change raises the minor.**

Written in English, like the rest of this repo outside the UI — see [`AGENTS.md`](AGENTS.md).

**One line per change, naming what moved for someone using the app or its API.** Why a change was
made belongs in the commit, the issue, or a comment beside the code. This file had grown into a
design diary — 517 lines, with `### Changed` appearing twice inside one entry — and was cut back
in #54.

## [Unreleased]

### Fixed

- One ISP walk at a time. Every stale page load used to start its own 24-feed fan-out at 15 s a
  feed, holding a worker for minutes; eight tabs exhausted the PHP-FPM pool for the whole instance.
  A cold cache still fetches, because "no alerts" is not an answer we may invent (#76).
- A response larger than 512 KB is not read. `timeout` was the only bound, and a 3 MB feed exhausts
  the image's 512 MB inside the parser — reproduced (#76).
- The revision marker was stamped in the server's UTC, so a revision seen after 20:00 in Chile was
  labelled tomorrow — and unlike `date` it is stored already rendered, so it could not be corrected
  on read. The three conversions from instant to Chilean day are now one helper (#77).
- The ISP pane rendered each day in the reader's timezone while filtering on the publisher's, so an
  alert could show "1 de agosto" and file under julio. Both panes share one day formatter (#77).
- The ISP archive could stop accreting for good: a flush left the cursor claiming a walk whose data
  was gone, one unreadable 200 retired a category permanently, and a failing backfill page braked
  the hourly feed (#74).
- The daily probe reported the ISP healthy while it was down: with every category braked it
  attempted nothing, and "we asked nothing" was answered as "nothing is wrong" — clearing the
  failure count, emptying the bell and greening the setup check (#75).
- A braked category is logged at `warning`, so one dead feed is visible at the default log level.
- The backfill cursor moved into its category's own cache entry, so it cannot outlive the archive it
  describes. `IspRepository` no longer takes `IAppConfig`, and the old `backfill-*` app config keys
  are orphans that can be deleted.

## [0.5.0] — 2026-08-05

### Added

- The whole ISP archive is kept, not the newest 60 — one cache entry per category, accreting (#47).
- The archive backfills itself two pages an hour, with a per-category cursor in `IAppConfig` (#49).
- A failing category backs off, doubling from 2 h to a 24 h cap, instead of being asked hourly (#60).
- A revised alert is replaced in place and marked *actualizada*, keeping its publication date (#50).
- The OCS route takes `source`, `category` and `olderThan`; no parameters answers exactly as before (#51).
- Five ISP families in the navigation plus **Todas**, and a subcategory rail inside the pane (#52).
- `LICENSE`, and SPDX headers on the eight `lib/` files that lacked them.
- A JS test runner (Vitest) and the frontend logic under it (#44).
- The repository is checked against itself: four assertions in the unit suite (#53).

### Changed

- **The sondeo's failure count has an interface.** It was two config-key prefixes: `ProbeJob` wrote
  them, `SourcesReachable` imported the background job for no reason except to borrow the two
  strings, and both test suites read and wrote the raw store. `CONTEXT.md` says those two surfaces
  "cannot disagree" — that was enforced by both files spelling the same string, which is an agreement
  nobody can see from a signature. `ProbeRecord` now owns the count, the first-failure stamp and the
  reset, and is the only thing that knows the keys exist.
- The notification schedule stays with the job. A bell is right at a transition and a status row at a
  persistent state, so the thresholds differ on purpose — but they now differ over a named method
  rather than over a shared prefix.
- The publication day is rendered in the **publisher's** timezone, not the server's. The ISP is
  Chilean and prints a Chilean date; the container runs UTC, so anything published after 20:00 in
  Santiago showed a day late — one alert of 173 on the live archive. Derived on read from the stored
  instant, so the 1.423-alert archive is corrected without a single refetch.
- The revision fingerprint no longer leaves the repository. It is how a rewritten alert is spotted; it
  renders nowhere, and an internal field riding out to every browser and into the OCS contract is one
  nobody can change later.
- The ISP feeds are asked conditionally; an unchanged feed answers `304` with no body (#48).
- `scripts/layout.mjs` gained check 7: the navigation must fit its column at every viewport (#52).

### Removed

- Nothing, from the freno and the backfill: [ADR-0010](docs/adr/0010-the-freno-and-the-backfill-stay-inside.md)
  records why an architecture review's proposal to extract them was rejected — the deletion test
  fails for both, and the review's sharpest claim (that `probe()` starves the backfill budget) was
  simply wrong, since the budget is per call.
- 1.072 lines of prose (#54): a stale 401-line plan doc, a 74-line REM glossary for data this app
  never fetches, 158 lines of agent-skill boilerplate, and a CHANGELOG that had become a design diary.

### Fixed

- **The ISP pane had three names for one destination.** Inicio said "Alertas del ISP", the navigation
  row and the heading said "Todas". `panes.js` exists to stop exactly that, and `layout.mjs` check 5
  passed throughout — "Todas" *was* a nav name, so the check held while the property it stands for
  did not. On a phone the heading is the only name shown, so it named nothing. The row and the
  heading read `paneName('isp')` again.
- **`Alertas vigentes` blamed the reader for an outage**, telling them nothing matched their search
  when MINSAL had simply returned nothing and they had not searched. It now distinguishes the two
  emptinesses, as the ISP pane always did.
- **A row could say the same words twice** — "Dispositivos médicos · Retiro — dispositivo médico ·
  *Dispositivos médicos*". The family tag now appears only when it adds something.
- **A MINSAL narrowing was answered with confident nonsense.** `page()` filtered on `categories` and
  `at`, which only ISP items carry, so `category` matched nothing and returned an empty archive while
  `olderThan` compared against a defaulted `0` and returned everything — both labelled as if filtered.
  The route now answers **400**: the fuente exists, the request does not make sense against it.
- A cache entry in an older shape is ignored rather than half-read.
- A non-RSS body from the ISP no longer wipes the archive (#42).
- An outage no longer costs a fetch on every page load (#43).
- The filters never said what was filtered (#44).
- The version was written in three places and two were wrong (#53).
- The test cache double ignored TTL and `remove()`, so a test passed for the wrong reason.

## [0.4.0] — 2026-08-03

### Added

- One toolbar row and one filter model for every list pane, with counts in the menus.
- `scripts/layout.sh`: a headless layout gate at 360, 768 and 1366 px (#40).
- CI (`.github/workflows/ci.yml`): the unit suite and the bundle check on every push.
- One name per pane, in `src/panes.js` — each answered to two or three before.
- `fuente` and `sondeo` in the glossary; ADR-0007 and ADR-0008.

### Changed

- The navigation groups by publisher, once, and its footer credits both.
- Inicio's card titles match core's Dashboard; headings are lighter below the breakpoint.

### Removed

- Six pieces of decoration and 878 characters of prose that told nobody anything.

### Fixed

- The app could render with stale CSS, or none, and say nothing.

## [0.3.0] — 2026-08-03

**Breaking:** one OCS route keyed by source. `/alerts` and `/reports` are gone, with no aliases —
see [ADR-0006](docs/adr/0006-one-ocs-route-keyed-by-source.md).

### Added

- `Service/Sources.php`, the catalogue of what sources exist and who owns each.
- `IspRepository::search()`, cache-only, so ISP alerts reach the global search box.

### Changed

- `MinsalReachable` is now `SourcesReachable`; probe counters are renamed to catalogue ids.
- The page receives one `IInitialState` key instead of one per source.
- `README.md` and this file are now in English.

### Fixed

- A notification about the ISP said MINSAL.
- Administración → Visión general stayed green during an ISP outage.

## [0.2.0] — 2026-08-02

**Breaking:** the API moved to OCS.

### Added

- `ISetupCheck` in Administración → Visión general.

### Fixed

- A `javascript:` URL from MINSAL reached `href` unvalidated.
- `intl` was an undeclared hard dependency.
- The daily probe could fill the notification bell forever.
- The tab widget broke the `tablist` ARIA contract.
- The tablero's iframe leaked the intranet URL to Microsoft on every load.
- The app shipped no icon, so the menu showed the generic placeholder.

## [0.1.0] — 2026-08-01

First release. Alertas vigentes, the weekly ETI/IRAG report, MINSAL's embedded dashboard and a link
to EPIVIGILA. A read-through caching proxy, with no database.
