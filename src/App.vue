<template>
	<NcContent app-name="epidemiologia">
		<!--
			aria-label, because the library warns without it and Files sets one
			("Archivos"). Measured on the instance in #26: ours was null, which is
			the one unambiguous defect that ticket found.
		-->
		<NcAppNavigation :aria-label="t('epidemiologia', 'Epidemiología')">
			<!--
				The DEFAULT slot, not `#list`, and that is forced rather than chosen.
				`#list` wraps its content in one NcAppNavigationList — a single <ul> —
				so several captioned lists inside it produce `ul > ul`, which is invalid
				and destroys list semantics. Measured on the instance before this was
				fixed; it is the same class of defect #14 closed. `#list` is for one flat
				list. Files uses the default slot too (#26).

				Each caption is the first <li> of the list it labels, because a caption
				without `is-heading` renders an <li> and an <li> loose in the body <div>
				is equally invalid. It labels its own list through aria-labelledby.

				Not `is-heading`: that renders h{max(2, level)} and the navigation
				precedes the content, so the captions would sit at level 2 immediately
				above the content's level 1 — the level-skip audit tools flag. ADR-0005
				records the cost: a screen-reader user loses H-key jumping between groups.
			-->
			<NcAppNavigationList :aria-label="t('epidemiologia', 'Vistas')">
				<NcAppNavigationItem v-for="pane in panes"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<NcAppNavigationList v-for="group in groups"
				:key="group.id"
				:aria-labelledby="`epi-nav-${group.id}`">
				<NcAppNavigationCaption :name="group.name" :heading-id="`epi-nav-${group.id}`" />
				<NcAppNavigationItem v-for="pane in group.panes"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
					<!-- raw: NcCounterBubble humanises by default, so 1042 would render
					     as "1 K". Only shown where the number is actionable. -->
					<template v-if="pane.count !== undefined" #counter>
						<NcCounterBubble :count="pane.count" type="highlighted" raw />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<!--
				The footer is what gives the column a bottom. #26 measured four items
				totalling 164px floating in a 562px column, against Files anchoring its
				own with a search field above and settings below — that emptiness, not
				the slot, is what read as "not a Nextcloud app". It carries the source
				attribution, which ADR-0004 says we owe anyway now that we build on
				state data published under no licence.
			-->
			<template #footer>
				<footer class="epi-nav-footer">
					<span>{{ t('epidemiologia', 'Fuente: Departamento de Epidemiología, MINSAL') }}</span>
					<a href="https://epi.minsal.cl/" target="_blank" rel="noopener noreferrer">
						epi.minsal.cl ↗
					</a>
				</footer>
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="t('epidemiologia', 'Epidemiología')">
			<div class="epi">
				<!--
					v-show, never v-if. The tablero pane holds an iframe whose Power BI
					report takes 15–20 s to paint; unmounting it on every tab switch would
					re-pay that each time. This is the single easiest thing to get wrong here.
				-->
				<InicioPane v-show="current === 'inicio'" @open="current = $event" />
				<AlertsPane v-show="current === 'alertas'" />
				<IspPane v-show="current === 'isp'" />
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
	NcAppNavigationCaption,
	NcAppNavigationItem,
	NcAppNavigationList,
	NcContent,
	NcCounterBubble,
} from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import Alert from 'vue-material-design-icons/Alert.vue'
import ChartBellCurve from 'vue-material-design-icons/ChartBellCurve.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import Home from 'vue-material-design-icons/Home.vue'
import Pill from 'vue-material-design-icons/Pill.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import InicioPane from './components/InicioPane.vue'
import AlertsPane from './components/AlertsPane.vue'
import ReportPane from './components/ReportPane.vue'
import TableroPane from './components/TableroPane.vue'
import IspPane from './components/IspPane.vue'
import EpivigilaPane from './components/EpivigilaPane.vue'
import { ispAlerts } from './state.js'

const current = ref('inicio')

// Latches on first open and never resets, so the iframe mounts exactly once.
const tableroSeen = ref(false)
watch(current, (pane) => {
	if (pane === 'tablero') {
		tableroSeen.value = true
	}
})

/**
 * Inicio first and ungrouped; the rest grouped by publisher.
 *
 * No counter on "Alertas vigentes": 75 never changes between MINSAL republications,
 * and Nextcloud uses counters for what needs action. A permanent number is decoration
 * that trains people to ignore the one place a real count will appear.
 *
 * The ISP counter IS shown, unlike MINSAL's: it counts what arrived, and what
 * arrived is what needs looking at.
 */
const panes = [
	{ id: 'inicio', name: t('epidemiologia', 'Inicio'), icon: markRaw(Home) },
]

const groups = [
	{
		id: 'minsal',
		name: t('epidemiologia', 'MINSAL'),
		panes: [
			{ id: 'alertas', name: t('epidemiologia', 'Alertas vigentes'), icon: markRaw(Alert) },
			{ id: 'informe', name: t('epidemiologia', 'Informe semanal'), icon: markRaw(ChartBellCurve) },
			{ id: 'tablero', name: t('epidemiologia', 'Tablero MINSAL'), icon: markRaw(ChartBox) },
		],
	},
	{
		id: 'isp',
		name: t('epidemiologia', 'ISP'),
		panes: [
			{ id: 'isp', name: t('epidemiologia', 'Alertas sanitarias'), icon: markRaw(Pill), count: ispAlerts.items.length },
		],
	},
	{
		id: 'notificacion',
		name: t('epidemiologia', 'Notificación'),
		panes: [
			{ id: 'epivigila', name: t('epidemiologia', 'EPIVIGILA'), icon: markRaw(ShieldAccount) },
		],
	},
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

/* Inicio fills the pane instead of scrolling it, so its grid can size to the
   viewport. The other panes keep their own scroll. */
.epi:has(> .epi-inicio:not([style*="display: none"])) {
	display: flex;
	flex-direction: column;
	height: 100%;
	max-width: none;
	padding-bottom: calc(var(--default-grid-baseline) * 6);
}

.epi-nav-footer {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) / 2);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
