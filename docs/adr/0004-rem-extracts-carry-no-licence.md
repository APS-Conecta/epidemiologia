# The DEIS REM extracts carry no licence, and we record that rather than assume one

We build on `https://repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_<year>.zip`. **DEIS states
no licence and no reuse terms for it.** This is recorded because the absence is invisible — a reader
sees public government data and assumes either public domain or CC0, and neither is stated. The
catalogue entry for it therefore says `license: unknown`, not `open` and not `cc0`; asserting either
would invent a fact the source does not state.

## What was checked, 2026-08-02

| Surface | Result |
|---|---|
| `repositoriodeis.minsal.cl/`, `/DatosAbiertos/`, `/DatosAbiertos/REM/` | **403**, directory listing disabled |
| Seven filenames — `LICENSE`, `LICENCE`, `license.txt`, `README.txt`, `readme.html`, `TERMS.txt`, `terminos.txt` | **404** on all |
| `deis.minsal.cl` terms page | **Does not exist.** The only legal link is a privacy policy, and it is exclusively about *personal* data under **Ley 19.628** |
| DEIS FAQ | *"Datos Abiertos: Conjuntos de datos disponibles para el público en general, que pueden ser descargados y utilizados para análisis independientes."* — permission to use, no condition, no grant to redistribute |
| `datos.gob.cl` | The SERIE_REM extracts **are not published there at all**, so no CKAN `license_id` attaches to them |

**Ley 20.285 is not a licence.** It compels disclosure and creates a right of access; it grants no
reuse rights and waives no copyright. Chile has no equivalent of the US federal-works rule, so state
works are not automatically public domain. What we have is **legal silence**, which is not a
dedication.

**The one real restriction sits elsewhere.** Both REM manuals carry, on page 1: *"Todos los derechos
reservados. Este material puede ser reproducido total o parcialmente para fines de diseminación y
capacitación. **Prohibida su venta.**"* That governs the **manual PDF's text**, not the ZIPs. Field
names and section labels are facts; long verbatim runs of its definitions are the "material".

## Consequences

- **Storing filtered rows and displaying them on a private intranet is unproblematic.** Nothing
  forbids it, the data are aggregate counts with no personal data, and showing an establishment its
  own submitted figures is the mildest case there is.
- **A paid product is the real exposure, and it is not an engineering decision.** No NC clause blocks
  it, but silence is not a grant. This goes to `gestion/docs/LICENSING.md` **§5 Open items** with the
  `[needs legal sign-off]` marker that file already uses five times — not resolved here. Note that
  `LICENSING.md` §1.2 currently justifies the proprietary licence with *"no redistribution intent"*;
  that sentence and this question fail together the day the project goes commercial.
- **`LICENSING.md` has no concept of a data source.** Its §3 inventory is scoped to runtime
  components — images, apps, fonts. REM needs a new section, not a new row.
- **Precedent cuts in our favour but does not decide it.** `ckan-rem20` is REM-derived MINSAL data
  published `cc-zero` and already `scope: in`; the two `cc-nc` exclusions in the catalogue are a
  different data steward's per-dataset choice, not a MINSAL-wide stance.
- **Attribute DEIS/MINSAL as the source on every view.** Customary, free, and the cheapest thing that
  makes the position defensible.
- DEIS can add terms at any time; there is no licence to rely on being irrevocable. Re-check on any
  material change of use.
