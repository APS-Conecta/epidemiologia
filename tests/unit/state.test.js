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
import { alerts, reports } from '../../src/state.js'
import { degradedText, emptyText, fecha, mes, monthOptions, tally, yearOptions } from '../../src/pure.js'

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
})

describe('the state half (L3-17)', () => {
	// The setup shim answers no initial-state node, so `sources` is the
	// fallback {} — which makes THIS suite the missing-key path (L3-14):
	// an undefined key reads as degraded, never as a publisher saying zero.
	it('reads a missing fuente as degraded, not as empty', () => {
		expect(alerts).toEqual({ items: [], fetched_at: null, degraded: true })
		expect(reports).toEqual({ items: [], fetched_at: null, degraded: true })
	})

	// The split, completed (L7-01): the shim is gone, so a pure import can
	// no longer drag the loadState machinery with it. The import sites
	// resolve to pure.js directly — what this pins is the ABSENCE of the
	// re-export, which is the property the split existed to deliver.
	it('no longer re-exports the pure half', () => {
		for (const name of ['fold', 'fecha', 'mes', 'degradedText', 'emptyText', 'tally', 'yearOptions', 'monthOptions', 'FUENTES']) {
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
