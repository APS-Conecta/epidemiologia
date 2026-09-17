# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Semantic versioning, still on `0.x`: **an incompatible change raises the minor.**

Written in English, like the rest of this repo outside the UI — see [`AGENTS.md`](AGENTS.md).

**One line per change, naming what moved for someone using the app or its API.** Why a change was
made belongs in the commit, the issue, or a comment beside the code.

## [Unreleased]

### Added

- A render error in any pane now shows *"No pudimos mostrar esta sección"* instead of a blank page.
  The panes are shown with `v-show`, so all six are mounted at once and one throwing pane took the
  whole app down on load — and an empty page looks exactly like the instance being broken. The error
  still reaches the browser console and any app-level handler, so the fallback does not cost a
  diagnosis.

### Changed

- **The alertas vigentes are read from the page MINSAL publishes the in-force set on.** The host this
  fuente used has answered a hard 403 to every network tried since October 2025, so it had no reachable
  content at all; MINSAL moved the alerts itself and the app follows the publisher. **13 alertas
  vigentes**, titled by MINSAL's own table rather than by the anchor text. **Eight of the thirteen link
  a PDF back to the old host and those links do not resolve** — for anyone, from anywhere, with no
  archive copy in existence; they are rendered exactly as MINSAL publishes them, unmarked.
  [ADR-0014](docs/adr/0014-the-fuentes-follow-their-publishers.md).
- **The weekly informe now comes from the ISP, and its pane is called *Circulación de Virus
  Respiratorios*** — the publisher's own title for it. The previous publisher's informes were not moved
  anywhere and have no reachable home. The admin bell and *Administración → Visión general* name it the
  same way, so *ETI/IRAG* now appears only on the **tablero**, which really is MINSAL's report. The
  pane's navigation row moved from the MINSAL group to the ISP's, its degraded notice links the ISP,
  and the fuente **accumulates one informe per semana
  epidemiológica**: the ISP publishes only the current one and its year archive renders client-side, so
  the back catalogue cannot be fetched at all. Any app update on the instance empties the accumulation
  and it restarts from the current week, and **the log now says so when it happens** — the ISP archive
  already did, and it is the one of the two that can walk itself back
  ([ADR-0012](docs/adr/0012-the-archive-is-a-cache-and-any-app-update-empties-it.md)).
- **Installing no longer requires importing the ISP's intermediate certificate by hand.** The app ships
  the certificate `www.ispch.gob.cl` omits from its chain and merges it with the instance's own trust
  anchors for its ISP requests only; verification is unchanged and `verify => false` is still forbidden.
  The ISP's leaf expires **2026-11-23** — if the issuer rotates, `certs/README.md` carries the one
  command that re-derives the replacement from the live certificate.
- **The app's summary and description name what it actually shows, and so does the published OCS
  contract.** All three advertised *informes IRAG*, which was the previous publisher's name for a
  document this app no longer reads; `openapi.json` is generated from the summary and had not been
  regenerated.
- The *"Ver en el sitio oficial"* link in the ISP's degraded notice now opens the ISP's alert index
  rather than the `anamed` category. It is shown exactly when the app cannot reach the ISP, and it
  sent a reader to one of the 24 categories with nothing saying the other 23 exist.
- **An ISP outage now says what failed.** The single warning an administrator sees carries the
  underlying error — `cURL error 60: SSL certificate problem`, for instance — instead of only *"No
  ISP feed could be reached"*. Per-feed causes stay at `debug`, which Nextcloud does not log by
  default, so that line was the only one there was.
- **A MINSAL feed that could not be read no longer reports itself as one that returned nothing.**
  Both degrade and both serve the last good copy, but *"returned 0 items"* is a claim about MINSAL
  that is false when MINSAL was never reached — and a body that is not a page (a WAF block, a proxy
  error) previously produced no log line at all.

### Fixed

- **A CA bundle that only half-wrote can no longer poison both ISP fuentes permanently.** The merge
  is written beside its destination and renamed onto it, so the identity path only ever holds a whole
  bundle. Written straight to that path, a truncated file stayed there under a correct name and a
  correct owner — every later request trusted it and none rebuilt it, so both fuentes failed
  `cURL error 60` until the instance's anchors happened to change.
