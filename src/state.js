import { loadState } from '@nextcloud/initial-state'

const APP = 'epidemiologia'

/** Both feeds share this shape; see MinsalRepository::present(). */
const EMPTY = { items: [], updated_at: null, fetched_at: null, degraded: false }

/**
 * Always pass a fallback: loadState() throws when the key is absent, and an
 * absent key is exactly what a forgotten rebuild or a renamed provider produces.
 * A missing feed should degrade to an empty pane, never to a blank app.
 */
export const alerts = loadState(APP, 'alerts', EMPTY)
export const reports = loadState(APP, 'reports', EMPTY)
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
