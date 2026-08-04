<!--
	The pane's title, and nothing else.

	It used to carry a provenance line too — "Fuente: Departamento de Epidemiología,
	MINSAL · actualizado hace 3 minutos" — under every heading on every pane. The
	navigation footer already says who publishes this, so that sentence was on
	screen twice; and the freshness half now lives in the toolbar, beside the count,
	where it sits on one row instead of taking its own.

	Each pane decides when to show this: above the mobile breakpoint the navigation
	names the pane and Files prints no heading at all.
-->
<template>
	<header class="epi-head">
		<h2>{{ title }}</h2>
	</header>
</template>

<script setup>
defineProps({
	title: { type: String, required: true },
})
</script>

<style scoped>
.epi-head {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.epi-head h2 {
	margin: 0;
}

/*
	Core sets h2 to 1.8em with a line-height of 1.5 and has no mobile rule to
	inherit — checked, not assumed. On a phone that applies a body leading to
	display type: the longest title ran to THREE lines and 122px, 26% of a 556px
	screen spent before any content. 1.5em with 1.3 puts it on two lines at 59px.
*/
@media (max-width: 1024px) {
	.epi-head h2 {
		font-size: 1.5em;
		line-height: 1.3;
	}
}
</style>
