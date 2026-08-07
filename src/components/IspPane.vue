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
		<!-- The heading is the navigation row that opened it, verbatim. layout.mjs
		     asserts that and it is not a formality: a reader used to tap one wording
		     and land on another, which is why panes.js exists at all. -->
		<PaneHeader :title="familyName" />

		<DegradedNotice v-if="sanitaryAlerts.degraded"
			source="el ISP"
			url="https://www.ispch.gob.cl/categorias-alertas/anamed/" />

		<FilterBar :query="query"
			:count="countLabel"
			:fetched-at="sanitaryAlerts.fetched_at"
			@update:query="query = $event">
			<FilterMenu :label="t('epidemiologia', 'Año')"
				radio-name="isp-year"
				:any-label="t('epidemiologia', 'Todos los años')"
				:options="years"
				:model-value="year"
				@update:model-value="year = $event">
				<template #icon>
					<CalendarRange :size="18" />
				</template>
			</FilterMenu>
			<FilterMenu :label="t('epidemiologia', 'Mes')"
				radio-name="isp-month"
				:any-label="t('epidemiologia', 'Todos los meses')"
				:options="months"
				:model-value="month"
				@update:model-value="month = $event">
				<template #icon>
					<CalendarMonthOutline :size="18" />
				</template>
			</FilterMenu>
		</FilterBar>

		<!--
			The rail, and it FILTERS rather than files. An alert belongs to 1,76
			categories on average and 43 % of memberships are duplicates, so giving each
			one a single home would assert a classification the ISP never published.
			Chips toggle: clicking the active one clears it, so the way out is the way in.
			Counts come from the loaded data, so a category the ISP stops publishing
			leaves the rail on its own rather than sitting at zero.
		-->
		<!-- aria-pressed, because the ON state is otherwise a background colour and
		     nothing else: a screen reader was read seven identical buttons with no way
		     to tell which filter was applied, and a high-contrast user had only the
		     fill. The class stays for sighted users; this is the same fact, told to
		     everyone else. -->
		<div v-if="rail.length > 1" class="epi-rail" role="group" :aria-label="t('epidemiologia', 'Categorías')">
			<button class="epi-rail__chip"
				:class="{ 'epi-rail__chip--on': kind === null }"
				:aria-pressed="kind === null"
				@click="kind = null">
				{{ railAllLabel }}
			</button>
			<button v-for="c in rail"
				:key="c.slug"
				class="epi-rail__chip"
				:class="{ 'epi-rail__chip--on': kind === c.slug }"
				:aria-pressed="kind === c.slug"
				@click="kind = kind === c.slug ? null : c.slug">
				{{ kindLabel(c.slug) }} ({{ c.count }})
			</button>
		</div>

		<ul v-if="shown.length" class="epi-list">
			<AlertRow v-for="alert in shown"
				:key="alert.url"
				:title="alert.title"
				:url="alert.url"
				:tags="(alert.categories ?? []).map(kindLabel)">
				<template #meta>
					<span class="epi-alert__date">{{ fecha(alert.date) }}</span>
					<!-- The ISP edits an alert in place to add affected lots, and those
					     lots are the part that changes what a clinician does. Beside the
					     publication date, not replacing it: the alert is still from its
					     original day, it just says more now. -->
					<span v-if="alert.updated" class="epi-alert__updated">
						{{ t('epidemiologia', 'actualizada {dia}', { dia: fecha(alert.updated) }) }}
					</span>
					<!-- Every category that carried it, not just the first: an alert
					     genuinely belongs to all of them. Rendered as text rather than
					     filled pills — 57 alerts carried 154 of those, nearly three per
					     row, and they outweighed the titles they were describing. Still
					     buttons, so one click still filters. -->
					<!-- Toggles, like the rail. Assigning meant a second tap did nothing
					     and no chip lit up — and inside a family these list categories the
					     rail does not offer, so the only way back was switching family.
					     Two controls that filter by category, on one screen, behaving
					     the same way. -->
					<button v-for="category in alert.categories"
						:key="category"
						class="epi-alert__cat"
						:aria-pressed="kind === category"
						@click="kind = kind === category ? null : category">
						{{ kindLabel(category) }}
					</button>
					<!-- Only when the pane is showing everything: inside a family the tag
					     would repeat the heading on every row. And only when it ADDS
					     something — "Dispositivos médicos · Retiro — dispositivo médico ·
					     Dispositivos médicos" is one row saying the same words twice,
					     which is what shipped in #64. -->
					<span v-if="familyTag(alert)" class="epi-alert__fam">{{ familyTag(alert) }}</span>
				</template>
			</AlertRow>
		</ul>

		<NcEmptyContent v-else
			:name="sanitaryAlerts.items.length ? t('epidemiologia', 'Sin coincidencias') : t('epidemiologia', 'Sin alertas')"
			:description="sanitaryAlerts.items.length
				? t('epidemiologia', 'Ninguna alerta del ISP coincide con esos filtros.')
				: emptyText(t('epidemiologia', 'El ISP'))">
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
import DegradedNotice from './DegradedNotice.vue'
import PaneHeader from './PaneHeader.vue'
import { paneName } from '../panes.js'
import { emptyText, fecha, sanitaryAlerts } from '../state.js'
import { useFilters } from '../filters.js'
import { categoriesIn, familiaName, familiaOf } from '../familias.js'

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

