import { describe, expect, it } from 'vitest'
import { PANES, PANE_IDS, paneName } from '../../src/panes.js'

describe('paneName (L3-19)', () => {
	it('names every pane the registry carries', () => {
		for (const id of PANE_IDS) {
			expect(typeof paneName(id)).toBe('string')
			expect(paneName(id)).not.toBe('')
		}
		expect(paneName('informe')).toBe('Circulación de Virus Respiratorios')
	})

	it('answers undefined for an unknown id rather than crashing mid-render', () => {
		expect(paneName('no-such-pane')).toBeUndefined()
	})
})

describe('the registry (L3-18)', () => {
	it('holds five panes in navigation order', () => {
		expect(PANE_IDS).toEqual(['inicio', 'alertas', 'tablero', 'epivigila', 'informe'])
	})

	it('gives every pane a name, an icon and a group', () => {
		for (const pane of PANES) {
			expect(typeof pane.icon).toBe('string')
			expect(['top', 'minsal', 'isp']).toContain(pane.group)
		}
	})
})
