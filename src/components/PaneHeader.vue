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

/*
	The one place this component states a size, and only below the breakpoint.

	Core sets h2 to 1.8em with a line-height of 1.5 and has no mobile rule to
	inherit — checked, not assumed. On a phone that is a body leading applied to
	display type: "Informe semanal de vigilancia ETI e IRAG" ran to THREE lines
	and 122px, so 26% of a 556px screen was spent before any content. Measured on
	the instance at a real 360px viewport.

	1.5em with 1.3 leading puts the longest title on two lines at 59px — 15%.
	Going smaller kept shrinking it (1.3em reaches 47px) and stopped buying
	anything a reader notices, so this is the least deviation from the platform
	that solves the problem rather than the smallest number that fits.
*/
@media (max-width: 1024px) {
	.epi-head h2 {
		font-size: 1.5em;
		line-height: 1.3;
	}
}
</style>
