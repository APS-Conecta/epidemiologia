<!--
	The last thing between a render error and a blank page.

	App.vue shows its panes with `v-show`, never `v-if` — the tablero's iframe must
	mount once — so all six are mounted on first paint and stay mounted. That makes
	the blast radius of a throwing pane the whole app rather than one tab: an error
	while drawing the ISP rail takes Alertas vigentes and the informe down with it,
	on load, before a reader has chosen anything. Vue's default for an unhandled
	render error is a page with nothing on it, which looks exactly like the instance
	being broken.

	ONE boundary, around the content area, and not one per pane. Six would buy
	nothing the one does not: with every pane mounted together there is no surviving
	sibling to keep showing, so the finer granularity would only be more code making
	the same promise.

	It does NOT stop propagation. `onErrorCaptured` returning `false` would keep the
	error from ever reaching Vue's own handler, so the fallback below would render
	and the console would stay clean — the app would look handled and be
	undiagnosable, which is a worse state than the crash it replaces. Returning
	nothing renders the fallback AND lets the error travel, so it is still reported
	where whoever is debugging will look.

	Not an *estado degradado*, and deliberately worded away from one. CONTEXT.md
	reserves that term for "a fetch returned zero items and we are serving the last
	good copy" — a state about the fuente, which DegradedNotice already says. This is
	a fault in our own rendering, and reusing that sentence would tell a clinician
	MINSAL is unreachable when MINSAL is fine.
-->
<template>
	<NcEmptyContent v-if="error"
		:name="t('epidemiologia', 'No pudimos mostrar esta sección')"
		:description="t('epidemiologia', 'Algo falló al dibujar esta página. Recargar suele resolverlo.')">
		<template #icon>
			<AlertCircleOutline :size="20" />
		</template>
	</NcEmptyContent>
	<slot v-else />
</template>

<script setup>
import { onErrorCaptured, ref } from 'vue'
import { NcEmptyContent } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

const error = ref(null)

// No `return false`: see the docblock. The fallback renders either way; what the
// return value decides is whether anyone ever finds out why.
onErrorCaptured((err) => {
	error.value = err
})
</script>
