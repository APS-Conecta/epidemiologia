# No `l10n/es.json`, and no seam for an app nothing calls

**Status: accepted, 2026-08-05.** #65 rescued two items from a plan document deleted in #54, both
written down as things not yet done. They are things that should not be done yet, and a future review
will notice them again.

## The l10n file would be an identity map

`l10n/es.json` was decided in #9 and never created; the directory does not exist. The app makes
**62 `t('epidemiologia', …)` calls and 5 `n(…)` calls over 53 distinct source strings, every one of
them already Spanish** — `t('epidemiologia', 'Cosméticos')`. This repo's language rule puts Spanish in
the string a clinician reads, not in a catalogue beside it, so `l10n/es.json` would be 53 entries of
`{"Cosméticos": "Cosméticos"}` — exactly what Nextcloud already renders by falling back to the source
when it finds no catalogue, as `scripts/layout.sh` has been asserting on six panes all along. What
the file adds is a second place to edit a string and a way for the two to disagree; correcting the
Spanish *is* correcting the source.

## The seam has no adapter

Nothing injects `IAppManager`, so "the sibling app is not installed" has no seam: ADR-0001 gives REM
to `analizador_rem`, and the first read of its data would fail as an exception rather than as a
state. But `IAppManager` appears zero times in `lib/`, and nothing in this app reads anything from
`analizador_rem` at any code path today. A seam with no adapter is designed against an imagined
caller, and the first real one nearly always wants a different shape. The failure it would prevent —
an uncaught exception where a "no está instalada" state belongs — cannot happen until something calls
across, and whatever makes that call will know what it needs when the app is absent.

## Reopen when

- **a locale other than Spanish is wanted** — then `es.json` is still the identity map but `en.json`
  is not, and the catalogue starts earning the drift risk it carries;
- **anything in `lib/` reads `analizador_rem`** — build the seam in that change, where the
  requirement is visible instead of guessed.
