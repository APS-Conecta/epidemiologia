<template>
	<NcContent app-name="epidemiologia">
		<NcAppNavigation>
			<NcAppNavigationList>
				<NcAppNavigationItem v-for="pane in panes"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
					<template v-if="pane.count !== undefined" #counter>
						<!-- raw: NcCounterBubble humanises by default, so 1042 would render
						     as "1 K". Harmless at 75, wrong the moment it is not. -->
						<NcCounterBubble :count="pane.count" type="highlighted" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>
		</NcAppNavigation>

		<NcAppContent :page-heading="t('epidemiologia', 'Epidemiología')">
			<div class="epi">
				<!--
					v-show, never v-if. The tablero pane holds an iframe whose Power BI
					report takes 15–20 s to paint; unmounting it on every tab switch would
					re-pay that each time. This is the single easiest thing to get wrong here.
				-->
				<AlertsPane v-show="current === 'alertas'" />
				<ReportPane v-show="current === 'informe'" />
				<TableroPane v-show="current === 'tablero'" :mounted="tableroSeen" />
				<EpivigilaPane v-show="current === 'epivigila'" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { markRaw, ref, watch } from 'vue'
import {
	NcAppContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationList,
	NcContent,
	NcCounterBubble,
} from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import Alert from 'vue-material-design-icons/Alert.vue'
import ChartBellCurve from 'vue-material-design-icons/ChartBellCurve.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import AlertsPane from './components/AlertsPane.vue'
import ReportPane from './components/ReportPane.vue'
import TableroPane from './components/TableroPane.vue'
import EpivigilaPane from './components/EpivigilaPane.vue'
import { alerts } from './state.js'

const current = ref('alertas')

// Latches on first open and never resets, so the iframe mounts exactly once.
const tableroSeen = ref(false)
watch(current, (pane) => {
	if (pane === 'tablero') {
		tableroSeen.value = true
	}
})

const panes = [
	{ id: 'alertas', name: t('epidemiologia', 'Alertas vigentes'), icon: markRaw(Alert), count: alerts.items.length },
	{ id: 'informe', name: t('epidemiologia', 'Informe semanal'), icon: markRaw(ChartBellCurve) },
	{ id: 'tablero', name: t('epidemiologia', 'Tablero MINSAL'), icon: markRaw(ChartBox) },
	{ id: 'epivigila', name: t('epidemiologia', 'EPIVIGILA'), icon: markRaw(ShieldAccount) },
]
</script>

<style scoped>
/*
	No font sizes and no raw pixels. core/css/styles.scss already sets body to
	--default-font-size with --default-line-height, and apps.scss sets h2 to 1.8em;
	the old stylesheet fought both, which is why the app looked wrong beside
	official ones. Everything here is a token or a multiple of the grid baseline.
*/
.epi {
	max-width: 1100px;
	padding: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 8)
		calc(var(--default-grid-baseline) * 10);
}
</style>
