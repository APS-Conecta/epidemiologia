# Adding a fuente

The catalogue is one list (`lib/Service/Sources.php`) and everything else asks
it — but "asks it" still means touching every consumer that renders, probes,
serves or names the new source. This is the checklist, written after the
retirement (ADR-0016) reduced it to two; the review's L3-10 asked for it in
writing rather than as tribal knowledge. A metadata-map refactor stays out at
n=2 — when a third fuente lands and this list feels long, that is the moment
to reconsider it.

## Backend

- [ ] `Sources`: the id const, and the `ALL` row (presentation order).
- [ ] `Sources::get()`'s match arm — default-less on purpose; an id in `ALL`
      without an arm throws `UnhandledMatchError`, and `SourcesTest` walks
      `ALL` so the failure lands in the suite, not in front of a clinician.
- [ ] `Sources::probe()`, `name()`, `kind()` arms — same rule. `name()` is
      mid-sentence Spanish with the institution in the phrase.
- [ ] `SEARCHABLE`, only if the fuente carries alerts somebody would search
      by subject — and then `search()`'s match arm too.
- [ ] `Sources::converge()`, only if the fuente ACCUMULATES a back catalogue
      (a replacing fuente has nothing to converge — see the method).
- [ ] The repository class itself, its cache keys, its freno/cooldown rules —
      and its docblock's disagreement with its siblings' (zero-parse-is-a-
      failure vs. accumulates): those disagreements are why there are two
      classes, not one.
- [ ] `ProbeJob`/`RefreshJob`: nothing — both loop `Sources::ALL`.
- [ ] The OCS contract: `ApiController`'s docblock payload example and the
      per-source item shape; regenerate `openapi.json`.
- [ ] `PageController` if the page renders it: one `sources` initial-state
      key already carries every fuente — nothing to add unless the shape
      itself changes.

## Frontend

- [ ] `src/state.js`: the feed export (`sources.<id> === undefined ?
      DEGRADED_EMPTY : …` — a missing key degrades, never reads as zero).
- [ ] `src/pure.js`: `FUENTES` gains the mid-sentence name with its article.
- [ ] A pane — or an entry in Inicio's `sources` computed, which is the whole
      point of the auto-fit row. Names come from `src/panes.js`'s registry.
- [ ] `tests/unit/attribution.test.js`'s `SURFACES`, if a new file renders
      publisher names.

## Gates that must stay green

- [ ] `scripts/test.sh` — `SourcesTest` walks `ALL` through every method.
- [ ] `scripts/smoke.sh` — its fuente-count assertions enumerate from the
      catalogue.
- [ ] `scripts/layout.sh` — pane count derives from the registry's ids.
