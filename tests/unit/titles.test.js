/**
 * Every title here is real, copied from the ISP's alert feed back when this app
 * read it, and the tags are the labels those items actually carried. The
 * fixtures and the reader both retired with the fuente (ADR-0016); the titles
 * stay because they are the corpus the rule below was measured against.
 *
 * The property under test is not the hit rate. It is that a preamble is quietened
 * ONLY when a tag on the same row already says it, so nothing can be de-emphasised
 * that appears nowhere else.
 */
import { describe, expect, it } from 'vitest'
import { splitTitle } from '../../src/titles.js'

const RETIRO = 'ALERTA DE RETIRO DEL MERCADO N° 22/26 DEL PRODUCTO FARMACÉUTICO TELLMI AM 80/5 comprimidos DE Laboratorios Saval S.A'

describe('splitTitle', () => {
	it('splits the preamble off the product a pharmacist is looking for', () => {
		const { prefix, rest } = splitTitle(RETIRO, ['Retiro de mercado'])

		expect(prefix).toBe('ALERTA DE RETIRO DEL MERCADO N° 22/26 DEL PRODUCTO FARMACÉUTICO')
		expect(rest).toBe('TELLMI AM 80/5 comprimidos DE Laboratorios Saval S.A')
	})

	it('loses not one character of the title', () => {
		const { prefix, rest } = splitTitle(RETIRO, ['Retiro de mercado'])

		// The safety argument in one assertion: this is a split, never an edit.
		expect(`${prefix} ${rest}`).toBe(RETIRO)
	})

	it('matches "de" against "del", which is the commonest alert the ISP publishes', () => {
		// The tag reads "Retiro de mercado"; the title reads "RETIRO DEL MERCADO".
		// Comparing whole strings fails here, and this is 10 of 15 real titles.
		expect(splitTitle(RETIRO, ['Retiro de mercado'])).not.toBe(null)
	})

	it('leaves the title alone when no tag on the row says it', () => {
		// Same title, tagged only ANAMED. Nothing on the row repeats "retiro del
		// mercado", so nothing may be quietened. Dims less when tags are missing,
		// never more.
		expect(splitTitle(RETIRO, ['ANAMED'])).toBe(null)
		expect(splitTitle(RETIRO, [])).toBe(null)
	})

	it('leaves a human-written title alone: there is no preamble to find', () => {
		const t = 'Advertencias sobre la seguridad de los autobronceantes tópicos y sus posibles riesgos en la piel'

		expect(splitTitle(t, ['Cosméticos', 'Advertencia de seguridad'])).toBe(null)
	})

	it('handles a short preamble that is genuinely repeated', () => {
		const { prefix, rest } = splitTitle(
			'Informe Técnico: Vacunas SARS-CoV-2 y su uso durante el embarazo',
			['Informe técnico'])

		expect(prefix).toBe('Informe Técnico:')
		expect(rest).toBe('Vacunas SARS-CoV-2 y su uso durante el embarazo')
	})

	it('keeps an amplification, which changes what the alert is', () => {
		// "AMPLIACIÓN Nº 1 DE" survives inside the preamble rather than being treated as
		// the subject: it is administrative, and the product is still what differs.
		const { prefix, rest } = splitTitle(
			'AMPLIACIÓN Nº 1 DE ALERTA DE RETIRO DEL MERCADO N° 08/2026 DEL PRODUCTO FARMACEUTICO MULCATEL SUSPENSIÓN ORAL 10% FABRICADO POR LABORATORIO MAVER S.A.',
			['Retiro de mercado'])

		expect(prefix).toContain('AMPLIACIÓN')
		expect(rest.startsWith('MULCATEL')).toBe(true)
	})

	it('refuses to promote a subject too short to be one', () => {
		expect(splitTitle('ALERTA DE RETIRO DEL MERCADO: X', ['Retiro de mercado'])).toBe(null)
	})

	it('survives a title that is nothing but a delimiter', () => {
		expect(splitTitle(':', ['Retiro de mercado'])).toBe(null)
		expect(splitTitle('', ['Retiro de mercado'])).toBe(null)
	})
})
