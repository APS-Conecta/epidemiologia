import { fold } from './state.js'

/**
 * Splitting the administrative preamble off an alert title, so the eye lands on the
 * product instead of on the fourth repetition of "ALERTA DE RETIRO DEL MERCADO".
 *
 * `AlertRow.vue` already named this lever and never pulled it:
 *
 *   > If the length ever has to come down, the lever is the boilerplate, not the
 *   > subject: every title opens with the same 25 characters of reference. Moving
 *   > that elsewhere shortens the row while still showing everything.
 *
 * Measured on the real feeds: three consecutive retiro alerts share 63 identical
 * characters before the product name, and those characters say "retiro del mercado"
 * — which the category tag on the same row already says. The row states its kind
 * twice and buries the one part that differs.
 *
 * NOTHING IS REMOVED. This returns where to split so the caller can render the
 * preamble quietly; the full title stays in the DOM and in the accessibility tree.
 * That is the whole safety argument, and it is #100's lesson: 27 of 75 MINSAL
 * alerts once reached a clinician titled "Descargar Documento" because a page's
 * structure was read wrong. A parse that deletes can delete a drug name. A parse
 * that only changes colour cannot.
 */

/**
 * The preamble ends at the first of: the phrase naming what kind of product this is,
 * a colon, or a full stop. Bounded at 120 characters because a "preamble" longer
 * than that is not a preamble, it is the title.
 *
 * Lazy on purpose (`.{10,120}?`): the FIRST delimiter, not the last, or a title with
 * two colons would dim past its own subject.
 */
const PREAMBLE = /^(.{10,120}?(?:PRODUCTO\s+FARMAC[EÉ]UTICO|:|\.))\s+/i

/** Below this, what is left is not a subject worth promoting. */
const MIN_REST = 8

/**
 * Where to split a title, or null to render it exactly as before.
 *
 * The guard is the second argument: a preamble is only quietened when a tag on the
 * SAME ROW already carries its meaning. That is what makes this safe rather than
 * clever — it cannot dim something that appears nowhere else, because the test for
 * dimming *is* "this appears somewhere else".
 *
 * Words shorter than four characters are ignored when matching, and that is
 * load-bearing rather than tidy: the tag reads "Retiro de mercado" while the title
 * reads "RETIRO DEL MERCADO". Comparing whole strings fails on `de` against `del`,
 * which is the single most common alert the ISP publishes.
 *
 * @param {string} title - the alert's title, as published
 * @param {Array<string>} tags - the category labels rendered on this row
 * @return {?{prefix: string, rest: string}} where to split, or null to leave it alone
 */
export function splitTitle(title, tags = []) {
	const match = PREAMBLE.exec(String(title))
	if (!match) {
		return null
	}

	const prefix = match[1]
	const rest = String(title).slice(match[0].length)
	if (rest.length < MIN_REST) {
		return null
	}

	const folded = fold(prefix)
	const said = tags.some((tag) => {
		const words = (fold(tag).match(/[a-z0-9]+/g) ?? []).filter((w) => w.length >= 4)

		return words.length > 0 && words.some((w) => folded.includes(w))
	})

	return said ? { prefix, rest } : null
}
