import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { PANES, PANE_IDS, paneName } from '../../src/panes.js'

/** A repo file as text — the other side of a contract this module cannot import. */
const text = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')

describe('paneName (L3-19)', () => {
	it('names every pane the registry carries', () => {
		for (const id of PANE_IDS) {
			expect(typeof paneName(id)).toBe('string')
			expect(paneName(id)).not.toBe('')
		}
		expect(paneName('informe')).toBe('Circulación de Virus Respiratorios')
		expect(paneName('ops')).toBe('Alertas y actualizaciones')
	})

	it('answers undefined for an unknown id rather than crashing mid-render', () => {
		expect(paneName('no-such-pane')).toBeUndefined()
	})
})

describe('the registry (L3-18)', () => {
	it('holds ten panes in navigation order', () => {
		expect(PANE_IDS).toEqual([
			'inicio',
			'alertas', 'epi', 'tablero', 'epivigila',
			'informe', 'anamed', 'farmacovigilancia', 'cosmeticos',
			'ops',
		])
	})

	it('gives every pane a name, an icon and a group', () => {
		for (const pane of PANES) {
			expect(typeof pane.icon).toBe('string')
			expect(['top', 'minsal', 'isp', 'ops']).toContain(pane.group)
		}
	})

	// A sector's radio names are `${id}-year|month|tipo`, and a native radio's
	// name is document-wide: all six are mounted at once, so two panes sharing an
	// id would share a radio group. Unique ids are what make the names unique.
	it('never gives two panes one id', () => {
		expect(new Set(PANE_IDS).size).toBe(PANE_IDS.length)
	})

	it('marks the six alert sectors with their backend id, in navigation order', () => {
		expect(PANES.filter((p) => p.source).map((p) => [p.id, p.source])).toEqual([
			['alertas', 'alerts'],
			['epi', 'epi_alerts'],
			['anamed', 'isp_anamed'],
			['farmacovigilancia', 'isp_pharmacovigilance'],
			['cosmeticos', 'isp_cosmetics'],
			['ops', 'paho_alerts'],
		])
	})

	// state.js reads a key the page does not carry as degraded (L3-14), so an id
	// misspelled here would render "sin contacto" forever and fail nowhere. The
	// ids are a contract with PHP; this reads PHP's side of it.
	it('spells every sector\'s id the way Service/Sources.php declares it', () => {
		const php = text('lib/Service/Sources.php')
		for (const { id, source } of PANES.filter((p) => p.source)) {
			expect(php, id).toContain(`= '${source}';`)
		}
	})

	// The registry holds icon NAMES; App.vue maps them to components. A name the
	// map lacks renders no glyph and no error — a blank row among iconed ones.
	it('has a component in App.vue for every icon it names', () => {
		const app = text('src/App.vue')
		for (const { id, icon } of PANES) {
			expect(app, id).toMatch(new RegExp(`^\\t'?${icon}'?: markRaw\\(`, 'm'))
		}
	})
})
