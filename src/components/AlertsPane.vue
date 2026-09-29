<!--
	One alert sector's list. Every sector is this pane, told apart by its registry id.

	It was degreyd's alone until six sectors came to share the pipeline behind it,
	one pane each. What differed between them — which fuente, whose, the address a
	reader is sent to, the noun the toolbar counts in, the radio names — is read
	off the registry entry (src/panes.js) and the maps keyed by it, so a sector
	joins by adding an entry there, not a file here.

	All six are mounted at once (v-show, App.vue), and a native radio's `name` is
	document-wide: the menus' radio names carry the pane id, or choosing a year on
	one pane would untick another's. layout.mjs check 10 watches for exactly that.
-->
<template>
	<section>
		<PaneHeader :title="paneName(pane)" />

		<DegradedNotice v-if="feed.degraded" :source="fuente.name" :url="SITIOS[sector.source]" />

		<FilterBar :query="query"
			:count="countLabel"
			:fetched-at="feed.fetched_at"
			:active="activeFilters"
			@update:query="query = $event"
			@clear="clearFilter">
			<FilterMenu :label="t('epidemiologia', 'Año')"
				:radio-name="`${pane}-year`"
				:any-label="t('epidemiologia', 'Todos los años')"
				:options="years"
				:model-value="year"
				@update:model-value="year = $event">
				<template #icon>
					<CalendarRange :size="18" />
				</template>
			</FilterMenu>
			<FilterMenu :label="t('epidemiologia', 'Mes')"
				:radio-name="`${pane}-month`"
				:any-label="t('epidemiologia', 'Todos los meses')"
				:options="months"
				:model-value="month"
				@update:model-value="month = $event">
				<template #icon>
					<CalendarMonthOutline :size="18" />
				</template>
			</FilterMenu>
			<!-- Drawn only where the items carry a tipo — the OPS/OMS list, today. -->
			<FilterMenu v-if="tipos.length"
				:label="t('epidemiologia', 'Tipo')"
				:radio-name="`${pane}-tipo`"
				:any-label="t('epidemiologia', 'Todos los tipos')"
				:options="tipos"
				:model-value="tipo"
				@update:model-value="tipo = $event">
				<template #icon>
					<ShapeOutline :size="18" />
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
					<!-- The day where the publisher gives one; the month where it does
					     not — MINSAL's pages publish none, and a day is never invented
					     from a month. No tipo here: the title's own first words say it. -->
					<span class="epi-alert__when">{{ alert.date ? fecha(alert.date) : mes(alert.month) }}</span>
				</template>
			</AlertRow>
		</ul>

		<!--
			Three emptinesses, and telling them apart is the whole point. Items that no
			filter lets through are the reader's search, not the publisher's silence —
			saying "nothing matched" to a reader who had not searched blamed them for an
			outage. Zero items, answered, is the publisher saying zero. Zero items, NOT
			answered, is neither: the warning above is the whole truth, and an "Sin
			alertas" beside it would be the claim L3-14 exists to refuse — and the state
			four sectors are in from a datacentre. layout.mjs check 11 holds it.
		-->
		<NcEmptyContent v-else-if="feed.items.length"
			:name="t('epidemiologia', 'Sin coincidencias')"
			:description="t('epidemiologia', 'Nada en esta lista coincide con esos filtros.')">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!feed.degraded"
			:name="t('epidemiologia', 'Sin alertas')"
			:description="emptyText(fuente.name)">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { computed, ref } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import AlertRow from './AlertRow.vue'
import FilterBar from './FilterBar.vue'
import FilterMenu from './FilterMenu.vue'
import PaneHeader from './PaneHeader.vue'
import DegradedNotice from './DegradedNotice.vue'
import { PANES, paneName } from '../panes.js'
import { source } from '../state.js'
import { emptyText, fecha, mes, tipoOptions, FUENTES, SITIOS } from '../pure.js'
import { useFilters } from '../filters.js'

const props = defineProps({
	/** The registry id of the sector this instance shows (src/panes.js). */
	pane: { type: String, required: true },
})

// Read once, not followed: an instance is mounted for one sector and never changes it.
const sector = PANES.find((p) => p.id === props.pane)
const feed = source(sector.source)
const fuente = FUENTES[sector.group]

// The filter pair, its menus and its stranding rule live in src/filters.js — the
// copies had already drifted once (#44), which is why they live there and not here.
// Every item carries year and month, and a stream's a day besides (openapi.json).
const { query, year, month, years, months, matches, active, clear } = useFilters(feed.items, {
	yearOf: (a) => a.year,
	monthOf: (a) => a.month,
	textOf: (a) => a.title,
})

/*
 * The Tipo, composed on top of the shared three the way the ISP pane composed its
 * category until it retired (ADR-0016): useFilters' docblock keeps a pane's own
 * predicate in the pane. Options come from the data, so a list without tipos has none.
 */
const tipo = ref(null)
const tipos = tipoOptions(feed.items)

const shown = computed(() => matches.value.filter((a) => !tipo.value || a.tipo === tipo.value))

/** The shared tokens, plus the Tipo's — worded as its menu words it. */
const activeFilters = computed(() => (tipo.value
	? [...active.value, { id: 'tipo', label: tipos.find((o) => o.id === tipo.value)?.label ?? tipo.value }]
	: active.value))

/**
 * @param {string} id - which token was dismissed
 */
function clearFilter(id) {
	if (id === 'tipo') {
		tipo.value = null
	} else {
		// Anything else is useFilters', which ignores ids it does not own.
		clear(id)
	}
}

const countLabel = computed(() => {
	const total = feed.items.length
	if (shown.value.length === total) {
		return fuente.count(total)
	}
	return t('epidemiologia', '{shown} de {total}', { shown: shown.value.length, total: fuente.count(total) })
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
