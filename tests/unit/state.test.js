/**
 * The filter options the alertas pane offers.
 *
 * These are here because the two alert panes once built them separately and
 * drifted: the ISP pane mapped a label onto every year, Alertas vigentes did
 * not, and FilterMenu interpolated `undefined (12)` into the live menu for
 * weeks (#44). Nothing in PHP can see that, and the layout gate needs a
 * provisioned instance — so the shape of an option is pinned here, where it
 * costs milliseconds.
 */
import { describe, expect, it } from 'vitest'
import * as stateExports from '../../src/state.js'
import { reports, source } from '../../src/state.js'
import { countText, degradedText, emptyText, fecha, mes, monthOptions, tally, tipoOptions, yearOptions, FUENTES } from '../../src/pure.js'
import { PANES } from '../../src/panes.js'

/**
 * Shaped like what the alert panes actually held: MINSAL carries year/month, the
 * ISP a date — the date shape is what exercises the accessors.
 *
 * Deliberately NOT in newest-first order. With a sorted fixture, tally() preserves
 * insertion order and every ordering assertion below passes against a function that
 * does no sorting at all — proved by mutation, which is how this comment came to exist.
 */
const MINSAL = [
	{ title: 'OFICIO D', year: '2025', month: '2025-11' },
	{ title: 'OFICIO C', year: '2026', month: '2026-06' },
	{ title: 'OFICIO A', year: '2026', month: '2026-07' },
	{ title: 'OFICIO B', year: '2026', month: '2026-07' },
]
const ISP = [
	{ title: 'Suturas V-Loc', date: '2025-12-04' },
	{ title: 'Cápsula Ozono', date: '' },
	{ title: 'ÁCIDO VALPROICO', date: '2026-08-04' },
	{ title: 'TELLMI ADVANCE', date: '2026-07-28' },
]
const yearOf = (a) => a.year
const monthOf = (a) => a.month
const ispYear = (a) => (a.date ?? '').slice(0, 4)
const ispMonth = (a) => (a.date ?? '').slice(0, 7)

describe('tally', () => {
	// tally counts; ordering is yearOptions'/monthOptions' job, and asserting it here
	// would let an unsorted builder pass on a fixture that happened to arrive sorted.
	it('counts how many items carry each value, in the order it first met them', () => {
		expect(tally(MINSAL, yearOf)).toEqual([
			{ id: '2025', count: 1 },
			{ id: '2026', count: 3 },
		])
	})

	it('offers no option for items that carry no value', () => {
		expect(tally(ISP, ispYear).map((o) => o.id)).not.toContain('')
	})
})

describe('yearOptions', () => {
	// The defect: FilterMenu renders `${option.label} (${option.count})`, so an
	// option without a label reaches a clinician as "undefined (3)".
	it('gives every option a label', () => {
		for (const option of yearOptions(MINSAL, yearOf)) {
			expect(option.label).toBeDefined()
			expect(String(option.label)).not.toBe('undefined')
		}
	})

	it('labels a year with the year itself', () => {
		expect(yearOptions(MINSAL, yearOf)).toEqual([
			{ id: '2026', count: 3, label: '2026' },
			{ id: '2025', count: 1, label: '2025' },
		])
	})

	it('orders newest first', () => {
		expect(yearOptions(ISP, ispYear).map((o) => o.id)).toEqual(['2026', '2025'])
	})

	// The whole point of sharing it: every pane that uses it gets the same shape
	// from one place.
	it('builds the same shape from a date-carrying item as from a year-carrying one', () => {
		const fromMinsal = yearOptions(MINSAL, yearOf)
		const fromIsp = yearOptions(ISP, ispYear)

		expect(Object.keys(fromIsp[0]).sort()).toEqual(Object.keys(fromMinsal[0]).sort())
	})
})

