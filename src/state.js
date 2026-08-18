import { loadState } from '@nextcloud/initial-state'
import { getCanonicalLocale, t } from '@nextcloud/l10n'

const APP = 'epidemiologia'

/** Every source shares this shape; see MinsalRepository::present(). */
const EMPTY = { items: [], fetched_at: null, degraded: false }

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
 * A day, written the way a person reads one.
 *
 * Takes the ISO day the repository already derived in the PUBLISHER's zone, and never
 * a timestamp: rendering `at` in the browser put the ISP pane in the reader's zone
 * while its own Año and Mes menus filtered on this ISO day. An alert published
 * 2026-07-31 21:30 in Santiago then showed "1 de agosto" and filed under *julio*, so
 * filtering to agosto hid the row that said agosto.
 *
 * `T00:00:00` makes it local midnight, so no offset can move the day the string names.
 */
export function fecha(iso) {
	return /^\d{4}-\d{2}-\d{2}$/.test(iso ?? '')
		? new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'long' }).format(new Date(iso + 'T00:00:00'))
		: ''
}

/**
 * A month, written the way a person reads one.
 *
 * MINSAL publishes no day: the month comes from the upload path, and it is the
 * UPLOAD month, not the oficio's (issue #1). So this formats a month
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
	return t('epidemiologia', 'No hemos podido contactar con {fuente}, así que esta lista puede estar incompleta.', { fuente })
}

/**
 * The one sentence for "the fuente answered, and had nothing".
 *
 * Beside degradedText for the same reason it exists: this state had FOUR wordings for
 * two facts — "MINSAL no tiene alertas vigentes publicadas" on Inicio against "MINSAL no
 * trae alertas vigentes en este momento" in the pane, and "esta semana" against "en este
 * momento" for the ISP. Nothing about the state differs between the two surfaces.
 *
 * "Las categorías del ISP", never "Sus categorías": the reader has no categories, so the
 * possessive named something that does not exist.
 */
export function emptyText(fuente) {
	return t('epidemiologia', '{fuente} no trae alertas en este momento.', { fuente })
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

/** Ids are `2026` and `2026-07`, so string order is date order. */
const newestFirst = (a, b) => b.id.localeCompare(a.id)

/**
 * The año menu's options, newest first.
 *
 * `label` is not decoration: FilterMenu renders `${label} (${count})`, so an option
 * without one reaches a clinician as **"undefined (12)"**. That shipped, because the
 * ISP pane mapped a label on and Alertas vigentes did not — two panes building the
 * same menu two ways. They build it here now, so there is one way to build it.
 *
 * @param {Array} items - The pane's items.
 * @param {Function} yearOf - Reads an item's year; the two panes store it differently.
 * @return {Array} `[{ id, count, label }]`, newest first.
 */
export function yearOptions(items, yearOf) {
	return tally(items, yearOf).sort(newestFirst).map((y) => ({ ...y, label: y.id }))
}

/**
 * The mes menu's options for the chosen año, newest first.
 *
 * Scoped to the year because offering a month that year does not have lets a reader
 * build a pair of filters that can only ever return nothing — which reads as a fault
 * in the data rather than in the filter.
 *
 * @param {Array} items - The pane's items.
 * @param {Function} yearOf - Reads an item's year.
 * @param {Function} monthOf - Reads an item's month key, `YYYY-MM`.
 * @param {?string} year - The chosen year, or null for all of them.
 * @return {Array} `[{ id, count, label }]`, newest first, labelled as a person reads a month.
 */
export function monthOptions(items, yearOf, monthOf, year) {
	const scoped = year ? items.filter((item) => yearOf(item) === year) : items

	return tally(scoped, monthOf).sort(newestFirst).map((m) => ({ ...m, label: mes(m.id) }))
}
