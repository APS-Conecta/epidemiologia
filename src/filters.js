/**
 * The filter pair the alertas pane carries, as a composable.
 *
 * Its own file, not state.js, because state.js is deliberately testable under vitest's
 * `node` environment — its docblock in vitest.config.js says so and gives the reason. A
 * composable needs Vue, and Vue touches `document` once at import. state.js stays
 * derivation over plain data; this is the reactive half.
 *
 * That import is answered by the three lines of `tests/setup.js`, NOT by jsdom: this
 * line used to say its test "asks for jsdom", which vitest.config.js and
 * tests/unit/filters.test.js both contradict in writing. Nothing had to change for the
 * sentence to stop being true — it never was — and it is the one line a reader would
 * consult before adding the jsdom dependency both of those files exist to refuse.
 */
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { fold, monthOptions, yearOptions } from './state.js'

/**
 * The filter pair the alertas pane carries: a search box, a year and a month.
 *
 * Extracted for the reason the menus were in #44 — two panes had built the same
 * control two ways and shipped `undefined (12)` to clinicians. What did not move
 * then was everything the menus DRIVE: three refs, the folded array, the predicate
 * chain, and the year→month stranding rule, which was byte-identical in both
 * files, comment included.
 *
 * None of it had a test in any language when it was extracted; `filters.test.js`
 * owns the narrowing since, and `layout.mjs` check 6 still asserts the rendered
 * half — an option's count, a marked radio.
 *
 * The extra predicates stay in the pane that owns them: folding one in here would
 * give this an interface as complex as its body — the rule the ISP pane's family
 * and category filters followed until the fuente retired (ADR-0016).
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

	/**
	 * The three filters as dismissible tokens, worded for a reader.
	 *
	 * Here rather than in the pane because these three are the composable's own,
	 * and the last time a pane worded a shared control for itself it shipped
	 * `undefined (12)` to clinicians (#44). A pane with a filter of its own
	 * appends to this, the same way it composes its own predicates on top of
	 * `matches`.
	 *
	 * The month's wording is read off `months` rather than re-derived, so a token can
	 * never name a month differently from the menu that set it.
	 */
	const active = computed(() => {
		const out = []
		if (query.value.trim()) {
			out.push({ id: 'query', label: t('epidemiologia', '«{texto}»', { texto: query.value.trim() }) })
		}
		if (year.value) {
			out.push({ id: 'year', label: year.value })
		}
		if (month.value) {
			out.push({ id: 'month', label: months.value.find((m) => m.id === month.value)?.label ?? month.value })
		}

		return out
	})

	/**
	 * Dismiss one token. Unknown ids are ignored, so a pane can hand its own straight
	 * through without first checking whether this owns it.
	 *
	 * @param {string} id - the token's id
	 */
	function clear(id) {
		if (id === 'query') {
			query.value = ''
		} else if (id === 'year') {
			// The month goes with the year. Keeping it would strand a month inside no
			// year — the same pair the watch above exists to prevent from the other side.
			year.value = null
			month.value = null
		} else if (id === 'month') {
			month.value = null
		}
	}

	return { query, year, month, years, months, matches, active, clear }
}
