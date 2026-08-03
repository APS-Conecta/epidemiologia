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
			:fetched-at="sanitaryAlerts.fetched_at">
			{{ t('epidemiologia', 'Fuente: Instituto de Salud Pública de Chile') }}
			<template v-if="sanitaryAlerts.fetched_at">
				· {{ t('epidemiologia', 'actualizado') }}
				<NcDateTime :timestamp="sanitaryAlerts.fetched_at * 1000" relative-time="long" />
			</template>
		</PaneHeader>

		<NcNoteCard v-if="sanitaryAlerts.degraded" type="warning">
			<strong>{{ t('epidemiologia', 'Problemas técnicos.') }}</strong>
			{{ t('epidemiologia', 'No hemos podido contactar al ISP, así que esta lista puede estar incompleta.') }}
			<a href="https://www.ispch.gob.cl/categorias-alertas/anamed/"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('epidemiologia', 'Ver en ispch.gob.cl') }} ↗
			</a>
		</NcNoteCard>

		<!--
			Two controls by default, like the MINSAL pane. The library gives NcTextField
			a 240px minimum and NcSelect a 260px one, so five of them demand 1280px and
			overflowed a ~1000px pane sideways. Those minimums are the components'
			designed usable width, so the fix is fewer controls, not narrower ones.
		-->
		<div class="epi-filters">
			<NcTextField v-model="query"
				type="search"
				:label="t('epidemiologia', 'Buscar')"
				:placeholder="t('epidemiologia', 'amoxicilina, dispositivo, falsificado…')" />
			<NcSelect v-model="kind"
				:options="kinds"
				label="label"
				:input-label="t('epidemiologia', 'Tipo de alerta')"
				:placeholder="t('epidemiologia', 'Todos los tipos')" />
			<NcButton variant="tertiary" @click="more = !more">
				<template #icon>
					<Tune :size="20" />
				</template>
				{{ more ? t('epidemiologia', 'Menos filtros') : t('epidemiologia', 'Más filtros') }}
				<!-- A hidden filter that is ON must never be invisible, or the list looks
				     wrong for no reason a reader can see. -->
				<template v-if="!more && hiddenActive">&nbsp;({{ hiddenActive }})</template>
			</NcButton>
		</div>

		<div v-if="more" class="epi-filters">
			<NcSelect v-model="year"
				:options="years"
				label="label"
				:input-label="t('epidemiologia', 'Año')"
				:placeholder="t('epidemiologia', 'Todos')" />
			<NcSelect v-model="month"
				:options="months"
				label="label"
				:input-label="t('epidemiologia', 'Mes')"
				:placeholder="t('epidemiologia', 'Todos')" />
			<NcSelect v-model="sort"
				:options="SORTS"
				label="label"
				:clearable="false"
				:input-label="t('epidemiologia', 'Ordenar por')" />
		</div>

		<p class="epi-count">
			{{ countLabel }}
			<NcButton v-if="filtered" variant="tertiary" @click="clear">
				{{ t('epidemiologia', 'Quitar filtros') }}
			</NcButton>
		</p>

		<ul v-if="shown.length" class="epi-list">
			<AlertRow v-for="alert in shown"
				:key="alert.url"
				:title="alert.title"
				:url="alert.url">
				<template #meta>
					<span class="epi-alert__date">{{ alert.date }}</span>
					<!-- Every category that carried it, not just the first: an alert
					     genuinely belongs to all of them. -->
					<NcChip v-for="category in alert.categories"
						:key="category"
						:text="kindLabel(category)"
						no-close
						@click="kind = kinds.find((k) => k.id === category) ?? null" />
				</template>
			</AlertRow>
		</ul>

		<NcEmptyContent v-else
			:name="sanitaryAlerts.items.length ? t('epidemiologia', 'Sin coincidencias') : t('epidemiologia', 'Sin alertas')"
			:description="sanitaryAlerts.items.length
				? t('epidemiologia', 'Ninguna alerta del ISP coincide con esos filtros.')
				: t('epidemiologia', 'Sus categorías no traen alertas en este momento.')">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { NcButton, NcChip, NcDateTime, NcEmptyContent, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { t, n, getCanonicalLocale } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import AlertRow from './AlertRow.vue'
import PaneHeader from './PaneHeader.vue'
import { sanitaryAlerts, fold } from '../state.js'

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

/**
 * The list opens scoped to the most recent year that HAS data, not to
 * `new Date().getFullYear()` — in January the ISP may not have published
 * anything yet, and a default that resolves to an empty list is worse than no
 * default at all. Derived from the data, so it cannot point at nothing.
 *
 * It is a starting point, not a cage: the year select is clearable and
 * "Quitar filtros" resets everything, and the count line always says
 * "N de 60" so the scoping is never silent.
 */
const newestYear = [...new Set(sanitaryAlerts.items.map((a) => (a.date ?? '').slice(0, 4)).filter(Boolean))]
	.sort()
	.pop() ?? null
const year = ref(newestYear ? { id: newestYear, label: newestYear } : null)

/**
 * Sorting. "Tipo" needs a single key from a list of categories, so it uses the
 * alphabetically first label — deterministic, and it groups an alert with the
 * category a reader would look for it under. Date breaks every tie, because a
 * list of recalls with no date order is unusable whatever it is grouped by.
 */
