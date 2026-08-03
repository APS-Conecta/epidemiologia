# `analizador_rem` owns REM; consumers read it over OCS

REM data serves more than one app, so it needs one owner rather than a copy per consumer. That owner
is **`analizador_rem`**: its `oc_rem_fact` table was already designed for shared analytical reads
(*"Denormalised from rem_source so an analytics query never needs the join"* — `serie`, `deis`,
`anio`, `periodo` sit on every fact row, with `rem_fact_slice_idx` over exactly that tuple), and it
already holds the cell map, the DEIS código dictionaries, the determinism firewall and the validator
written against DEIS's published CSVs. Epidemiología therefore **does not** read `oc_rem_fact` and
does **not** keep its own copy; it calls the OCS read API that app's roadmap already calls Phase 4.

## Considered options

- **A second REM store inside Epidemiología.** Rejected: the código-de-prestación mapping would then
  exist in two apps and drift, which is the failure this decision exists to prevent.
- **A new dedicated data app, with both apps as consumers.** Architecturally purer — a storage owner
  that is not also a UI — but it means refactoring a working app to serve one consumer that needs
  440 rows. YAGNI; revisit if a third consumer appears.
- **Provisioning-loaded `oc_ref_*` tables with no app owner.** Fast and app-agnostic, but REM cannot
  be read correctly from a bare table: `es_total` marks the only summable column, Serie P is a stock
  that must never be summed across cortes, and B17 is 94% formulas derived from B so a naive
  serie-wide SUM double-counts. Those rules belong with code.
- **Direct SQL from Epidemiología into `oc_rem_fact`.** Rejected on the same boundary AD-9 draws for
  core: an app reaching into another app's private storage has no contract and no version.

## Consequences

- `analizador_rem` gains a **second ingestion path** — DEIS's published extracts
  (`repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_<year>.zip`) alongside the establishment's
  own `.xlsm` planillas.
- **Provenance becomes first-class.** A figure from our own planilla is *gated* — checked against the
  planilla's own Control sheet arithmetic. A DEIS extract has no Control sheet and no gate. Merging
  them silently would destroy the gate's meaning, so the source kind must be recorded and readable.
- `analizador_rem` must be installed on the gestión instance, where today it is not.
- **PIV lives with the owner too.** It is the denominator for Metas II, IIIa, IIIb, IVa, V and VII —
  56% of the meta weight — so storing it in Epidemiología would recreate the same duplication.
- The read API stays narrow on purpose. Its first consumer needs Serie P totals for a handful of
  códigos, not a general query surface. The owner's own mappers carry the note *"No read methods here
  yet, deliberately… Write them when the reader arrives"*; this decision is the reader arriving.
