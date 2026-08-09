# `analizador_rem` owns REM; consumers read it over OCS

**Status: accepted, 2026-08-02.**
REM serves more than one app, so it needs one owner, not a copy per consumer: **`analizador_rem`**.
Its `oc_rem_fact` denormalises `serie`, `deis`, `anio`, `periodo` onto every fact row, indexed as
`rem_fact_slice_idx`, and it holds the cell map, the DEIS código dictionaries, the determinism
firewall and the validator. Epidemiología keeps no copy and no SQL against it — the boundary AD-9
draws for core — and calls the OCS read API that app's roadmap calls Phase 4.

## Considered options

- **A dedicated data app.** Refactors a working app for one consumer needing 440 rows; revisit at a third.
- **`oc_ref_*` tables from provisioning, no owner.** REM cannot be read from a bare table: `es_total`
  marks the only summable column, Serie P is a stock never summed across cortes, and B17 is 94%
  formulas derived from B, so a naive serie-wide SUM double-counts.

## Consequences

- A **second ingestion path** for `analizador_rem`: DEIS's `SERIE_REM_<year>.zip` extracts from
  `repositoriodeis.minsal.cl/DatosAbiertos/REM/`, beside our own `.xlsm` planillas. It must also be
  installed on the gestión instance, where today it is not.
- **Provenance becomes first-class.** Our planillas are *gated* against their Control sheet arithmetic
  and a DEIS extract is not, so the source kind must be recorded and readable, or the gate loses meaning.
- **PIV lives with the owner**: denominator for Metas II, IIIa, IIIb, IVa, V, VII — 56% of the weight.
- The read API stays narrow — Serie P totals for a few códigos. `RemFactMapper` has no read methods
  yet, deliberately; this decision is the reader arriving.
