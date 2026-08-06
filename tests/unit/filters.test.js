/**
 * The filter pair both alert panes carry, and the first tests it has ever had.
 *
 * Under `node`, like everything else here: Vue touches `document` once at import, and
 * tests/setup.js answers it. See vitest.config.js for why there is no jsdom.
 */
import { describe, expect, it } from 'vitest'
import { useFilters } from '../../src/filters.js'

describe('useFilters', () => {
	const items = [
		{ title: 'Sarampión en las Américas', date: '2026-08-01' },
		{ title: 'Dengue en la Región', date: '2026-07-15' },
		{ title: 'Fiebre amarilla', date: '2025-05-31' },
	]
	const accessors = {
		yearOf: (a) => a.date.slice(0, 4),
		monthOf: (a) => a.date.slice(0, 7),
		textOf: (a) => a.title,
	}
	const titles = (f) => f.matches.value.map((a) => a.title)

	it('shows everything before anything is chosen', () => {
		expect(titles(useFilters(items, accessors))).toHaveLength(3)
	})

	it('finds an accented title typed without its accent', () => {
		const f = useFilters(items, accessors)
		f.query.value = 'sarampion'

		// The reason fold() exists, and the first thing about this pane nobody could test.
		expect(titles(f)).toEqual(['Sarampión en las Américas'])
	})

	it('narrows to a year', () => {
		const f = useFilters(items, accessors)
		f.year.value = '2025'

		expect(titles(f)).toEqual(['Fiebre amarilla'])
	})

	it('narrows to a month within a year', () => {
		const f = useFilters(items, accessors)
		f.month.value = '2026-07'

		expect(titles(f)).toEqual(['Dengue en la Región'])
	})

	it('clears a month the newly chosen year does not have', async () => {
		// The rule that was copied byte-for-byte into both panes and asserted nowhere.
		// Without it the pane shows zero results, which reads as a fault in the data
		// rather than in the filter pair.
		const f = useFilters(items, accessors)
		f.month.value = '2026-07'
		f.year.value = '2025'
		await Promise.resolve()

		expect(f.month.value).toBeNull()
		expect(titles(f)).toEqual(['Fiebre amarilla'])
	})

	it('combines the search box with a year', () => {
		const f = useFilters(items, accessors)
		// 'region' matches Sarampión's "Américas"? No — but 'en la' did, in both rows.
		// The first version of this test expected one row and got two, which is the
		// composable working and the expectation being wrong.
		f.query.value = 'region'
		f.year.value = '2026'

		expect(titles(f)).toEqual(['Dengue en la Región'])
	})
})
