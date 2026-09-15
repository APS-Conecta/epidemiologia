/**
 * The ISP's 24 category slugs live in PHP, and TWO JavaScript files mirror them.
 *
 * `IspRepository::TERMS` is the fetch list and, because `Sources::VOCABULARY` reads it,
 * also the API's published vocabulary — it is what decides 400 from 200 on
 * `?category=`. On the browser side `familias.js` groups those slugs into families and
 * `IspPane.vue`'s `KIND_LABELS` gives each one a Spanish label. Nothing connected the
 * three, and the drift is silent by construction: an unknown slug does not throw, it
 * falls through `kindLabel()` to `prettify()` and through `familiaOf()` to the `otros`
 * floor, so a 25th category arrives looking like a plausible label in a plausible group.
 *
 * Read as TEXT rather than imported, for the reason family-icons.test.js gives: these
 * run under vitest's `node` environment on purpose (vitest.config.js), PHP cannot be
 * imported at all, and IspPane.vue is a single-file component. Same trade, same shape.
 *
 * NOT the test the 2026-09 audit plan proposed. That one asserted every slug matched
 * exactly one entry of FAMILIAS — which is red on arrival, because `otros` is the floor
 * and its predicate is `() => true`, so all 16 classified slugs match two. Measured: it
 * fails 16 of 24, starting at `anamed`, against a plan that says it should pass.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { familiaOf } from '../../src/familias.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

/** The slugs as the PHP declares them — the side that does the fetching. */
const terms = () => {
	const block = read('../../lib/Service/IspRepository.php').match(/public const TERMS = \[([\s\S]*?)\];/)
	expect(block, 'TERMS is not where this test expects it in IspRepository.php').not.toBe(null)

	return [...block[1].matchAll(/'([a-z0-9-]+)'/g)].map((m) => m[1])
}

/** The slugs IspPane gives a Spanish label to. Keys are quoted or bare, hence `'?`. */
const labelled = () => {
	const block = read('../../src/components/IspPane.vue').match(/const KIND_LABELS = \{([\s\S]*?)\n\}/)
	expect(block, 'KIND_LABELS is not where this test expects it in IspPane.vue').not.toBe(null)

	return [...block[1].matchAll(/^\t'?([a-z0-9-]+)'?:/gm)].map((m) => m[1])
}

/**
 * The eight the ISP adscribes to nothing, named rather than counted.
 *
 * familias.js opens by saying 16 of the 24 slugs name their own family and the other
 * eight do not; this is that sentence as an assertion. Naming them is what makes the
 * test fail in BOTH directions a regex can drift: widened to swallow one of these
 * eight, or narrowed to drop one of the sixteen. A bare count would notice neither,
 * because the two mistakes cancel.
 */
const UNCLASSIFIED = [
	'advertencia-de-seguridad',
	'informacion-de-seguridad',
	'informe-tecnico',
	'nota-informativa',
	'actualizacion-de-seguridad',
	'cese-de-distribucion',
	'actualizacion-de-software',
	'robo-hurto',
]

describe('the ISP category slugs, across PHP and JS', () => {
	it('still numbers 24, so an extraction that silently found nothing cannot pass', () => {
		// The anti-vacuity guard, and it goes first. Every assertion below is over a list
		// read out of another language by regex; an empty list satisfies all of them.
		expect(terms()).toHaveLength(24)
	})

	it('puts exactly the eight the ISP declares no family for in `otros`', () => {
		const otros = terms().filter((slug) => familiaOf(slug) === 'otros')

		// Fails on a 25th slug the ISP adds, and on either direction of regex drift in
		// familias.js — which is the whole point, since neither breaks loudly today.
		expect(otros.sort()).toStrictEqual([...UNCLASSIFIED].sort())
	})

	it('gives every slug a label, and labels no slug that is not one', () => {
		// The other half of the mirror. A missing key renders through prettify() as
		// "Robo hurto" — plausible enough that nobody reports it — and a stale key is a
		// label for a category the app no longer fetches.
		expect(labelled().sort()).toStrictEqual(terms().sort())
	})
})
