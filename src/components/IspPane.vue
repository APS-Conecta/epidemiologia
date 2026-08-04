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
		<PaneHeader :title="paneName('isp')" />

		<DegradedNotice v-if="sanitaryAlerts.degraded"
			source="el ISP"
			url="https://www.ispch.gob.cl/categorias-alertas/anamed/" />

		<FilterBar :query="query"
			:count="countLabel"
			:fetched-at="sanitaryAlerts.fetched_at"
			@update:query="query = $event">
			<FilterMenu :label="t('epidemiologia', 'Tipo')"
				radio-name="isp-kind"
				:any-label="t('epidemiologia', 'Todos los tipos')"
				:groups="kindGroups"
				:model-value="kind"
				@update:model-value="kind = $event">
				<template #icon>
					<TagOutline :size="18" />
				</template>
			</FilterMenu>
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

		<ul v-if="shown.length" class="epi-list">
			<AlertRow v-for="alert in shown"
				:key="alert.url"
				:title="alert.title"
				:url="alert.url">
				<template #meta>
					<NcDateTime class="epi-alert__date" :timestamp="alert.at * 1000" :relative-time="false" :format="{ dateStyle: 'long' }" />
					<!-- Every category that carried it, not just the first: an alert
					     genuinely belongs to all of them. Rendered as text rather than
					     filled pills — 57 alerts carried 154 of those, nearly three per
					     row, and they outweighed the titles they were describing. Still
					     buttons, so one click still filters. -->
					<button v-for="category in alert.categories"
						:key="category"
						class="epi-alert__cat"
						@click="kind = category">
						{{ kindLabel(category) }}
					</button>
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
import { NcDateTime, NcEmptyContent } from '@nextcloud/vue'
import { t, n, getCanonicalLocale } from '@nextcloud/l10n'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import AlertRow from './AlertRow.vue'
import FilterBar from './FilterBar.vue'
import FilterMenu from './FilterMenu.vue'
import DegradedNotice from './DegradedNotice.vue'
import PaneHeader from './PaneHeader.vue'
import { paneName } from '../panes.js'
import { sanitaryAlerts, fold, monthOptions, tally, yearOptions } from '../state.js'

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
const year = ref(null)
const month = ref(null)

/**
 * The families the ISP's OWN naming declares, and one group for the ones it does
 * not.
 *
 * 16 of its 24 terms name their family in the slug — the `-dp` suffix for
 * dispositivos, `cosmet…`, `desinfectante…`, and the medicamentos cluster — so
 * grouping those is reading the ISP's nomenclature. The other eight
 * (advertencia de seguridad, informe técnico, nota informativa, robo,
 * actualización de software…) are NOT adscribed by the ISP to anything, and they
 * stay together under a label that says so.
 *
 * Sorting them by resemblance would be inventing a classification the source did
 * not publish — the same thing PLAN.md forbids for MINSAL disease tags, and for
 * the same reason: a clinician would read our guess as the ISP's.
 */
const SIN_FAMILIA = t('epidemiologia', 'Sin familia declarada por el ISP')

function familia(slug) {
	if (/-dp$|dispositivo/.test(slug)) {
		return t('epidemiologia', 'Dispositivos médicos')
	}
	if (slug.includes('cosmet')) {
		return t('epidemiologia', 'Cosméticos')
	}
	if (slug.includes('desinfectante')) {
		return t('epidemiologia', 'Desinfectantes')
	}
	if (/anamed|farmacovigilancia|retiro-de-mercado|producto-sin-registro|falsificado-producto/.test(slug)) {
		return t('epidemiologia', 'Medicamentos')
	}
	return SIN_FAMILIA
}

/** Categories present in the data, grouped by family, undeclared ones last. */
const kindGroups = computed(() => {
	const counts = tally(sanitaryAlerts.items.flatMap((a) => a.categories ?? []), (c) => c)
	const byFamily = new Map()
	for (const { id, count } of counts) {
		const f = familia(id)
		if (!byFamily.has(f)) {
			byFamily.set(f, [])
		}
		byFamily.get(f).push({ id, count, label: kindLabel(id) })
	}

	return [...byFamily.entries()]
		.map(([name, options]) => ({ name, options: options.sort((a, b) => b.count - a.count) }))
		.sort((a, b) => (a.name === SIN_FAMILIA) - (b.name === SIN_FAMILIA)
			|| b.options.length - a.options.length)
})

// The ISP stores a full date where MINSAL stores year and month, so only these two
// readers differ; the menus themselves are built in state.js by the same code that
// builds Alertas vigentes'. Nothing is pre-applied: the pane opens on every year.
const yearOf = (a) => (a.date ?? '').slice(0, 4)
const monthOf = (a) => (a.date ?? '').slice(0, 7)

const years = computed(() => yearOptions(sanitaryAlerts.items, yearOf))
const months = computed(() => monthOptions(sanitaryAlerts.items, yearOf, monthOf, year.value))

// Changing the year can strand a month that year does not have, which would show
// zero results and read as a fault in the data rather than in the filter pair.
watch(year, () => {
	if (month.value && !months.value.some((m) => m.id === month.value)) {
		month.value = null
	}
})

/**
 * Folded ONCE, not per keystroke. The search covers the title AND the category
 * labels, so typing "dispositivo" finds device alerts whose title never says the
 * word — which is most of them, since the title names the product.
 */
const folded = sanitaryAlerts.items.map((a) => ({
	...a,
	_fold: fold(a.title + ' ' + (a.categories ?? []).map(kindLabel).join(' ')),
}))

const shown = computed(() => {
	const needle = fold(query.value.trim())

	return folded.filter((a) => (!needle || a._fold.includes(needle))
		&& (!kind.value || (a.categories ?? []).includes(kind.value))
		&& (!year.value || (a.date ?? '').startsWith(year.value))
		&& (!month.value || (a.date ?? '').startsWith(month.value)))
})

const countLabel = computed(() => {
	const total = sanitaryAlerts.items.length
	if (shown.value.length === total) {
		return n('epidemiologia', '%n alerta', '%n alertas', total)
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
</style>
