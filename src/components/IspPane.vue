<!--
	Sanitary alerts from the ISP: market withdrawals, falsifications,
	pharmacovigilance, device recalls.

	Deliberately NOT merged with the MINSAL alert list. A MINSAL oficio and an ISP
	product recall are different kinds of object — different issuer, different
	action, different audience — and a clinician reading one list would have to
	re-derive which is which on every row.
-->
<template>
	<section>
		<PaneHeader :title="t('epidemiologia', 'Alertas sanitarias del ISP')"
			:fetched-at="ispAlerts.fetched_at">
			{{ t('epidemiologia', 'Fuente: Instituto de Salud Pública de Chile') }}
			<template v-if="ispAlerts.fetched_at">
				· {{ t('epidemiologia', 'actualizado') }}
				<NcDateTime :timestamp="ispAlerts.fetched_at * 1000" relative-time="long" />
			</template>
		</PaneHeader>

		<NcNoteCard v-if="ispAlerts.degraded" type="warning">
			<strong>{{ t('epidemiologia', 'Problemas técnicos.') }}</strong>
			{{ t('epidemiologia', 'No hemos podido contactar al ISP, así que esta lista puede estar incompleta.') }}
			<a href="https://www.ispch.gob.cl/categorias-alertas/anamed/"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('epidemiologia', 'Ver en ispch.gob.cl') }} ↗
			</a>
		</NcNoteCard>

		<div class="epi-filters">
			<NcTextField v-model="query"
				type="search"
				:label="t('epidemiologia', 'Buscar por título')"
				:placeholder="t('epidemiologia', 'amoxicilina, falsificado, dispositivo…')" />
		</div>

		<p class="epi-count">{{ countLabel }}</p>

		<ul v-if="shown.length" class="epi-list">
			<NcListItem v-for="alert in shown"
				:key="alert.url"
				one-line
				:name="alert.title"
				:href="alert.url"
				target="_blank"
				:details="alert.date" />
		</ul>

		<NcEmptyContent v-else
			:name="ispAlerts.items.length ? t('epidemiologia', 'Sin coincidencias') : t('epidemiologia', 'Sin alertas')"
			:description="ispAlerts.items.length
				? t('epidemiologia', 'Ninguna alerta del ISP coincide con esa búsqueda.')
				: t('epidemiologia', 'El ISP no tiene alertas publicadas en sus categorías.')">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { NcDateTime, NcEmptyContent, NcListItem, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import PaneHeader from './PaneHeader.vue'
import { ispAlerts, fold } from '../state.js'

const query = ref('')

// Folded once, not per keystroke — same reason as the MINSAL list.
const folded = ispAlerts.items.map((a) => ({ ...a, _fold: fold(a.title) }))

const shown = computed(() => {
	const needle = fold(query.value.trim())

	return folded.filter((a) => !needle || a._fold.includes(needle))
})

const countLabel = computed(() => {
	const total = ispAlerts.items.length
	if (shown.value.length === total) {
		return n('epidemiologia', '%n alerta del ISP', '%n alertas del ISP', total)
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
}
</style>