- **The daily sondeo can see the alerts page change again.** It compared a publication date the
  publisher does not move — that date read June while the page listed alerts dated July — so it
  reported no change straight through real edits, which is the one thing a detector must not do. It
  now compares the alerts it parsed.
- **A fuente in estado degradado no longer fails `scripts/layout.sh`.** An outage upstream was reported
  as six layout failures in the alerts pane; the filter-count checks a degraded fuente cannot answer
  are now skipped by name, and the structural ones still run.
- The OCS route's published description said `total` and `has_more` were missing from the contract.
  They have been in it since 0.9.0; the sentence was a record of the fixed defect left in the present
  tense, and because the docblock *is* the spec source it was served to every consumer as the
  contract's own description of itself.

## [0.9.0] — 2026-08-18

### Added

- A shared accent-folding conformance table, read by both suites. `fold()` exists twice — PHP for
  OCS and global search, JS for the in-browser filter — and the two agreed only by coincidence, so
  a change to either could make searching and filtering return different sets for the same query
  with nothing going red. One edit to `tests/unit/fold-cases.json` now fails a test in each.

### Changed

- The four ISP families the publisher names in its own slugs — medicamentos, dispositivos,
  cosméticos, desinfectantes — carry a distinct icon in the navigation instead of sharing one.
  *Sin familia declarada por el ISP* deliberately keeps none, because a glyph there would invent the
  classification the shared icon existed to prevent.
  [ADR-0013](docs/adr/0013-family-icons-where-the-isp-names-the-family.md).
- An ISP alert whose title opens by repeating a category tag on its own row now shows that preamble
  quietly, so the eye lands on the product rather than on the fourth *ALERTA DE RETIRO DEL MERCADO*
  in a column. **Nothing is removed** — the whole title stays in the link and in the accessibility
  tree — and a title whose preamble is not repeated on its row is untouched. Measured on the real
  feeds: three consecutive retiro alerts share 63 identical characters before the product name.
- The ISP family chosen from the navigation now shows as a token like every other filter, and
  dismissing it returns to the whole archive — heading, highlighted row and tokens moving together.
  Widening that way keeps a chosen category, which switching between families still clears: a token
  saying *Medicamentos* takes Medicamentos with it and nothing else.
- Both alert panes keep their toolbar on screen while the list scrolls, and show what is narrowing
  it as dismissible tokens beside the count. The ISP archive is 276 rows and ~18.500px tall; past
  the fortieth row nothing said which year, month, search or category was applied, while the empty
  state said *"Ninguna alerta del ISP coincide con esos filtros"* about filters that had scrolled
  out of sight. Clearing one is now a click on the token rather than hunting for the control that
  set it.

### Removed

- **`updated_at` is gone from the OCS envelope.** It was two different facts under one key —
  MINSAL's WordPress `modified` on one fuente, the newest archived item's date on the other — and
  nothing read it. It could not answer the question a freshness key exists for, because a degraded
  read serves the last good copy and its `updated_at` with it; `fetched_at` and `degraded` answer
  that and both stay. Removing a published key is a breaking change to the contract, hence a minor
  release. See [ADR-0006](docs/adr/0006-one-ocs-route-keyed-by-source.md) § Correction.

### Fixed

- The "could not reach" and "nothing published" notices now name the source instead of printing a
  literal `%s`. Both called `t()` with printf syntax and an array; it substitutes `{nombre}` from an
  object, so the placeholder reached the screen. They render only when a fuente is unreachable —
  the one path a working network never exercises.
- **`openapi.json` was describing a narrower envelope than the route returns.** `total` and
  `has_more` were added so a capped response could be told from a complete one, and the docblock
  the extractor reads was never updated — so the published contract named four keys while the
  route returned six, and a consumer reading the spec could not discover that paging exists. The
  spec now names all five and marks the two paging keys optional, which is what they are: they
  appear only when a source is named.

## [0.8.0] — 2026-08-06

### Changed

- `refresh()` is private in both repositories. Nothing outside them ever called it, and its
  signature published two implementation facts: the raw cache-entry shape, and a freno ADR-0010
  says lives inside the class. `IspRepository`'s public interface goes from five members to four.
