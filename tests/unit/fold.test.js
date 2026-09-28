/**
 * The browser half of the accent-folding conformance table.
 *
 * `FoldConformanceTest.php` asserts the same cases against `AlertRepository::fold()`,
 * reading the same `fold-cases.json`, so one edit to the table fails a test in each
 * suite. See that file's docblock for why the two implementations exist at all and how
 * far they are allowed to differ.
 *
 * The corpus join (L0-03): `src/aps/fold-corpus.json` is the org's shared corpus —
 * the same entries territorio's and farmacia's js suites fold — so this app now
 * answers both its own cases and the org's, from the one vendored fold.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import cases from './fold-cases.json'
import { fold } from '../../src/aps/filters.js'

const corpus = JSON.parse(readFileSync(new URL('../../src/aps/fold-corpus.json', import.meta.url), 'utf8'))

describe('fold', () => {
	it.each(cases)('folds %j to %j', (input, expected) => {
		expect(fold(input)).toBe(expected)
	})

	// The cross-language contract this app now shares with territorio and
	// farmacia's js suites: one corpus, one fold (the PHP FoldConformanceTest
	// keeps fold-cases.json; wiring it to the shared corpus is an
	// aps-common-side change outside this phase's file set — disclosed).
	it('answers the org corpus beside its own cases', () => {
		for (const { input, folded } of corpus) {
			expect(fold(input)).toBe(folded)
		}
	})
})
