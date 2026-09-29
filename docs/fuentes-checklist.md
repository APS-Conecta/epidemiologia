# Adding a fuente

The catalogue is one list (`lib/Service/Sources.php`) and everything else asks
it — but "asks it" still means touching every consumer that renders, probes,
serves or names the new source. This is the checklist, rewritten when six alert
sectors went onto one pipeline (ADR-0017); the review's L3-10 asked for it in
writing rather than as tribal knowledge. At seven fuentes the per-method
`match` arms were kept on purpose (ADR-0017 D4): an arm missed is an
`UnhandledMatchError` the suite walks into, never a wrong answer.

Most new fuentes are **alert fuentes** — a page or a feed of alerts — and join
the one pipeline as configuration. A fuente whose rules the pipeline does not
have, like the informe's kept weekly documents, gets a repository of its own,
and then only `Sources` below applies to it on the backend.

## Backend

- [ ] `Sources`: the id const — never `sanitary_alerts`, which
      `RetireSanitaryAlerts` wipes on every upgrade — and its `ALL` row
      (presentation order).
- [ ] An alert fuente: its `Fuente` in `AlertRepositories::fuente()` — the
      id; the address it reads; its `host` (a host already there shares that
      host's freno and cooldown — `ISP_HOST` for www.ispch.gob.cl); the
      publisher and the two names the log uses; its parser (`PageParser`
      for a page of PDF links, `FeedParser` for a WordPress feed,
      `PahoParser` for the OPS/OMS's list, or a new `Parser` with a real
      capture as its fixture); `backfill`, the two older pages, for a
      stream, and nothing for an in-force page; `bundle` only for a host
      that serves its leaf certificate alone.
- [ ] `Sources::get()`'s match arm — default-less on purpose; an id in `ALL`
      without an arm throws `UnhandledMatchError`, and `SourcesTest` walks
      `ALL` so the failure lands in the suite, not in front of a clinician.
- [ ] `Sources::probe()`, `revalidate()`, `name()`, `kind()`, `host()` arms —
      same rule. `name()` is mid-sentence Spanish with the institution in the
      phrase; `host()` asks `AlertRepositories::host()` for an alert fuente,
      so a host is spelled once, in its `Fuente`.
- [ ] `SEARCHABLE`, only if the fuente carries alerts somebody would search
      by subject — and then `search()`'s match arm too.
- [ ] `ACCUMULATING` and `converge()`'s arm, only for a fuente that keeps
      what it reads and has older pages — every stream. An in-force set
      replaces itself and has nothing to converge.
- [ ] `ProbeJob`, `WarmJob`: nothing — both loop `Sources::ALL`.
      `RefreshJob`: nothing beyond `ACCUMULATING`, which it loops.
- [ ] The OCS contract: a new item key enters `ApiController::sources()`'s
      `@return` and `ApiContractTest::NOMBRES`; regenerate `openapi.json`
      with `scripts/openapi.sh`, never by hand.
- [ ] `PageController`: nothing — one `sources` initial-state key already
      carries every fuente.

## Frontend

- [ ] `src/panes.js`: an alert fuente is a sector — a registry entry with
      its `source` (the backend id), its group and an icon name, plus its
      `PANE_NAMES` entry. The pane (one more `AlertsPane`), the navigation
      row, the tile on Inicio, `scripts/layout.mjs`' sector checks and
      `panes.test.js`' pin against `Sources.php` all follow from that entry.
- [ ] `src/App.vue`: the icon name's component in `ICONS`.
- [ ] `src/pure.js`: its `SITIOS` entry — the publisher's own page for that
      list, where the degraded notice sends a reader.
- [ ] A new publisher: its `FUENTES` entry — `name` (mid-sentence, with its
      article), `caption` (standing alone: the navigation's caption and
      the tiles' label) and `count` (its noun for what its lists hold) —
      a captioned block in `App.vue` written out like the three there
      (ADR-0008: groups do not collapse), and its name in the footer's
      credit.
- [ ] `src/state.js`: nothing — `source(id)` reads any id, and a missing
      key reads as degraded, never as zero.
- [ ] `tests/unit/attribution.test.js`'s `SURFACES`, if a new file renders
      publisher names.

## Gates that must stay green

- [ ] `scripts/test.sh` — `SourcesTest` walks `ALL` through every method,
      and `AlertRepositoryTest` walks it through `AlertRepositories`.
- [ ] `npm test`, then `npm run build` with the rebuilt `js/` and `css/`
      committed.
- [ ] `scripts/smoke.sh` — its `for source in …` line names every id by
      hand; add the new one.
- [ ] `scripts/layout.sh` — the sector checks derive from the registry, and
      check 7 re-measures the navigation against ADR-0008.