describe('monthOptions', () => {
	it('gives every option a label', () => {
		for (const option of monthOptions(MINSAL, yearOf, monthOf, null)) {
			expect(option.label).toBeDefined()
			expect(String(option.label)).not.toBe('undefined')
		}
	})

	// Offering a month the chosen year does not have builds a filter pair that can
	// only ever return nothing, which reads as broken data rather than a bad filter.
	it('offers only the months of the chosen year', () => {
		expect(monthOptions(MINSAL, yearOf, monthOf, '2025').map((o) => o.id)).toEqual(['2025-11'])
	})

	it('offers every month when no year is chosen', () => {
		expect(monthOptions(MINSAL, yearOf, monthOf, null).map((o) => o.id))
			.toEqual(['2026-07', '2026-06', '2025-11'])
	})

	it('orders newest first', () => {
		expect(monthOptions(ISP, ispYear, ispMonth, null).map((o) => o.id))
			.toEqual(['2026-08', '2026-07', '2025-12'])
	})
})

describe('fecha', () => {
	it('renders the ISO day the repository derived, without moving it', () => {
		// Local midnight, so no offset can push the day the string names. Rendering
		// `at` in the browser instead put the pane in the reader's zone while its own
		// filters used this day, and the two disagreed for anything published late.
		expect(fecha('2026-07-31')).toContain('31')
		expect(fecha('2026-07-31')).not.toContain('1 de agosto')
	})

	it('says nothing when there is no day, rather than Invalid Date', () => {
		expect(fecha(null)).toBe('')
		expect(fecha('')).toBe('')
		expect(fecha('2026-07')).toBe('')
	})
})

describe('degradedText / emptyText', () => {
	// t() substitutes {nombre} and %n. printf's %s is not a token it knows, so both
	// sentences shipped the literal "%s" to clinicians on the degraded path, at six
	// render sites, invisible to PHPUnit and to a reviewer reading the source.
	// toBe and not toContain: a placeholder that survives has to fail here, and with
	// vars an object and no count, t() also runs .replace(/%n/g,"undefined") on these.
	it('puts the source into the sentence', () => {
		expect(degradedText('MINSAL')).toBe('No hemos podido contactar con MINSAL, así que esta lista puede estar incompleta.')
		expect(emptyText('El ISP')).toBe('El ISP no trae alertas en este momento.')
	})

	// FUENTES names publishers for mid-sentence use — "el ISP", "la OPS/OMS" — and
	// emptyText opens its sentence with the name. Every sector pane passes it one.
	it('opens its sentence with a capital whatever article the name carries', () => {
		expect(emptyText(FUENTES.minsal.name)).toBe('MINSAL no trae alertas en este momento.')
		expect(emptyText(FUENTES.isp.name)).toBe('El ISP no trae alertas en este momento.')
		expect(emptyText(FUENTES.ops.name)).toBe('La OPS/OMS no trae alertas en este momento.')
	})

	it('keeps the article lower-case mid-sentence', () => {
		expect(degradedText(FUENTES.ops.name)).toBe('No hemos podido contactar con la OPS/OMS, así que esta lista puede estar incompleta.')
	})
})

describe('FUENTES.count', () => {
	// The toolbar's count, in each publisher's noun: MINSAL's lists are in force,
	// the ISP's are alertas, and the OPS/OMS list is more than alertas.
	it('counts in each publisher\'s own noun, singular and plural', () => {
		expect(FUENTES.minsal.count(13)).toBe('13 alertas vigentes')
		expect(FUENTES.minsal.count(1)).toBe('1 alerta vigente')
		expect(FUENTES.isp.count(10)).toBe('10 alertas')
		expect(FUENTES.ops.count(54)).toBe('54 publicaciones')
		expect(FUENTES.ops.count(1)).toBe('1 publicación')
	})
})

