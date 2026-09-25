<!--
	One filter, as a compact button that opens its options — the shape Files uses
	for Tipo, Modificado and Personas.

	It replaces a full NcSelect per filter. A select is a permanently open control
	that takes a label, a box and a caret whether or not anyone is filtering; this
	is a 34px button that costs nothing until it is used. That difference is most of
	why the alert panes spent 200px before their first row.
-->
<template>
	<NcActions :menu-name="menuName" type="tertiary">
		<!--
			An icon, because NcActions' default is the three-dot overflow glyph, which
			says "more actions" rather than "filter" — Files gives each of its filters
			a meaningful one (a file for Tipo, a calendar for Modificado).
		-->
		<template #icon>
			<slot name="icon" />
		</template>

		<!--
			`model-value` is the GROUP's current value and `value` is THIS radio's own —
			NcActionRadio computes `checked` as `model === value`. Passing a boolean to
			model-value and omitting value left `checked` evaluating `true === ''`, so no
			option ever filled its circle and `aria-checked` was "false" for every one of
			them. The filter worked; it just never said what it had filtered (#44).

			ANY is a sentinel because a radio group cannot carry null: `value` defaults to
			'' and an omitted model-value would match it by accident.
		-->
		<NcActionRadio :name="radioName"
			:model-value="modelValue ?? ANY"
			:value="ANY"
			@update:model-value="$emit('update:modelValue', null)">
			{{ anyLabel }}
		</NcActionRadio>

		<!--
				ONE interpolation, never markup. NcActionRadio takes its label from
				ActionGlobalMixin.getText(), which is

					this.$slots.default?.()[0].children?.trim?.() || ''

				— the first vnode of the slot, and only if its children is a string.
				This was written as `{{ label }}<template v-if="count"> ({{ count }})</template>`
				and the count vnode was dropped in silence: no warning, no empty
				parens, just a menu that had never once shown a number. Anything that
				has to appear here goes into `optionText`, as one string.
			-->
		<NcActionRadio v-for="option in options"
			:key="option.id"
			:name="radioName"
			:model-value="modelValue ?? ANY"
			:value="option.id"
			@update:model-value="$emit('update:modelValue', option.id)">
			{{ optionText(option) }}
		</NcActionRadio>
	</NcActions>
</template>

<script setup>
import { computed } from 'vue'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActions from '@nextcloud/vue/components/NcActions'

const props = defineProps({
	/** What the button says when nothing is chosen: "Año", "Tipo", "Mes". */
	label: { type: String, required: true },
	/** Unique per menu, or two menus on one pane share a radio group. */
	radioName: { type: String, required: true },
	/** The "no filter" option's wording, e.g. "Todos los años". */
	anyLabel: { type: String, required: true },
	/** [{ id, label, count }] — the menu's options. */
	options: { type: Array, default: () => [] },
	modelValue: { type: [String, null], default: null },
})

defineEmits(['update:modelValue'])

/** Stands in for "no filter": NcActionRadio's value is a string, so null cannot be one. */
const ANY = '\u0000any'

/**
 * The label with its count, as a single string.
 *
 * The count is what makes a long menu usable: the ISP's Tipo had 14 categories
 * running from 10 alerts down to 1, and the number is what says which are worth
 * opening. Absent or zero, it is left off rather than printed as "(0)" — an
 * option nothing matches is not offered in the first place.
 *
 * @param {object} option - One entry of `options`.
 * @return {string} Its menu label.
 */
const optionText = (option) => (option.count
	? `${option.label} (${option.count})`
	: option.label)

/** The button carries the current choice, so a glance at the row says what is filtered. */
const menuName = computed(() => {
	if (props.modelValue === null) {
		return props.label
	}
	return props.options.find((o) => o.id === props.modelValue)?.label ?? props.label
})
</script>
