# No `l10n/es.json`, and no seam for an app nothing calls

**Status: accepted, 2026-08-05.** Recorded because #65 carried both as debt, a future review will
notice both again, and neither is debt.

## Context

#65 rescued two items from a plan document deleted in #54:

1. `l10n/es.json` was decided in #9 and never created. The directory does not exist.
2. Nothing injects `IAppManager`, so "the sibling app is not installed" has no seam — ADR-0001 gives
   the REM to `analizador_rem`, and the first read of its data would fail as an exception rather
   than as a state.

Both were written down as things not yet done. They are things that should not be done yet.

## The l10n file would be an identity map

The app makes **62 `t('epidemiologia', …)` calls and 5 `n(…)` calls over 53 distinct source strings,
every one of them already Spanish** — `t('epidemiologia', 'Cosméticos')`. This repo's language rule
puts Spanish in the string a clinician reads, not in a catalogue beside it. So `l10n/es.json` would
be 53 entries of `{"Cosméticos": "Cosméticos"}`, which is exactly what Nextcloud already renders by
falling back to the source when it finds no catalogue — as `scripts/layout.sh` has been asserting on
six panes all along. What the file adds is a second place to edit a string and a way for the two to
disagree; correcting the Spanish *is* correcting the source.

**Write it when a second locale exists.** At that point `es.json` is still the identity map, but
`en.json` is not, and the catalogue starts earning the drift risk it carries.

## The seam has no adapter

`IAppManager` appears zero times in `lib/`, and nothing in this app reads anything from
`analizador_rem`, at any code path, today. A seam with no adapter is designed against an imagined
caller, and the first real one nearly always wants a different shape. The failure it would prevent —
an uncaught exception where a "no está instalada" state belongs — cannot happen until something calls
across, and whatever makes that call will know what it needs when the app is absent.

**Build it in the change that first reads `analizador_rem`**, where the requirement is visible
instead of guessed.

## Reopen when

- **a locale other than Spanish is wanted** — then the catalogue mechanism starts being used rather
  than merely present;
- **anything in `lib/` reads `analizador_rem`** — the second half becomes a real requirement in the
  same commit that creates it.
