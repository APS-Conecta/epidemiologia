/**
 * The browser half of the accent-folding conformance table.
 *
 * `FoldConformanceTest.php` asserts the same cases against `MinsalRepository::fold()`,
 * reading the same `fold-cases.json`, so one edit to the table fails a test in each
 * suite. See that file's docblock for why the two implementations exist at all and how
 * far they are allowed to differ.
 */
import { describe, expect, it } from 'vitest'
import cases from './fold-cases.json'
import { fold } from '../../src/state.js'

describe('fold', () => {
	it.each(cases)('folds %j to %j', (input, expected) => {
		expect(fold(input)).toBe(expected)
	})
})
