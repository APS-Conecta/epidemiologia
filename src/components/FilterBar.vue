<!--
	The one row every list pane carries: search, its filters, and what is on screen.

	Measured before it existed: the alert panes spent 200px on a heading, a
	provenance line, a search field and a select before their first row, where Files
	spends 34px on a toolbar holding its action, its breadcrumb and three filters.
	This is that row, on the platform's own clickable-area grid.

	It exists as a component rather than as markup in each pane so the controls
	cannot drift apart again — which is exactly what happened to the two panes'
	titles, their degraded notices and their filters when each was written separately.

	The provenance line it replaces is not lost: the navigation footer already
	carried the same sentence, so every screen was printing it twice.
-->
<template>
	<div class="epi-bar">
		<div class="epi-bar__controls">
			<NcTextField class="epi-bar__find"
				:model-value="query"
				:label="t('epidemiologia', 'Buscar')"
				:show-trailing-button="!!query"
				trailing-button-icon="close"
				:trailing-button-label="t('epidemiologia', 'Borrar búsqueda')"
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
				<Procedencia v-if="fetchedAt" :fetched-at="fetchedAt" />
			</p>
		</div>

		<!--
			What is narrowing the list, and the way to undo it.

			A labelled group and NOT a second live region: the count above is the only one
			this app has, and making every keystroke announce a sentence naming four
			filters is worse than announcing a number. These are reachable, named and
			operable; they are not shouted.

			NcChip rather than our own pill, because a chip you dismiss is exactly what
			the platform component is for — it carries the close button, its label and its
			theming, so none of that is ours to get wrong.
		-->
		<div v-if="active.length"
			class="epi-bar__active"
			role="group"
			:aria-label="t('epidemiologia', 'Filtros activos')">
			<NcChip v-for="f in active"
				:key="f.id"
				:text="f.label"
				:aria-label-close="t('epidemiologia', 'Quitar el filtro {filtro}', { filtro: f.label })"
				@close="$emit('clear', f.id)" />
		</div>
	</div>
</template>

<script setup>
import NcChip from '@nextcloud/vue/components/NcChip'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Procedencia from './Procedencia.vue'

defineProps({
	query: { type: String, default: '' },
	/** Already-pluralised, because only the pane knows what it is counting. */
	count: { type: String, required: true },
	fetchedAt: { type: Number, default: null },
	/**
	 * What is currently narrowing the list: `[{ id, label }]`, already worded by the
	 * pane. The bar renders and dismisses them; it does not know what a family or a
	 * semana epidemiológica is, and should not have to.
	 */
	active: { type: Array, default: () => [] },
})

defineEmits(['update:query', 'clear'])
</script>

<style scoped>
/* Wraps rather than overflows: at 360px the search field alone is most of the
   width, so the filters drop to a second line instead of pushing the count off
   screen. The row is only one line tall where there is room for one. */
/*
	Sticky, because the filter state was unreachable the moment you scrolled. The
	ISP archive was 276 rows and ~18.500px tall; by row 40 nothing on screen said
	which year, month, search or category was narrowing it — and the empty state
	said "Ninguna alerta del ISP coincide con esos filtros" while the filters
	themselves had scrolled away.

	`top: 0` against `.app-content`, which is the scrolling ancestor: measured on
	the instance (overflow-y: auto, 710px of viewport over 18.498px of content),
	not assumed. Everything between it and this row is `overflow: visible`, so
	nothing traps the sticky box.

	The background is not decoration. A transparent sticky row lets the alert
	titles slide visibly through the search field, and it must be the token rather
	than white so it survives a stock Nextcloud dark theme.
*/
.epi-bar {
	position: sticky;
	top: 0;
	z-index: 2;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-block: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	/* The edge is load-bearing, not trim. Without it the row sliding underneath is
	   clipped mid-sentence hard against the search field, and a half-drawn line of
	   text reads as broken rendering rather than as content passing behind. */
	border-bottom: 1px solid var(--color-border);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

/* The old `.epi-bar` layout, unchanged: it wraps rather than overflows, so at 360px
   the filters drop to a second line instead of pushing the count off screen. */
.epi-bar__controls {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}

/* Wraps too. Four active filters at 360px are more than one line holds, and a token
   that scrolls sideways is a token nobody finds — the whole point is that what is
   applied is visible without hunting for it. */
.epi-bar__active {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
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
