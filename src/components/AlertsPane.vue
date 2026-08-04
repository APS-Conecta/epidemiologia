<template>
	<section>
		<!--
			The heading is mobile-only. On a desktop the navigation already says which
			pane you are in, and Files and Maps print no heading in their content at all
			— measured on the instance. On a phone the navigation is a closed drawer, so
			it is the only thing naming the pane and it stays.
		-->
		<PaneHeader class="epi-head--mobile"
			:title="t('epidemiologia', 'Alertas epidemiológicas vigentes')" />

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

		<NcEmptyContent v-else
			:name="t('epidemiologia', 'Sin coincidencias')"
			:description="t('epidemiologia', 'Ninguna alerta vigente coincide con esa búsqueda.')">
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
import DegradedNotice from './DegradedNotice.vue'
import { alerts, fold, mes, tally } from '../state.js'

const query = ref('')
const year = ref(null)
const month = ref(null)

/** Upload year, not the oficio's year — only 28 of 75 titles carry one at all. */
const years = tally(alerts.items, (a) => a.year).sort((a, b) => b.id.localeCompare(a.id))

/**
 * Months of the chosen year. Offering a month the selected year does not have
 * would let a reader build a pair of filters that can only ever return nothing.
 */
const months = computed(() => tally(
	year.value ? alerts.items.filter((a) => a.year === year.value) : alerts.items,
	(a) => a.month,
).sort((a, b) => b.id.localeCompare(a.id)).map((m) => ({ ...m, label: mes(m.id) })))

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

/* Above the breakpoint the navigation names the pane, so the heading is dead
   weight — 41px of it, plus its provenance line. Below, the navigation is a
   drawer and this is the only label there is. */
.epi-head--mobile {
	display: none;
}

@media (max-width: 1024px) {
	.epi-head--mobile {
		display: block;
	}
}
</style>
