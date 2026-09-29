/**
 * The derivations, with no I/O in them (L3-17).
 *
 * state.js used to mix these with the initial-state reads, so every pure
 * consumer dragged the loadState machinery with it, and the module could
 * not run under plain node at all. Everything here is a function of its
 * arguments, and every consumer imports from here directly — the re-export
 * shim that briefly kept pre-split import sites resolving went with L7-01.
 *
 * fold comes from the vendored aps-common module (L0-03): one fold for the
 * org, PHP and JS, corpus-checked.
 */
import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import { fold } from './aps/filters.js'

export { fold }

/**
 * The publishers this app reads, named mid-sentence with their article (L3-20):
 * "MINSAL" takes none, "el ISP" and "la OPS/OMS" take theirs. The convention was
 * documented in state.js prose and pinned only by a test that reads component
 * source as text; the constants are the machine-checked half now. DegradedNotice's
 * docblock is the sentence-level rationale.
 *
 * Keyed by the registry's group (src/panes.js), which groups by publisher, so a
 * sector pane finds its publisher as FUENTES[group]. `count` is the publisher's
 * noun for what its lists hold, as the toolbar counts them: MINSAL's two lists
 * are in-force sets, so "vigentes" carries a fact; the ISP's are alertas; the
 * OPS/OMS list mixes alertas, actualizaciones and other publications, so it
 * counts publicaciones. A literal pair each, because n() needs both forms whole.
 */
export const FUENTES = {
	minsal: {
		name: 'MINSAL',
		count: (k) => n('epidemiologia', '%n alerta vigente', '%n alertas vigentes', k),
	},
	isp: {
		name: t('epidemiologia', 'el ISP'),
		count: (k) => n('epidemiologia', '%n alerta', '%n alertas', k),
	},
	ops: {
		name: t('epidemiologia', 'la OPS/OMS'),
		count: (k) => n('epidemiologia', '%n publicación', '%n publicaciones', k),
	},
}

/**
 * Where a reader is SENT when a pane cannot serve its list (L6-01), keyed by the
 * backend id (Service/Sources.php): one address per LIST, not per publisher.
 *
 * FUENTES carried one `url` per publisher while each publisher had one list. With
 * four ISP lists, that one address — the informe's virus-respiratorios page — would
 * have sent the reader of the ANAMED pane to a list that is not ANAMED's. Each entry
 * is the publisher's own page for the list the pane mirrors: for the ISP's
 * categories, the WordPress archive whose `/feed/` the backend reads.
 *
 * Deliberately NOT read off the backend's Fuente config: where to READ is the
 * backend's fact, where to SEND the reader is the frontend's. For the three page
 * fuentes the two are one address; for a feed they are not; and either can move
 * without the other. Each address is said once here, and no pane carries one inline.
 *
 * epi.minsal.cl is the one this app once withdrew: ADR-0014 measured it closed to
 * every network, the owner's residential connection included, on 2026-09-15. It
 * answered that connection again on 2026-09-27 (the capture PageParserTest reads)
 * while still refusing this server, and it is the reader's connection that follows
 * this link.
 */
export const SITIOS = {
	alerts: 'https://degreyd.minsal.cl/alertas-sanitarias-y-epidemiologicas-vigentes-en-chile/',
	epi_alerts: 'https://epi.minsal.cl/alertas-epidemiologicas-vigentes/',
	reports: 'https://www.ispch.gob.cl/virusrespiratorios/',
	isp_anamed: 'https://www.ispch.gob.cl/categorias-alertas/anamed/',
	isp_pharmacovigilance: 'https://www.ispch.gob.cl/categorias-alertas/farmacovigilancia/',
	isp_cosmetics: 'https://www.ispch.gob.cl/categorias-alertas/vigilancia-de-cosmeticos/',
	paho_alerts: 'https://www.paho.org/es/alertas-actualizaciones-epidemiologicas',
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
 * possessive named something that did not exist.
 */
export function emptyText(fuente) {
	// The sentence opens with the name, and FUENTES names "el ISP" and "la OPS/OMS"
	// for mid-sentence use — lower-case, which would open this one lower-case.
	return t('epidemiologia', '{fuente} no trae alertas en este momento.', {
		fuente: fuente.charAt(0).toUpperCase() + fuente.slice(1),
	})
}

/**
 * How many items carry each value of `key`, as [{ id, count }].
 *
 * Filter options are built from the data rather than declared, so a filter can
 * never offer a value the list does not contain — and a value the source stops
 * publishing disappears on its own. Shared because the alertas pane builds its
 * año and mes menus this way, and writing it twice is how two panes' filters
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
 * ISP pane mapped a label on and Alertas vigentes did not — two panes building
 * the same menu two ways. It is built here now, so there is one way to build it.
 *
 * @param {Array} items - The pane's items.
 * @param {Function} yearOf - Reads an item's year; a fuente that stores it differently injects its own.
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

/**
 * The OPS/OMS's three kinds of publication, in the order its Tipo menu offers
 * them: the values PahoParser::tipo() writes, read off the title's first words.
 */
const TIPOS = {
	alerta: () => t('epidemiologia', 'Alerta'),
	actualizacion: () => t('epidemiologia', 'Actualización'),
	otro: () => t('epidemiologia', 'Otra publicación'),
}

/**
 * The Tipo menu's options: the kinds this list carries, in TIPOS' order, counted.
 *
 * Built from the data like the año and the mes: a list whose items carry no `tipo`
 * answers [], and the pane draws no menu — which is how five of the six sectors
 * have none without any of them saying so.
 *
 * ponytail: a value outside TIPOS is not offered (its items still show under
 * "Todos los tipos"); the backend writes only these three. Label it by its id the
 * day PahoParser grows a fourth.
 *
 * @param {Array} items - The pane's items.
 * @return {Array} `[{ id, count, label }]`.
 */
export function tipoOptions(items) {
	const counts = new Map(tally(items, (item) => item.tipo).map((o) => [o.id, o.count]))

	return Object.keys(TIPOS)
		.filter((id) => counts.has(id))
		.map((id) => ({ id, count: counts.get(id), label: TIPOS[id]() }))
}
