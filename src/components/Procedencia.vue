<!--
	The "leído + NcDateTime" provenance fragment, once (L3-22).

	Rendered three ways — ReportPane's count line, FilterBar's live region and
	Inicio's freshness badge were line-for-line copies or quiet variants, the
	exact drift pattern degradedText's own history records. This renders the
	fragment alone: "leído <NcDateTime…>"; the caller places it after its own
	text and owns whether there is anything to show.
-->
<template>
	<template v-if="fetchedAt">
		· {{ t('epidemiologia', 'leído') }}
		<NcDateTime :timestamp="fetchedAt * 1000" relative-time="long" />
	</template>
</template>

<script setup>
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import { t } from '@nextcloud/l10n'

defineProps({
	/** Epoch seconds, the wire's unit — the component multiplies once, here. */
	fetchedAt: { type: Number, default: null },
})
</script>
