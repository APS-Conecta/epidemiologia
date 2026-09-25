import { loadState } from '@nextcloud/initial-state'

/**
 * The initial-state half (L3-17): the only module in src/ that reads the
 * page. Every derivation lives in pure.js and is re-exported here so the
 * import sites that predate the split keep resolving.
 */

const APP = 'epidemiologia'

/**
 * The same shape, degraded: what a MISSING key reads as (L3-14). The
 * distinction is the whole point — `?? EMPTY` rendered "MINSAL no trae
 * alertas en este momento" for a publisher the page never read, a positive
 * claim about a fuente that was not asked. Undefined degrades; an empty
 * array is a real answer.
 */
const DEGRADED_EMPTY = { items: [], fetched_at: null, degraded: true }

/**
 * One key, not one per source. Each loadState key is a contract with
 * PageController that has to be spelled identically in two languages, so two of
 * them were two chances to typo a source into silence. PHP enumerates the
 * sources in Service/Sources.php; this file only names the ones it renders.
 *
 * Always pass a fallback: loadState() throws when the key is absent, and an
 * absent key is exactly what a forgotten rebuild or a renamed provider produces.
 * A missing source should degrade to an empty pane, never to a blank app.
 */
const sources = loadState(APP, 'sources', {})

export const alerts = sources.alerts === undefined ? DEGRADED_EMPTY : sources.alerts
export const reports = sources.reports === undefined ? DEGRADED_EMPTY : sources.reports

/** Not a source: a constant URL with nothing to fetch, parse, cache or degrade. */
export const tablero = loadState(APP, 'tablero', '')

export {
	// The derivations, I/O-free since L3-17 — see src/pure.js.
	fold,
	fecha,
	mes,
	degradedText,
	emptyText,
	tally,
	yearOptions,
	monthOptions,
	FUENTES,
} from './pure.js'
