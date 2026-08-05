<!--
	The one row every list pane carries: search, its filters, and what is on screen.

	Measured before it existed: the alert panes spent 200px on a heading, a
	provenance line, a search field and a select before their first row, where Files
	spends 34px on a toolbar holding its action, its breadcrumb and three filters.
	This is that row, on the platform's own clickable-area grid.

	It exists as a component rather than as markup in each pane so the two panes
	cannot drift apart again — which is exactly what happened to their titles, their
	degraded notices and their filters when each was written separately.

	The provenance line it replaces is not lost: the navigation footer already
	carried the same sentence, so every screen was printing it twice.
-->
<template>
	<div class="epi-bar">
		<NcTextField class="epi-bar__find"
			:model-value="query"
			:label="t('epidemiologia', 'Buscar')"
			:show-trailing-button="!!query"
			trailing-button-icon="close"
			@update:model-value="$emit('update:query', $event)"
			@trailing-button-click="$emit('update:query', '')">
			<Magnify :size="16" />
		</NcTextField>

		<slot />

		<!-- A live region: this is the only feedback that a filter or a search changed
		     anything, and it updates on every keystroke while the list below re-renders.
		     Without it a screen-reader user typing in Buscar is told nothing at all, not
		     even that the result count fell to zero. -->
		<p class="epi-bar__count" role="status" aria-live="polite">
			{{ count }}
			<template v-if="fetchedAt">
				· {{ t('epidemiologia', 'leído') }}
				<NcDateTime :timestamp="fetchedAt * 1000" relative-time="long" />
			</template>
		</p>
	</div>
</template>

<script setup>
import { NcDateTime, NcTextField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'

defineProps({
	query: { type: String, default: '' },
	/** Already-pluralised, because only the pane knows what it is counting. */
	count: { type: String, required: true },
	fetchedAt: { type: Number, default: null },
})

defineEmits(['update:query'])
</script>

<style scoped>
/* Wraps rather than overflows: at 360px the search field alone is most of the
   width, so the filters drop to a second line instead of pushing the count off
   screen. The row is only one line tall where there is room for one. */
.epi-bar {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.epi-bar__find {
	width: auto;
	min-width: 200px;
	max-width: 280px;
	flex: 1 1 200px;
}

/* Pushed to the end on a wide row; on a wrapped one it simply follows. */
.epi-bar__count {
	margin: 0 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	white-space: nowrap;
}
</style>
