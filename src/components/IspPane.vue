<!--
	Sanitary alerts from the ISP: market withdrawals, falsifications,
	pharmacovigilance, device recalls.

	Deliberately NOT merged with the MINSAL alert list. A MINSAL oficio and an ISP
	product recall are different kinds of object — different issuer, different
	action, different audience — and a clinician reading one list would have to
	re-derive which is which on every row.

	Titles WRAP here rather than truncate, which is the opposite of the Inicio
	card and deliberate. ISP titles put the product at the END
	("ALERTA DE RETIRO DEL MERCADO N° 22/26 DEL PRODUCTO FARMACÉUTICO TELLMI AM
	80/5 comprimidos DE Laboratorios Saval S.A"), so cutting the tail removes
	exactly the half a pharmacist needs. This is an archive pane with its own
	scroll and no height budget to protect (#35).
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
			<NcSelect v-model="kind"
				class="epi-filters__select"
				:options="kinds"
				label="label"
				:input-label="t('epidemiologia', 'Tipo de alerta')"
				:placeholder="t('epidemiologia', 'Todos los tipos')" />
			<NcSelect v-model="month"
				class="epi-filters__select"
				:options="months"
				label="label"
				:input-label="t('epidemiologia', 'Publicado')"
				:placeholder="t('epidemiologia', 'Cualquier fecha')" />
		</div>

		<p class="epi-count">
			{{ countLabel }}
			<NcButton v-if="filtered" variant="tertiary" @click="clear">
				{{ t('epidemiologia', 'Quitar filtros') }}
			</NcButton>
		</p>

		<ul v-if="shown.length" class="epi-list">
			<li v-for="alert in shown" :key="alert.url" class="epi-alert">
				<a class="epi-alert__title" :href="alert.url" target="_blank" rel="noopener noreferrer">
					{{ alert.title }}
				</a>
				<p class="epi-alert__meta">
					<span class="epi-alert__date">{{ alert.date }}</span>
					<!-- Every category that carried it, not just the first: an alert
					     genuinely belongs to all of them. -->
					<NcChip v-for="category in alert.categories"
						:key="category"
						:text="kindLabel(category)"
						no-close
						@click="kind = kinds.find((k) => k.id === category) ?? null" />
				</p>
			</li>
		</ul>

		<NcEmptyContent v-else
			:name="ispAlerts.items.length ? t('epidemiologia', 'Sin coincidencias') : t('epidemiologia', 'Sin alertas')"
			:description="ispAlerts.items.length
				? t('epidemiologia', 'Ninguna alerta del ISP coincide con esos filtros.')
				: t('epidemiologia', 'Sus categorías no traen alertas en este momento.')">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { NcButton, NcChip, NcDateTime, NcEmptyContent, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { t, n, getCanonicalLocale } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import PaneHeader from './PaneHeader.vue'
import { ispAlerts, fold } from '../state.js'

/**
 * The ISP's category slugs, in Spanish and with their accents.
 *
 * Only the ones a naive prettify gets wrong are listed — which is most of them,
 * because the slugs are unaccented. Anything absent falls through to `prettify`,
 * so a category the ISP adds tomorrow renders sanely instead of blank.
 */
const KIND_LABELS = {
	anamed: 'ANAMED',
	farmacovigilancia: 'Farmacovigilancia',
	'retiro-de-mercado': 'Retiro de mercado',
	'alerta-falsificado-producto-sin-registro': 'Falsificado o sin registro',
	'producto-sin-registro': 'Producto sin registro',
	'dispositivos-medicos': 'Dispositivos médicos',
	'retiro-del-mercado-dispositivo-medico': 'Retiro — dispositivo médico',
	'dispositivo-medico-falsificado': 'Dispositivo médico falsificado',
	'cese-de-utilizacion-dp': 'Cese de utilización',
	'nota-informativa-dp': 'Nota informativa — dispositivos',
	'alertas-cosmeticas': 'Cosméticos',
	'nota-informativa-cosmetovigilancia': 'Cosmetovigilancia',
	'vigilancia-de-cosmeticos': 'Vigilancia de cosméticos',
	'desinfectantes-y-sanitizantes': 'Desinfectantes y sanitizantes',
	'falsificados-sin-registro-sanitario-desinfectantes': 'Desinfectantes sin registro',
	'advertencia-de-seguridad': 'Advertencia de seguridad',
	'informacion-de-seguridad': 'Información de seguridad',
	'informe-tecnico': 'Informe técnico',
	'nota-informativa': 'Nota informativa',
	'actualizacion-de-seguridad': 'Actualización de seguridad',
	'cese-de-distribucion': 'Cese de distribución',
	'actualizacion-de-software': 'Actualización de software',
	'robo-de-dispositivos-medicos': 'Robo de dispositivos médicos',
	'robo-hurto': 'Robo o hurto',
}

const prettify = (slug) => {
	const words = slug.replace(/-/g, ' ')

	return words.charAt(0).toUpperCase() + words.slice(1)
}

const kindLabel = (slug) => KIND_LABELS[slug] ?? prettify(slug)

const query = ref('')
const kind = ref(null)
const month = ref(null)

// Folded once, not per keystroke — same reason as the MINSAL list.
const folded = ispAlerts.items.map((a) => ({ ...a, _fold: fold(a.title) }))

/** Categories present in the data, with how many alerts each carries. */
const kinds = computed(() => {
	const counts = new Map()
	for (const alert of ispAlerts.items) {
		for (const category of alert.categories ?? []) {
			counts.set(category, (counts.get(category) ?? 0) + 1)
		}
	}

	return [...counts.entries()]
		.map(([id, count]) => ({ id, count, label: `${kindLabel(id)} (${count})` }))
		.sort((a, b) => a.label.localeCompare(b.label, getCanonicalLocale()))
})

/** Months present in the data, newest first. The ISP publishes a real day. */
const months = computed(() => {
	const seen = new Map()
	for (const alert of ispAlerts.items) {
		const key = (alert.date ?? '').slice(0, 7)
		if (key) {
			seen.set(key, (seen.get(key) ?? 0) + 1)
		}
	}

	return [...seen.entries()]
		.sort((a, b) => b[0].localeCompare(a[0]))
		.map(([id, count]) => ({ id, count, label: `${monthName(id)} (${count})` }))
})

function monthName(key) {
	const [year, m] = key.split('-')
	const name = new Date(Number(year), Number(m) - 1, 1)
		.toLocaleDateString(getCanonicalLocale(), { month: 'long' })

	return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${year}`
}

const filtered = computed(() => query.value.trim() !== '' || kind.value !== null || month.value !== null)

function clear() {
	query.value = ''
	kind.value = null
	month.value = null
}

const shown = computed(() => {
	const needle = fold(query.value.trim())

	return folded.filter((alert) => (!needle || alert._fold.includes(needle))
		&& (!kind.value || (alert.categories ?? []).includes(kind.value.id))
		&& (!month.value || (alert.date ?? '').startsWith(month.value.id)))
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

.epi-filters__select {
	min-width: 220px;
}

.epi-count {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
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

.epi-alert {
	padding: calc(var(--default-grid-baseline) * 2.5) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.epi-alert:hover {
	background: var(--color-background-hover);
}

/* Wraps. The product name is at the end of an ISP title, so truncating loses
   the half that identifies what was recalled. */
.epi-alert__title {
	color: var(--color-main-text);
	text-decoration: none;
	font-weight: var(--font-weight-heading);
}

.epi-alert__title:hover,
.epi-alert__title:focus-visible {
	text-decoration: underline;
}

.epi-alert__meta {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
	margin: calc(var(--default-grid-baseline) * 1.5) 0 0;
}

.epi-alert__date {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}
</style>
