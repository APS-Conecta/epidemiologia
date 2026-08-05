# The DEIS REM extracts carry no licence, and we record that rather than assume one

**Status: accepted, and moved. This app never fetches REM** — [ADR-0001](0001-analizador-rem-owns-rem.md)
gives it to `analizador_rem`, which is where the decision now lives and is applied.

The decision, in one line: `https://repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_<year>.zip`
states **no licence and no reuse terms**, so its catalogue entry says `license: unknown` — not `open`,
not `cc0`. Asserting either would invent a fact the source does not state, and the absence is
invisible: a reader sees public government data and assumes one of the two.

Kept as a stub rather than deleted, because the number is cited elsewhere and a missing ADR reads as
a decision nobody made. Shortened in #54, along with the app's navigation footer, which had cited
this ADR as the reason to credit two publishers who have nothing to do with REM.