const props = defineProps({
	/** Which ISP family to show; null is the whole archive. Set by the navigation. */
	family: { type: String, default: null },
})

const kind = ref(null)

/** The heading, and the rail's own "all" chip, both named after the navigation row. */
const familyName = computed(() =>
	props.family === null ? paneName('isp') : familiaName(props.family))
const railAllLabel = computed(() =>
	props.family === null
		? t('epidemiologia', 'Todas las categorías')
		: t('epidemiologia', 'Todas las de {familia}', { familia: familiaName(props.family) }))

/**
 * The family to tag a row with, or '' to tag nothing.
 *
 * Empty inside a family (the heading already says it) and empty when one of the
 * row's own category buttons already carries the same words — an alert filed under
 * "Dispositivos médicos" does not need a tag reading "Dispositivos médicos".
 *
 * @param {object} alert - one alert, with its categories
 * @return {string} the family label, or ''
 */
function familyTag(alert) {
	if (props.family !== null) {
		return ''
	}
	const cats = alert.categories ?? []
	if (!cats.length) {
		return ''
	}
	const label = familiaName(familiaOf(cats[0]))

	return cats.some((c) => kindLabel(c) === label) ? '' : label
}

/** Categories carrying alerts in the shown family, busiest first, counted from the data. */
const rail = computed(() => categoriesIn(sanitaryAlerts.items, props.family))

// Switching family must clear a chip from the previous one, or the pane shows an
// empty list filtered by a category the rail no longer offers.
watch(() => props.family, () => {
	kind.value = null
})
// The filter pair, its menus and its stranding rule live in src/filters.js, shared with
// AlertsPane. What stays here is what is genuinely this pane's: the family and the
// category, which no other pane has.
const yearOf = (a) => (a.date ?? '').slice(0, 4)
const monthOf = (a) => (a.date ?? '').slice(0, 7)

const { query, year, month, years, months, matches } = useFilters(sanitaryAlerts.items, {
	yearOf,
	monthOf,
	// The categories are searchable too: "dispositivo" has to find device alerts whose
	// title never says the word, because the title names the product.
	textOf: (a) => a.title + ' ' + (a.categories ?? []).map(kindLabel).join(' '),
})

const shown = computed(() => matches.value.filter((a) =>
	// The family narrows before anything else. An alert is in it if ANY of its
	// categories is — memberships are not exclusive, and requiring all of them would
	// hide the 43 % of alerts that straddle two.
	(props.family === null || (a.categories ?? []).some((c) => familiaOf(c) === props.family))
	&& (!kind.value || (a.categories ?? []).includes(kind.value))))

const countLabel = computed(() => {
	const total = sanitaryAlerts.items.length
	if (shown.value.length === total) {
		return n('epidemiologia', '%n alerta', '%n alertas', total)
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


/* Text, not a pill. Still a button: one click filters to that category. The
   underline appears on hover so the row reads as prose until you reach for it. */
/* The accent colour, at rest. These filter when clicked, and they used to be
   pixel-identical to the static date beside them — 13px, weight 400, #6b6b6b,
   underline transparent — with cursor:pointer the only difference, which is
   invisible until you are already on it and never visible on a touch screen.
   Colour rather than a filled pill: 57 alerts carry 154 of these, and the pills
   outweighed the titles they described. */
.epi-alert__cat {
	border: 0;
	background: none;
	padding: 0;
	font: inherit;
	font-size: var(--font-size-small);
	color: var(--color-primary-element);
	cursor: pointer;
	text-decoration: underline;
	text-decoration-color: transparent;
	text-underline-offset: 2px;
}

.epi-alert__cat:hover,
.epi-alert__cat:focus-visible {
	color: var(--color-main-text);
	text-decoration-color: currentColor;
}

.epi-alert__date {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

/* Horizontal, scrollable, and never wrapping into a wall: at 360px a family's
   categories are more than fit, and a rail that wraps to four rows pushes the
   alerts themselves off the first screen. */
.epi-rail {
	display: flex;
	gap: var(--default-grid-baseline);
	overflow-x: auto;
	padding: var(--default-grid-baseline) 0;
	scrollbar-width: thin;
}

.epi-rail__chip {
	flex: 0 0 auto;
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 100px);
	padding: 0 calc(var(--default-grid-baseline) * 2);
	height: 34px;
	font-size: var(--font-size-small);
	white-space: nowrap;
}

.epi-rail__chip--on {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

/* The family an alert's first category belongs to, shown only in Todas. Quieter
   than the category buttons beside it: it is context, not a control. */
.epi-alert__fam {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	font-style: italic;
	white-space: nowrap;
}

/* Carries weight the date does not: an alert that grew new lots is the one case where
   re-reading something already read is the point. As a BACKGROUND, not as a tint on
   text: --color-primary-element on --color-primary-element-text is the pair the theme
   guarantees contrast for. */
.epi-alert__updated {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-radius: var(--border-radius);
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	font-size: var(--font-size-small);
	white-space: nowrap;
}
</style>
