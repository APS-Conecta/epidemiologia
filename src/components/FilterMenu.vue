<!--
	One filter, as a compact button that opens its options — the shape Files uses
	for Tipo, Modificado and Personas.

	It replaces a full NcSelect per filter. A select is a permanently open control
	that takes a label, a box and a caret whether or not anyone is filtering; this
	is a 34px button that costs nothing until it is used. That difference is most of
	why the alert panes spent 200px before their first row.

	`groups` exists for one case: the ISP's Tipo. Its 14 live categories fall into
	families the ISP itself names — the `-dp` suffix, `cosmet…`, `desinfectante…` —
	so grouping them reads its nomenclature. The eight terms it does NOT adscribe to
	a family are passed as one group labelled as such, rather than distributed by
	resemblance, which would assert a classification the ISP never published.
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

		<NcActionRadio :name="radioName"
			:model-value="modelValue === null"
			@update:model-value="$emit('update:modelValue', null)">
			{{ anyLabel }}
		</NcActionRadio>

		<template v-for="group in shownGroups" :key="group.name ?? 'all'">
			<NcActionSeparator v-if="group.name" />
			<NcActionCaption v-if="group.name" :name="group.name" />
			<NcActionRadio v-for="option in group.options"
				:key="option.id"
				:name="radioName"
				:model-value="modelValue === option.id"
				@update:model-value="$emit('update:modelValue', option.id)">
				{{ option.label }}<template v-if="option.count"> ({{ option.count }})</template>
			</NcActionRadio>
		</template>
	</NcActions>
</template>

<script setup>
import { computed } from 'vue'
import { NcActionCaption, NcActionRadio, NcActionSeparator, NcActions } from '@nextcloud/vue'

const props = defineProps({
	/** What the button says when nothing is chosen: "Año", "Tipo", "Mes". */
	label: { type: String, required: true },
	/** Unique per menu, or two menus on one pane share a radio group. */
	radioName: { type: String, required: true },
	/** The "no filter" option's wording, e.g. "Todos los años". */
	anyLabel: { type: String, required: true },
	/** [{ id, label, count }] — flat menus. */
	options: { type: Array, default: () => [] },
	/** [{ name, options }] — grouped menus; `name: null` renders ungrouped. */
	groups: { type: Array, default: null },
	modelValue: { type: [String, null], default: null },
})

defineEmits(['update:modelValue'])

const shownGroups = computed(() => props.groups ?? [{ name: null, options: props.options }])

/** The button carries the current choice, so a glance at the row says what is filtered. */
const menuName = computed(() => {
	if (props.modelValue === null) {
		return props.label
	}
	const all = shownGroups.value.flatMap((g) => g.options)
	return all.find((o) => o.id === props.modelValue)?.label ?? props.label
})
</script>