describe('countText', () => {
	// The toolbar's count. A fuente nobody could read that holds nothing has none:
	// "0 alertas vigentes" beside the warning is the publisher-said-zero claim L3-14
	// refuses — the state epi_alerts is in while epi.minsal.cl refuses this server.
	it('counts nothing for a fuente nobody could read that holds nothing', () => {
		expect(countText(FUENTES.minsal, { items: [], degraded: true }, 0)).toBe('')
		// The missing-key path this suite runs on (state.js): degraded and empty.
		expect(countText(FUENTES.minsal, source('epi_alerts'), 0)).toBe('')
	})

	it('counts a last copy served degraded, and a real answer of zero', () => {
		expect(countText(FUENTES.isp, { items: [{}, {}], degraded: true }, 2)).toBe('2 alertas')
		expect(countText(FUENTES.minsal, { items: [], degraded: false }, 0)).toBe('0 alertas vigentes')
	})

	it('counts what the filters let through against the total', () => {
		expect(countText(FUENTES.ops, { items: [{}, {}, {}], degraded: false }, 1)).toBe('1 de 3 publicaciones')
	})
})

describe('tipoOptions', () => {
	// Shaped like paho_alerts' items, and deliberately NOT in the menu's order — an
	// options builder that did no ordering must not pass on a fixture that arrived sorted.
	const PAHO = [
		{ title: 'Nota informativa: vacunación', tipo: 'otro' },
		{ title: 'Actualización epidemiológica Sarampión', tipo: 'actualizacion' },
		{ title: 'Alerta epidemiológica Fiebre amarilla', tipo: 'alerta' },
		{ title: 'Actualización epidemiológica Dengue', tipo: 'actualizacion' },
	]

	it('offers the kinds the list carries, in a fixed order, counted and labelled', () => {
		expect(tipoOptions(PAHO)).toEqual([
			{ id: 'alerta', count: 1, label: 'Alerta' },
			{ id: 'actualizacion', count: 2, label: 'Actualización' },
			{ id: 'otro', count: 1, label: 'Otra publicación' },
		])
	})

	// The pane draws the menu only when this is non-empty: five sectors carry no tipo.
	it('offers nothing for a list whose items carry no tipo', () => {
		expect(tipoOptions(MINSAL)).toEqual([])
	})

	it('never offers a kind the list does not carry', () => {
		expect(tipoOptions(PAHO.slice(1, 2)).map((o) => o.id)).toEqual(['actualizacion'])
	})
})

describe('the state half (L3-17)', () => {
	// The setup shim answers no initial-state node, so `sources` is the
	// fallback {} — which makes THIS suite the missing-key path (L3-14):
	// an undefined key reads as degraded, never as a publisher saying zero.
	it('reads a missing fuente as degraded, not as empty', () => {
		expect(reports).toEqual({ items: [], fetched_at: null, degraded: true })
	})

	// source() is that rule for any id: every sector the registry names, and one it does not.
	it('reads any id the page does not carry as degraded', () => {
		for (const { id, source: key } of PANES.filter((p) => p.source)) {
			expect(source(key), id).toEqual({ items: [], fetched_at: null, degraded: true })
		}
		expect(source('no_such_id')).toEqual({ items: [], fetched_at: null, degraded: true })
	})

	// The split, completed (L7-01): the shim is gone, so a pure import can
	// no longer drag the loadState machinery with it. The import sites
	// resolve to pure.js directly — what this pins is the ABSENCE of the
	// re-export, which is the property the split existed to deliver.
	it('no longer re-exports the pure half', () => {
		for (const name of ['fold', 'fecha', 'mes', 'degradedText', 'countText', 'emptyText', 'tally', 'yearOptions', 'monthOptions', 'tipoOptions', 'FUENTES', 'SITIOS']) {
			expect(stateExports).not.toHaveProperty(name)
		}
	})
})

describe('mes (L3-19)', () => {
	// The assertion tests/setup.js's own comment has promised since it was
	// written — "mes() must render 'julio de 2026', never 'July 2026" — and
	// no test ever made.
	it('renders the month in the page\'s locale', () => {
		expect(mes('2026-07')).toBe('julio de 2026')
	})
})
