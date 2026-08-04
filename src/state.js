import { loadState } from '@nextcloud/initial-state'
import { getCanonicalLocale, t } from '@nextcloud/l10n'

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

/**
 * A month, written the way a person reads one.
 *
 * MINSAL publishes no day: the month comes from the upload path, and PLAN.md is
 * explicit that it is the UPLOAD month, not the oficio's. So this formats a month
 * and never invents a day — the 1 below only exists because Intl needs a Date to
 * format, and the day is never rendered.
 *
 * `2026-07` reaching a clinician was the defect this replaces: that is a sort key.
 */
export function mes(monthKey) {
	if (!monthKey || !/^\d{4}-\d{2}$/.test(monthKey)) {
		return ''
	}
	const [year, month] = monthKey.split('-').map(Number)
	return new Intl.DateTimeFormat(getCanonicalLocale(), { month: 'long', year: 'numeric' })
		.format(new Date(year, month - 1, 1))
}

/**
 * The one sentence that tells a clinician the list may be incomplete.
 *
 * It lives here because it existed in FOUR hand-written variants — the shared
 * component, the ISP pane and both Inicio cards — differing only in punctuation
 * and institution. DegradedNotice's own docblock had already warned that two
 * copies would eventually disagree.
 *
 * "contactar con", not "contactar a": the article differs between "MINSAL" and
 * "el ISP", and one sentence that works for both beats two that drift.
 */
export function degradedText(fuente) {
	return t('epidemiologia', 'No hemos podido contactar con %s, así que esta lista puede estar incompleta.', [fuente])
}

/**
 * How many items carry each value of `key`, as [{ id, count }].
 *
 * Filter options are built from the data rather than declared, so a filter can
 * never offer a value the list does not contain — and a value the source stops
 * publishing disappears on its own. Shared because both alert panes build their
 * año and mes menus this way, and writing it twice is how the two panes' filters
 * drifted apart in the first place.
 */
export function tally(items, key) {
	const counts = new Map()
	for (const item of items) {
		const k = key(item)
		if (k) {
			counts.set(k, (counts.get(k) ?? 0) + 1)
		}
	}

	return [...counts.entries()].map(([id, count]) => ({ id, count }))
}
