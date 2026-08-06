/**
 * The filter pair both alert panes carry, as a composable.
 *
 * Its own file, not state.js, because state.js is deliberately testable under vitest's
 * `node` environment — its docblock in vitest.config.js says so and gives the reason. A
 * composable needs Vue, Vue needs a DOM at import, so it lives here and its test asks for
 * jsdom. state.js stays derivation over plain data; this is the reactive half.
 */
import { computed, ref, watch } from 'vue'
import { fold, monthOptions, yearOptions } from './state.js'

/**
 * The filter pair both alert panes carry: a search box, a year and a month.
 *
 * Extracted for the reason the menus were in #44 — the panes had built the same control
 * two ways and shipped `undefined (12)` to clinicians. What did not move then was
 * everything the menus DRIVE: three refs, the folded array, the predicate chain, and the
 * year→month stranding rule, which was byte-identical in both files, comment included.
 *
 * And none of it had a test in any language. `layout.mjs` check 6 asserts that an option
 * renders a count and that choosing one marks its radio; nothing asserted the list
 * narrowed. This is the app's most-used interaction.
 *
 * The extra predicates stay in the panes. `IspPane` composes family and category on top of
 * `matches`; folding those in here would give this an interface as complex as its body.
 *
 * @param {Array} items - the fuente's items, loaded once and never mutated
 * @param {object} accessors - `yearOf`, `monthOf` and `textOf`, each taking one item
 * @return {object} `{ query, year, month, years, months, matches }`
 */
export function useFilters(items, { yearOf, monthOf, textOf }) {
	const query = ref('')
	const year = ref(null)
	const month = ref(null)

	const years = yearOptions(items, yearOf)
	const months = computed(() => monthOptions(items, yearOf, monthOf, year.value))

	// Changing the year can strand a month that year does not have, which would show zero
	// results and read as a fault in the data rather than in the filter pair.
	watch(year, () => {
		if (month.value && !months.value.some((m) => m.id === month.value)) {
			month.value = null
		}
	})

	// Folded once, not per keystroke: every row times every character typed adds up.
	const folded = items.map((i) => ({ ...i, _fold: fold(textOf(i)) }))

	const matches = computed(() => {
		const needle = fold(query.value.trim())

		return folded.filter((i) => (!needle || i._fold.includes(needle))
			&& (!year.value || yearOf(i) === year.value)
			&& (!month.value || monthOf(i) === month.value))
	})

	return { query, year, month, years, months, matches }
}
