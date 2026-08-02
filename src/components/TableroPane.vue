<template>
	<section class="epi-tablero">
		<PaneHeader :title="t('epidemiologia', 'Tablero MINSAL · ETI e IRAG')">
			{{ t('epidemiologia', 'El tablero oficial, incrustado. Lo mantiene MINSAL, no nosotros · tarda unos segundos en cargar · Total País y cada región se eligen dentro del tablero.') }}
		</PaneHeader>

		<!--
			v-if on the iframe, never on the pane: the pane wrapper is v-show in App.vue
			precisely so this element survives a tab switch. With v-if on the wrapper the
			iframe unmounts every time and re-pays Power BI's 15–20 s paint.

			referrerpolicy: without it the full intranet URL goes to Microsoft on every
			load. The CSP frame domain lives in PageController.
		-->
		<iframe v-if="mounted"
			class="epi-frame"
			:src="tablero"
			:title="t('epidemiologia', 'Situación Epidemiológica Vigilancia ETI e IRAG')"
			loading="lazy"
			referrerpolicy="no-referrer"
			allowfullscreen />
	</section>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import PaneHeader from './PaneHeader.vue'
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
