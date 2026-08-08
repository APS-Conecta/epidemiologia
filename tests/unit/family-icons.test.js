/**
 * The icon map in App.vue is keyed by family id, and the family ids live in
 * familias.js. Two files holding one fact, which is the shape this repo keeps
 * getting bitten by — familias.js itself exists because the navigation and the rail
 * held two copies of `familia()` and drifted (#52).
 *
 * Rename a family there and its icon does not break loudly: the lookup misses and
 * the row silently falls back to the empty box `otros` uses. So the drift is
 * invisible in exactly the way this suite exists to prevent.
 *
 * Read as TEXT rather than imported, because App.vue is a single-file component and
 * these tests run under vitest's `node` environment on purpose — see
 * vitest.config.js. Same trade MetadataTest.php makes for the licence headers.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { FAMILIAS } from '../../src/familias.js'

const source = readFileSync(new URL('../../src/App.vue', import.meta.url), 'utf8')

const mapped = () => {
	const block = source.match(/const FAMILY_ICONS = \{([\s\S]*?)\n\}/)
	expect(block, 'FAMILY_ICONS is not where this test expects it in App.vue').not.toBe(null)

	return [...block[1].matchAll(/^\t([a-z]+):/gm)].map((m) => m[1])
}

describe('FAMILY_ICONS', () => {
	it('names only families that actually exist', () => {
		const ids = FAMILIAS.map((f) => f.id)

		// The failure this catches: familias.js renames a family, App.vue keeps the old
		// key, and that row loses its glyph without anything going red.
		expect(mapped().filter((id) => !ids.includes(id))).toEqual([])
	})

	it('gives `otros` no icon, which is the half of the old rule that survived', () => {
		// ADR-0013: "Sin familia declarada por el ISP" is exactly where a glyph would
		// invent the classification the original one-icon rule existed to prevent.
		expect(mapped()).not.toContain('otros')
	})

	it('covers every family the ISP names', () => {
		// Not a style rule — the four non-`otros` families are the ISP's own
		// nomenclature (familias.js counts 16 of 24 slugs naming their family), so each
		// one having a glyph is the decision ADR-0013 records. Adding a fifth family
		// fails here until someone chooses for it, rather than shipping a blank row.
		const named = FAMILIAS.map((f) => f.id).filter((id) => id !== 'otros')

		expect(mapped().sort()).toEqual(named.sort())
	})
})