- The TTL-honouring cache double is shared instead of byte-identical in both repository suites.
  The rule it encodes is subtle — entries carry an **absolute** expiry, because `$now` is
  per-repository — and a single edit to it now fails a test in each suite, where before the two
  agreed only by coincidence.
- The filter pair both alert panes carry — the search box, the year, the month, and the rule that
  clears a month the newly chosen year does not have — moved to `src/filters.js`. It was
  byte-identical in both files, comment included, and **had no test in any language**. It has six
  now. Each pane keeps what is genuinely its own: the ISP composes family and category on top.

## [0.7.0] — 2026-08-06

### Fixed

- The ISP archive is emptied by **any** app on the instance being installed, enabled, disabled or
  updated — Nextcloud's distributed-cache namespace is keyed by every enabled app's version. It
  rebuilds in about three days of walking; a refresh that finds nothing now says so, instead of the
  pane silently understating the archive. [ADR-0012](docs/adr/0012-the-archive-is-a-cache-and-any-app-update-empties-it.md) (#101).
- **27 of 75 MINSAL alerts reached a clinician titled "Descargar Documento"** — 36 % of the pane,
  since 0.1.0. The page is a table whose third cell is a link label; the name is in the first cell.
  An anchor text used by more than one row is now read as a label, not a title (#100).
- The same PDF, linked from two rows of MINSAL's own table, was listed twice and duplicated a Vue
  key (#104).
- Seven ISP titles carried whitespace `trim()` cannot see, rendering as a visible indent (#104).
- The outage log line always claimed "24 intentos" whatever the freno had left to attempt (#104).
- Administración → Visión general asserted the result of a daily probe that had never run — for
  about 29 hours after every install. It now says so instead (#102).
- Three narrowings answered 200 with an empty list: a blank `category`, a category the ISP does not
  publish, and an `olderThan` that is not an instant. All three answer 400 (#103).
- A narrowed response is capped at 100 and said nothing about it, so naming a source returned fewer
  items than not naming one. Every source now carries `total` and `has_more` (#103).

## [0.6.0] — 2026-08-05

### Fixed after review

- `get()` released a lock it had never acquired: the `add()` was conditional and the `remove()` was
  not, so a cold reader deleted the lock of whoever was mid-walk. The test passed while executing it.
- A backfill page that can never be read stalled that category's walk forever and silently. Three
  tries, then the walk steps over it and says so.
- The hourly write rebuilt each entry from an explicit key list, dropping the fields the backfill
  owns. It spreads the stored entry now, so the class of bug is gone rather than the instance.
- An oversize response was cached as a healthy empty archive for thirty days. The cap moved from
  `parse()` into `fetch()` and `page()`: too big to read is *unreachable*, not *empty*.
- `search()` paid for the whole of `present()` before its own empty guard, on every unified search.
- `layout.mjs` check 8 hard-required a `Cosméticos` row, which exists only when the archive carries
  a cosmetics alert — a red suite for a data reason. It walks whichever family is there, or skips.
- `layout.mjs`'s usage message still told you to pass the token on the command line.
- `probe()`'s docblock claimed it does not touch the freno. It does, in both directions, and the
  cost is 26 requests rather than 24.

### Fixed

- The ISP archive could stop accreting for good: a Redis flush left the cursor claiming a walk whose
  data was gone, one unreadable 200 retired a category permanently, and a failing backfill page
  braked the hourly feed (#74).
- The daily probe reported the ISP healthy while it was down — with every category braked it
  attempted nothing, and that was answered as "nothing is wrong" (#75).
- One ISP walk at a time: every stale page load used to start its own 24-feed fan-out and could
  exhaust the PHP-FPM pool. A cold cache still fetches (#76).
- A response larger than 512 KB is not read; a 3 MB feed exhausted the image's 512 MB in the
  parser (#76).
- The revision marker was stamped in the server's UTC, so a revision seen after 20:00 in Chile was
  labelled tomorrow. The three conversions to a Chilean day are one helper (#77).
- The ISP pane rendered days in the reader's timezone while filtering on the publisher's (#77).
- Global search bypassed `present()`, shipping the revision fingerprint and a different day than
  the pane (#78).
- A stored URL that is not `https://` no longer leaves the repository (#78).
- A narrowing MINSAL cannot express is refused while the fuente is empty too, instead of answering
  200 with an empty list (#78).
- `layout.mjs` check 6 pointed at a pane with no toolbar, so it printed ok about filters it never
  opened; it now fails when it finds no menus at all (#79).
- The rail's active chip carries `aria-pressed`, and a row's category buttons toggle like the rail
  instead of assigning (#79).
- Inicio's "Ver las N alertas del ISP" landed on whatever family was last selected (#80).
- The ISP navigation lists the families that carry alerts, not all five. The pane's own row,
  **Alertas del ISP**, stays unconditional so a degraded ISP keeps a destination (#80).
- `layout.mjs` check 8, the only one that navigates between panes, walks family → Inicio → the
  card's link and asserts where it lands (#80).
- Seven accessibility and wording defects: a green dot asserting unmeasured health, a heading level
  skipped on desktop, the semana epidemiológica printed with no word naming it, an `aria-label`
  erasing the visible label, two external links without `rel`, a result count with no live region,
  and four wordings for two facts (#81).
- The 4,9 MB source map and `vitest.config.js` were served with no authentication; `smoke.sh` now
  asserts it anonymously (#83).
- Neither dev script passes an app password as a command-line argument, and `smoke.sh`'s `occ()`
  keeps stderr (#83).

### Changed

- The backfill cursor lives in its category's own cache entry, so it cannot outlive the archive it
  describes. `IspRepository` no longer takes `IAppConfig`; `backfill-*` config keys are orphans.
- A braked category is logged at `warning`, so one dead feed is visible at the default level.
- `scripts/layout.mjs` reports in English, and the comments in `appinfo/info.xml` and `.htaccess`
  are English. Nothing a clinician reads changed (#82).

## [0.5.0] — 2026-08-05

### Added

- The whole ISP archive is kept, not the newest 60 — one cache entry per category, accreting (#47).
- The archive backfills itself two pages an hour, with a per-category cursor (#49).
- A failing category backs off, doubling from 2 h to a 24 h cap, instead of being asked hourly (#60).
- A revised alert is replaced in place and marked *actualizada*, keeping its publication date (#50).
- The OCS route takes `source`, `category` and `olderThan`; no parameters answers as before (#51).
- Five ISP families in the navigation under the pane's own row, and a subcategory rail (#52).
- The ISP feeds are asked conditionally; an unchanged feed answers `304` with no body (#48).
- A JS test runner (Vitest) and the frontend logic under it (#44).
- `LICENSE`, SPDX headers on the `lib/` files that lacked them, and four self-assertions (#53).
- `layout.mjs` check 7: the navigation must fit its column at every viewport (#52).

### Changed

- The sondeo's failure count has an interface, `ProbeRecord`, instead of two shared config prefixes.
- The publication day is rendered in the publisher's timezone, derived on read from the stored
  instant — one alert of 173 on the live archive was a day late.
- The revision fingerprint no longer leaves the repository.

### Fixed

- The ISP pane had three names for one destination, and `layout.mjs` check 5 held throughout because
  "Todas" *was* a nav name.
- `Alertas vigentes` told a reader their search matched nothing when MINSAL had returned nothing.
- A row could print a family tag repeating a category it already showed.
- A MINSAL narrowing was answered with an empty list labelled as if filtered; it answers **400**.
- A cache entry in an older shape is ignored rather than half-read.
- A non-RSS body from the ISP no longer wipes the archive (#42).
- An outage no longer costs a fetch on every page load (#43).
- The filters never said what was filtered (#44).
- The version was written in three places and two were wrong (#53).
- The test cache double ignored TTL and `remove()`, so a test passed for the wrong reason.

### Removed

- 1.072 lines of prose (#54). Nothing from the freno or the backfill: ADR-0010 records why an
  architecture review's proposal to extract them was rejected.

## [0.4.0] — 2026-08-03

### Added

- One toolbar row and one filter model for every list pane, with counts in the menus.
- `scripts/layout.sh`: a headless layout gate at 360, 768 and 1366 px (#40).
- CI (`.github/workflows/ci.yml`): the unit suite and the bundle check, on `main` and on pull requests.
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
