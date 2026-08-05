# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Semantic versioning, still on `0.x`: **an incompatible change raises the minor.**

Written in English, like the rest of this repo outside the UI — see [`AGENTS.md`](AGENTS.md).

**One line per change, naming what moved for someone using the app or its API.** Why a change was
made belongs in the commit, the issue, or a comment beside the code. This file had grown into a
design diary — 517 lines, with `### Changed` appearing twice inside one entry — and was cut back
in #54.

## [Unreleased]

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

- The ISP feeds are asked conditionally; an unchanged feed answers `304` with no body (#48).
- `scripts/layout.mjs` gained check 7: the navigation must fit its column at every viewport (#52).

### Removed

- 1.072 lines of prose (#54): a stale 401-line plan doc, a 74-line REM glossary for data this app
  never fetches, 158 lines of agent-skill boilerplate, and a CHANGELOG that had become a design diary.

### Fixed

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
