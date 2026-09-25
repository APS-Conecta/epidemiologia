<template>
	<section class="epi-tablero">
		<!--
			No subtitle. It said three things: that the tablero is official (the
			navigation group already says MINSAL), that it takes seconds to load (the
			iframe shows that itself), and that the region is chosen inside it, which
			is where a reader looks anyway.
		-->
		<PaneHeader :title="paneName('tablero')" />

		<!--
			v-if on the iframe, never on the pane: the pane wrapper is v-show in App.vue
			precisely so this element survives a tab switch. With v-if on the wrapper the
			iframe unmounts every time and re-pays Power BI's 15–20 s paint.

			referrerpolicy: without it the full intranet URL goes to Microsoft on every
			load. The CSP frame domain lives in PageController.
		-->
		<iframe v-if="mounted && tablero"
			class="epi-frame"
			:src="tablero"
			:title="t('epidemiologia', 'Situación Epidemiológica Vigilancia ETI e IRAG')"
			loading="lazy"
			referrerpolicy="no-referrer"
			allowfullscreen />

		<!-- The URL never reached the page (L3-15): a blank iframe "looks to
		     everyone like MINSAL being down" — the exact sentence smoke.sh's
		     CSP check was written against. Named, not blank. -->
		<NcEmptyContent v-else-if="mounted"
			:name="t('epidemiologia', 'Tablero no disponible')"
			:description="t('epidemiologia', 'La dirección del tablero no llegó a la página. Recargar suele resolverlo.')">
			<template #icon>
				<ChartBox :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import PaneHeader from './PaneHeader.vue'
import { paneName } from '../panes.js'
import { tablero } from '../state.js'

// Latches true on first open and never resets, so the report paints exactly once.
defineProps({
	mounted: { type: Boolean, default: false },
})
</script>

<style scoped>
.epi-frame {
	width: 100%;
	height: 72vh;
	min-height: 420px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-main-background);
}
</style>
