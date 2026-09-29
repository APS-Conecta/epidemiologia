import { loadState } from '@nextcloud/initial-state'

/**
 * The initial-state half (L3-17): the only module in src/ that reads the
 * page. Every derivation lives in pure.js and is imported from there
 * directly — the re-export shim that used to keep pre-split import sites
 * resolving went with L7-01, so a pure consumer no longer drags the
 * loadState machinery with it.
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
 * PageController that has to be spelled identically in two languages, so two
 * of them were two chances to typo a source into silence. PHP enumerates the
 * sources in Service/Sources.php; the registry (src/panes.js) names the ones
 * a sector pane renders, and panes.test.js holds its spelling to PHP's.
 *
 * Always pass a fallback: loadState() throws when the key is absent, and an
 * absent key is exactly what a forgotten rebuild or a renamed provider produces.
 * A missing source should degrade to an empty pane, never to a blank app.
 */
const sources = loadState(APP, 'sources', {})

/**
 * One fuente's answer, by the id Service/Sources.php spells: what the page
 * carries under that key, or the degraded shape when it carries none.
 *
 * @param {string} id - The backend id, e.g. 'paho_alerts'.
 * @return {object} `{ items, fetched_at, degraded, … }`.
 */
export function source(id) {
	return sources[id] === undefined ? DEGRADED_EMPTY : sources[id]
}

/** The one fuente read by name: the informe, which is no alert sector (ReportPane, Inicio's last card). */
export const reports = source('reports')

/** Not a source: a constant URL with nothing to fetch, parse, cache or degrade. */
export const tablero = loadState(APP, 'tablero', '')
