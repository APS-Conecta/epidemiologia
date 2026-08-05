/**
 * The ISP's families, and the counts the navigation and the rail read off them.
 *
 * These are here because the navigation and the pane must agree about what a
 * family contains. They held two copies of `familia()` before #52, which is how a
 * filter and its menu drift into disagreeing — the same defect #44 produced in
 * FilterMenu and that nothing in PHP can see.
 */
import { describe, expect, it } from 'vitest'
import { categoriesIn, familiaOf, familiasPresent } from '../../src/familias.js'

/**
 * Real slugs, including the one that looks like it belongs to two families and does
 * not: `retiro-del-mercado-dispositivo-medico` reads as the medicamentos cluster's
 * `retiro-de-mercado` only if you misread `del` as `de`. Pinned because the next
 * person to widen that regex will assume it already overlaps.
 */
const alerts = [
	{ categories: ['anamed', 'retiro-de-mercado'] },
	{ categories: ['retiro-del-mercado-dispositivo-medico'] },
	{ categories: ['alertas-cosmeticas'] },
	{ categories: ['robo-hurto'] },
	{ categories: ['anamed'] },
]

describe('familiaOf', () => {
	it('reads the family off the ISP own naming', () => {
		expect(familiaOf('anamed')).toBe('medicamentos')
		expect(familiaOf('nota-informativa-dp')).toBe('dispositivos')
		expect(familiaOf('vigilancia-de-cosmeticos')).toBe('cosmeticos')
		expect(familiaOf('desinfectantes-y-sanitizantes')).toBe('desinfectantes')
	})

	it('puts a slug the ISP adscribes to nothing in otros, rather than guessing', () => {
		expect(familiaOf('robo-hurto')).toBe('otros')
		expect(familiaOf('informe-tecnico')).toBe('otros')
	})

	it('keeps the device retiro a device, however much it reads like a medicamento one', () => {
		expect(familiaOf('retiro-del-mercado-dispositivo-medico')).toBe('dispositivos')
	})
})

describe('familiasPresent', () => {
	it('counts an alert once per family, not once per membership', () => {
		// The first alert is in anamed AND retiro-de-mercado, both medicamentos.
		// Counting memberships would make it two.
		const medicamentos = familiasPresent(alerts).find((f) => f.id === 'medicamentos')

		expect(medicamentos.count).toBe(2)
	})

	it('leaves out a family nothing is published under, so no zero needs clearing', () => {
		expect(familiasPresent(alerts).map((f) => f.id)).not.toContain('desinfectantes')
	})

	it('returns them in the declared order, not in the order the data happened to arrive', () => {
		expect(familiasPresent(alerts).map((f) => f.id))
			.toStrictEqual(['dispositivos', 'cosmeticos', 'medicamentos', 'otros'])
	})
})

describe('categoriesIn', () => {
	it('keeps only the asked family', () => {
		expect(categoriesIn(alerts, 'medicamentos').map((c) => c.slug))
			.toStrictEqual(['anamed', 'retiro-de-mercado'])
	})

	it('takes every category when no family is asked for', () => {
		expect(categoriesIn(alerts, null)).toHaveLength(5)
	})

	it('sorts busiest first, and ties alphabetically so the rail does not reshuffle', () => {
		expect(categoriesIn(alerts, null)[0]).toStrictEqual({ slug: 'anamed', count: 2 })
	})
})
