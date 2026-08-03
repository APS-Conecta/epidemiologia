import { loadState } from '@nextcloud/initial-state'

const APP = 'epidemiologia'

/** Every source shares this shape; see MinsalRepository::present(). */
const EMPTY = { items: [], updated_at: null, fetched_at: null, degraded: false }

/**
 * One key, not one per source. Each loadState key is a contract with
 * PageController that has to be spelled identically in two languages, so three of
 * them were three chances to typo a source into silence. PHP enumerates the
 * sources in Service/Sources.php; this file only names the ones it renders.
 *
 * Always pass a fallback: loadState() throws when the key is absent, and an
 * absent key is exactly what a forgotten rebuild or a renamed provider produces.
 * A missing source should degrade to an empty pane, never to a blank app.
 */
const sources = loadState(APP, 'sources', {})

export const alerts = sources.alerts ?? EMPTY
export const reports = sources.reports ?? EMPTY
export const sanitaryAlerts = sources.sanitary_alerts ?? EMPTY

/** Not a source: a constant URL with nothing to fetch, parse, cache or degrade. */
export const tablero = loadState(APP, 'tablero', '')

/**
 * Accent-folded lowercase, so "sarampion" typed in a hurry still finds
 * "sarampión". MinsalRepository::fold() does the same on the PHP side for global
 * search — two languages, so the duplication cannot be removed, only kept honest.
 *
 * The combining-mark range is written escaped on purpose. It used to be typed as
 * literal U+0300–U+036F characters, which render invisibly and are one editor
 * round-trip away from silently changing meaning.
 */
export function fold(text) {
	return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}
