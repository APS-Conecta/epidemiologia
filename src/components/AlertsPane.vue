<template>
	<section>
		<PaneHeader :title="paneName('alertas')" />

		<DegradedNotice v-if="alerts.degraded" source="MINSAL" url="https://epi.minsal.cl/" />

		<FilterBar :query="query"
			:count="countLabel"
			:fetched-at="alerts.fetched_at"
			@update:query="query = $event">
			<FilterMenu :label="t('epidemiologia', 'Año')"
				radio-name="alertas-year"
				:any-label="t('epidemiologia', 'Todos los años')"
				:options="years"
				:model-value="year"
				@update:model-value="year = $event">
				<template #icon>
					<CalendarRange :size="18" />
				</template>
			</FilterMenu>
			<FilterMenu :label="t('epidemiologia', 'Mes')"
				radio-name="alertas-month"
				:any-label="t('epidemiologia', 'Todos los meses')"
				:options="months"
				:model-value="month"
				@update:model-value="month = $event">
				<template #icon>
					<CalendarMonthOutline :size="18" />
				</template>
			</FilterMenu>
		</FilterBar>

		<!-- 75 rows is ~600 DOM nodes, rendered once from initial state with no
		     images. Virtualising that would be a liability, not an optimisation. -->
		<ul v-if="shown.length" class="epi-list">
			<AlertRow v-for="alert in shown"
				:key="alert.url"
				:title="alert.title"
				:url="alert.url">
				<template #meta>
					<span class="epi-alert__when">{{ mes(alert.month) }}</span>
				</template>
			</AlertRow>
		</ul>

		<!--
			Two different emptinesses, and telling them apart is the whole point. With
			MINSAL degraded or genuinely empty this pane said "nothing matched your
			search" to a reader who had not searched — blaming them for an outage.
			IspPane branched on this from the start; this one did not, which is what
			two panes drifting apart looks like.
		-->
		<NcEmptyContent v-else
			:name="alerts.items.length ? t('epidemiologia', 'Sin coincidencias') : t('epidemiologia', 'Sin alertas')"
			:description="alerts.items.length
				? t('epidemiologia', 'Ninguna alerta vigente coincide con esa búsqueda.')
				: emptyText('MINSAL')">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { NcEmptyContent } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import AlertRow from './AlertRow.vue'
import FilterBar from './FilterBar.vue'
import FilterMenu from './FilterMenu.vue'
import PaneHeader from './PaneHeader.vue'
import { paneName } from '../panes.js'
import DegradedNotice from './DegradedNotice.vue'
import { alerts, emptyText, fold, mes, monthOptions, yearOptions } from '../state.js'

const query = ref('')
const year = ref(null)
const month = ref(null)

// Upload year, not the oficio's year — only 28 of 75 titles carry one at all.
// Both menus come from state.js, which is what stops this pane and the ISP's
// building the same control two ways again (#44).
const yearOf = (a) => a.year
const monthOf = (a) => a.month

const years = yearOptions(alerts.items, yearOf)
const months = computed(() => monthOptions(alerts.items, yearOf, monthOf, year.value))

// Changing the year can strand a month that year does not have, which would show
// zero results and read as a fault in the data rather than in the filter pair.
watch(year, () => {
	if (month.value && !months.value.some((m) => m.id === month.value)) {
		month.value = null
	}
})

// Folded once, not per keystroke: 75 rows x every character typed adds up.
const folded = alerts.items.map((a) => ({ ...a, _fold: fold(a.title) }))

const shown = computed(() => {
	const needle = fold(query.value.trim())
	return folded.filter((a) => (!needle || a._fold.includes(needle))
		&& (!year.value || a.year === year.value)
		&& (!month.value || a.month === month.value))
})

const countLabel = computed(() => {
	const total = alerts.items.length
	if (shown.value.length === total) {
		return n('epidemiologia', '%n alerta vigente', '%n alertas vigentes', total)
	}
	return t('epidemiologia', '{shown} de {total} alertas', { shown: shown.value.length, total })
})
</script>

<style scoped>
.epi-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.epi-alert__when {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	font-variant-numeric: tabular-nums;
}

</style>
