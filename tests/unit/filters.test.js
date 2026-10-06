/**
 * The filter pair the alertas pane carries, and the first tests it has ever had.
 *
 * Under `node`, like everything else here: Vue touches `document` once at import, and
 * tests/setup.js answers it. See vitest.config.js for why there is no jsdom.
 */
import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
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

	it('updates matches when reactive items change', () => {
		const reactiveItems = ref([
			{ title: 'Sarampión en las Américas', date: '2026-08-01' },
		])
		const f = useFilters(reactiveItems, accessors)

		expect(titles(f)).toEqual(['Sarampión en las Américas'])

		reactiveItems.value = [
			{ title: 'Sarampión en las Américas', date: '2026-08-01' },
			{ title: 'Dengue en la Región', date: '2026-07-15' },
		]

		expect(titles(f)).toEqual([
			'Sarampión en las Américas',
			'Dengue en la Región',
		])
	})
})

describe('useFilters — the token flow (L3-19)', () => {
	// This block's own fixtures (the file's existing `items`/`accessors` are
	// scoped to the other describe callback — N2):
	const ITEMS = [
		{ title: 'Sarampión', year: '2026', month: '2026-07' },
		{ title: 'OFICIO D', year: '2025', month: '2025-11' },
	]
	const ACCESSORS = { yearOf: (a) => a.year, monthOf: (a) => a.month, textOf: (a) => a.title }

	it('shows a token per set filter, worded', () => {
		const { query, year, month, active } = useFilters(ITEMS, ACCESSORS)
		expect(active.value).toEqual([])

		query.value = 'sarampión'
		year.value = '2026'
		month.value = '2026-07'
		expect(active.value.map((t) => t.id)).toEqual(['query', 'year', 'month'])
		expect(active.value[0].label).toContain('sarampión')
	})

	it('clearing the year takes the month with it', () => {
		const { year, month, active, clear, months } = useFilters(ITEMS, ACCESSORS)
		year.value = '2025'
		month.value = months.value.find((m) => m.id.startsWith('2025-'))?.id ?? null
		clear('year')
		expect(year.value).toBeNull()
		expect(month.value).toBeNull('a month stranded inside no year reads as broken data — the rule that "shipped untested once already"')
		expect(active.value).toEqual([])
	})

	it('ignores a token id it does not own', () => {
		const { clear, active } = useFilters(ITEMS, ACCESSORS)
		expect(() => clear('family')).not.toThrow()
		expect(active.value).toEqual([])
	})
})
