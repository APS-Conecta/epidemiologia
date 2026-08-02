<!--
	Heading plus provenance line, shared by all four panes.

	No font-size anywhere: core's apps.scss already sets h2 to 1.8em, and body to
	--default-font-size with --default-line-height. Stating our own sizes is what
	made the old page look wrong next to official apps.
-->
<template>
	<header class="epi-head">
		<h2>{{ title }}</h2>
		<p class="epi-head__sub">
			<slot>
				{{ t('epidemiologia', 'Fuente: Departamento de Epidemiología, MINSAL') }}
				<template v-if="fetchedAt">
					· {{ t('epidemiologia', 'actualizado') }}
					<!-- NcDateTime rather than a string rendered by PHP: it stays correct on
					     a page left open for hours, which a server-rendered "hace 3 h" cannot. -->
					<NcDateTime :timestamp="fetchedAt * 1000" :relative-time="'long'" />
				</template>
			</slot>
		</p>
	</header>
</template>

<script setup>
import { NcDateTime } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

defineProps({
	title: { type: String, required: true },
	fetchedAt: { type: Number, default: null },
})
</script>

<style scoped>
.epi-head {
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.epi-head h2 {
	margin: 0;
}

.epi-head__sub {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
