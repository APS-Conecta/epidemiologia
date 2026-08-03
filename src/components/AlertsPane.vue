<template>
	<section>
		<PaneHeader :title="t('epidemiologia', 'Alertas epidemiológicas vigentes')"
			:fetched-at="alerts.fetched_at" />

		<DegradedNotice v-if="alerts.degraded" source="MINSAL" url="https://epi.minsal.cl/" />

		<div class="epi-filters">
			<NcTextField v-model="query"
				type="search"
				:label="t('epidemiologia', 'Buscar por título')"
				:placeholder="t('epidemiologia', 'sarampión, hantavirus, coqueluche…')" />
			<NcSelect v-model="year"
				class="epi-filters__year"
				:options="years"
				:input-label="t('epidemiologia', 'Año')"
				:placeholder="t('epidemiologia', 'Todos los años')" />
		</div>

		<p class="epi-count">{{ countLabel }}</p>

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
import { computed, ref } from 'vue'
import { NcEmptyContent, NcSelect, NcTextField } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import AlertRow from './AlertRow.vue'
import PaneHeader from './PaneHeader.vue'
import DegradedNotice from './DegradedNotice.vue'
import { alerts, fold, mes } from '../state.js'

const query = ref('')
const year = ref(null)

/** Upload year, not the oficio's year — only 28 of 75 titles carry one at all. */
const years = [...new Set(alerts.items.map((a) => a.year))].sort().reverse()

// Folded once, not per keystroke: 75 rows x every character typed adds up.
const folded = alerts.items.map((a) => ({ ...a, _fold: fold(a.title) }))

const shown = computed(() => {
	const needle = fold(query.value.trim())
	return folded.filter((a) => (!needle || a._fold.includes(needle))
		&& (!year.value || a.year === year.value))
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
.epi-filters {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
	flex-wrap: wrap;
	align-items: flex-end;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.epi-filters :deep(.input-field) {
	flex: 1;
	min-width: 240px;
}

.epi-filters__year {
	min-width: 200px;
}

.epi-count {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	font-variant-numeric: tabular-nums;
}

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