const SORTS = [
	{ id: 'recent', label: t('epidemiologia', 'Más recientes') },
	{ id: 'oldest', label: t('epidemiologia', 'Más antiguas') },
	{ id: 'kind', label: t('epidemiologia', 'Tipo de alerta') },
	{ id: 'title', label: t('epidemiologia', 'Título') },
]
const sort = ref(SORTS[0])

/** The secondary row is collapsed until asked for. */
const more = ref(false)

/** How many collapsed filters are currently narrowing the list. */
const hiddenActive = computed(() => (year.value ? 1 : 0) + (month.value ? 1 : 0))

/**
 * Folded ONCE, not per keystroke. The search covers the title AND the category
 * labels, so typing "dispositivo" finds device alerts whose title never says the
 * word — which is most of them, since the title names the product.
 */
const folded = sanitaryAlerts.items.map((a) => ({
	...a,
	_fold: fold(a.title + ' ' + (a.categories ?? []).map(kindLabel).join(' ')),
	_kind: [...(a.categories ?? [])].map(kindLabel).sort()[0] ?? '',
}))

/** Categories present in the data, with how many alerts each carries. */
const kinds = computed(() => {
	const counts = new Map()
	for (const alert of sanitaryAlerts.items) {
		for (const category of alert.categories ?? []) {
			counts.set(category, (counts.get(category) ?? 0) + 1)
		}
	}

	return [...counts.entries()]
		.map(([id, count]) => ({ id, count, label: `${kindLabel(id)} (${count})` }))
		.sort((a, b) => a.label.localeCompare(b.label, getCanonicalLocale()))
})

/** Years present in the data, newest first. */
const years = computed(() => tally(sanitaryAlerts.items, (a) => (a.date ?? '').slice(0, 4))
	.sort((a, b) => b.id.localeCompare(a.id))
	.map((y) => ({ ...y, label: `${y.id} (${y.count})` })))

/**
 * Months, scoped to the chosen year. Offering a month that the selected year
 * does not have would let a reader build a filter pair that can only ever
 * return nothing.
 */
const months = computed(() => {
	const inYear = year.value
		? sanitaryAlerts.items.filter((a) => (a.date ?? '').startsWith(year.value.id))
		: sanitaryAlerts.items

	return tally(inYear, (a) => (a.date ?? '').slice(5, 7))
		.sort((a, b) => b.id.localeCompare(a.id))
		.map((m) => ({ ...m, label: `${monthName(m.id)} (${m.count})` }))
})

// Changing the year can strand a month that year does not have — which would
// silently show zero results and look like a bug in the data.
watch(year, () => {
	if (month.value && !months.value.some((m) => m.id === month.value.id)) {
		month.value = null
	}
})

/** @return {Array<{id: string, count: number}>} non-empty keys with their counts */
function tally(items, key) {
	const counts = new Map()
	for (const item of items) {
		const k = key(item)
		if (k) {
			counts.set(k, (counts.get(k) ?? 0) + 1)
		}
	}

	return [...counts.entries()].map(([id, count]) => ({ id, count }))
}

function monthName(mm) {
	const name = new Date(2000, Number(mm) - 1, 1)
		.toLocaleDateString(getCanonicalLocale(), { month: 'long' })

	return `${name.charAt(0).toUpperCase()}${name.slice(1)}`
}

// Sync the default's label with the real option, so the control does not show a
// bare "2026" while every other entry shows "2026 (55)".
watch(years, (list) => {
	if (year.value) {
		const match = list.find((y) => y.id === year.value.id)
		if (match) {
			year.value = match
		}
	}
}, { immediate: true })

const filtered = computed(() => query.value.trim() !== ''
	|| kind.value !== null || year.value !== null || month.value !== null)

function clear() {
	query.value = ''
	kind.value = null
	year.value = null
	month.value = null
}

const shown = computed(() => {
	const needle = fold(query.value.trim())
	const rows = folded.filter((alert) => (!needle || alert._fold.includes(needle))
		&& (!kind.value || (alert.categories ?? []).includes(kind.value.id))
		&& (!year.value || (alert.date ?? '').startsWith(year.value.id))
		&& (!month.value || (alert.date ?? '').slice(5, 7) === month.value.id))

	const by = {
		recent: (a, b) => b.at - a.at,
		oldest: (a, b) => a.at - b.at,
		title: (a, b) => a.title.localeCompare(b.title, getCanonicalLocale()),
		// Date breaks the tie so each type reads newest-first inside its group.
		kind: (a, b) => a._kind.localeCompare(b._kind, getCanonicalLocale()) || b.at - a.at,
	}[sort.value.id]

	return [...rows].sort(by)
})

const countLabel = computed(() => {
	const total = sanitaryAlerts.items.length
	if (shown.value.length === total) {
		return n('epidemiologia', '%n alerta del ISP', '%n alertas del ISP', total)
	}

	return t('epidemiologia', '{shown} de {total} alertas', { shown: shown.value.length, total })
})
</script>

<style scoped>
/*
	A wrapping row. With at most three controls per row the library's 240/260px
	minimums fit a ~1000px pane, so nothing overflows sideways and nothing has to
	be forced narrower than the component was designed for.
*/
.epi-filters {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 3);
	align-items: flex-end;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.epi-filters :deep(.input-field) {
	flex: 1 1 240px;
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


.epi-alert__date {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}
</style>
