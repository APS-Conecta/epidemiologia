<!--
	The landing pane: what the sources last published, visible without scrolling.

	Two things about it are decisions, not implementation details:

	1. It is NOT personalised. "Latest" is a property of the source, not of the
	   reader, so there is no per-user state and nothing to seed on first run.
	   MINSAL items carry a month and no day anyway, so "new since you last looked"
	   was never constructible from them.

	2. Freshness and degradation are PER CARD. MINSAL and the ISP fail
	   independently and fetched_at is already per feed; one banner across the pane
	   would force a choice between alarming about everything and staying quiet
	   about everything. Only the failing source turns gold.
-->
<template>
	<section class="epi-inicio">
		<header>
			<h2>{{ t('epidemiologia', 'Inicio') }}</h2>
			<p class="epi-inicio__sub">{{ t('epidemiologia', 'Lo último que publicaron nuestras fuentes') }}</p>
		</header>

		<!--
			auto-fit rather than a fixed column count: one source fills the row, two
			split it, and a third wraps. Adding a source is adding an entry to
			`sources`, which is what makes this pane scale without a redesign.
		-->
		<div class="epi-inicio__sources">
			<article v-for="source in sources"
				:key="source.id"
				class="epi-card"
				:class="{ 'epi-card--lead': source.lead, 'epi-card--degraded': source.feed.degraded }">
				<div class="epi-card__head">
					<h3>{{ source.name }}</h3>
					<span class="epi-fresh" :class="{ 'epi-fresh--stale': source.feed.degraded }">
						<span class="epi-fresh__dot" />
						<template v-if="source.feed.degraded">{{ t('epidemiologia', 'sin contacto') }}</template>
						<template v-else-if="source.feed.fetched_at">
							{{ t('epidemiologia', 'leído') }}
							<NcDateTime :timestamp="source.feed.fetched_at * 1000" relative-time="long" />
						</template>
					</span>
				</div>

				<p v-if="source.feed.degraded" class="epi-card__warn">
					{{ source.degradedText }}
				</p>

				<!-- Zero items with no degraded flag is a real answer, not a fault. Several
				     of the ISP's term feeds legitimately return nothing. It must not look
				     like the warning above, or the warning stops meaning anything. -->
				<NcEmptyContent v-if="!source.feed.items.length && !source.feed.degraded"
					class="epi-card__empty"
					:name="t('epidemiologia', 'Sin alertas nuevas')"
					:description="source.emptyText">
					<template #icon>
						<CheckCircle :size="20" />
					</template>
				</NcEmptyContent>

				<ul v-else class="epi-card__list">
					<li v-for="item in source.feed.items.slice(0, PER_CARD)" :key="item.url" class="epi-row">
						<!-- title: Inicio rows are one line on purpose (six must fit without
						     scrolling), and ISP titles put the product at the END, so the
						     truncation removes the useful half. A tooltip gives it back for
						     free; the full text lives one click away in the pane. #35 -->
						<a :href="item.url"
							:title="item.title"
							target="_blank"
							rel="noopener noreferrer">{{ item.title }}</a>
						<span class="epi-row__when">{{ item.date || item.month || item.year }}</span>
					</li>
				</ul>

				<NcButton variant="tertiary" class="epi-card__more" @click="$emit('open', source.pane)">
					{{ source.moreText }}
				</NcButton>
			</article>
		</div>

		<article class="epi-card">
			<div class="epi-card__head">
				<h3>{{ t('epidemiologia', 'Informes ETI/IRAG') }}</h3>
				<NcButton variant="tertiary" @click="$emit('open', 'informe')">
					{{ t('epidemiologia', 'Ver todos') }}
				</NcButton>
			</div>

			<NcEmptyContent v-if="!reports.items.length"
				class="epi-card__empty"
				:name="t('epidemiologia', 'Sin informes')"
				:description="t('epidemiologia', 'MINSAL no ha publicado el informe de esta semana.')">
				<template #icon>
					<CheckCircle :size="20" />
				</template>
			</NcEmptyContent>

			<!-- The week number, not a thumbnail of the PDF's first page: the runtime
			     image ships imagick but no ghostscript, so rasterising is impossible
			     without a derived production image (ADR-0002). -->
			<div v-else class="epi-weeks">
				<a v-for="(report, i) in reports.items.slice(0, PER_CARD_WEEKS)"
					:key="report.url"
					class="epi-week"
					:class="{ 'epi-week--current': i === 0 }"
					:href="report.url"
					target="_blank"
					rel="noopener noreferrer">
					<b>{{ report.se || '—' }}</b>
					<span class="epi-week__meta">
						<small>{{ i === 0 ? t('epidemiologia', 'Semana epidemiológica') : t('epidemiologia', 'Semana') }}</small>
						<span>{{ t('epidemiologia', 'Influenza · PDF') }} ↗</span>
					</span>
				</a>
			</div>
		</article>
	</section>
</template>

<script setup>
import { computed } from 'vue'
import { NcButton, NcDateTime, NcEmptyContent } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import { alerts, sanitaryAlerts, reports } from '../state.js'

defineEmits(['open'])

/** What fits at 500px of content height without the card scrolling. */
const PER_CARD = 6
const PER_CARD_WEEKS = 3

/**
 * One entry per publisher. The ISP joins here in #33 — the shape is already
 * right for it, which is the whole point of the auto-fit row above.
 */
const sources = computed(() => [
	{
		id: 'minsal-alertas',
		name: t('epidemiologia', 'Alertas vigentes'),
		feed: alerts,
		pane: 'alertas',
		lead: true,
		moreText: n('epidemiologia', 'Ver %n alerta', 'Ver las %n alertas', alerts.items.length),
		emptyText: t('epidemiologia', 'MINSAL no tiene alertas vigentes publicadas.'),
		degradedText: t('epidemiologia', 'No hemos podido contactar a MINSAL. Esta lista puede estar incompleta.'),
	},
	{
		id: 'isp-alertas',
		name: t('epidemiologia', 'Alertas sanitarias'),
		feed: sanitaryAlerts,
		pane: 'isp',
		lead: false,
		moreText: n('epidemiologia', 'Ver %n alerta del ISP', 'Ver las %n alertas del ISP', sanitaryAlerts.items.length),
		// Not "el ISP no publicó": several of its 24 categories are legitimately
		// empty in any given week, and saying nothing was published would be false.
		emptyText: t('epidemiologia', 'Sus categorías no traen alertas esta semana.'),
		degradedText: t('epidemiologia', 'No hemos podido contactar al ISP. Esta lista puede estar incompleta.'),
	},
])
</script>

<style scoped>
/* The pane is a grid that fills the height it is given, so nothing scrolls on a
   desktop. Each card clips its own overflow instead — which is what lets a new
   source arrive as another card rather than as more page. */
.epi-inicio {
	display: grid;
	grid-template-rows: auto minmax(0, 1fr) auto;
	gap: calc(var(--default-grid-baseline) * 4);
	height: 100%;
	min-height: 0;
}

.epi-inicio__sub {
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}

.epi-inicio h2 {
	margin: 0;
}

.epi-inicio__sources {
	display: grid;
	/* Both halves are load-bearing, and each was measured on the instance for #20.
	   min(320px, 100%) rather than a bare 320px: auto-fit collapses columns but
	   never shrinks a track below its own floor, so at a 360px viewport — where
	   this pane's content box is 281px — the card stayed 320px and Inicio, the
	   landing pane, scrolled sideways.
	   min-width: 0 because this is itself a grid item, and its automatic minimum
	   is min-content. Without it the percentage inside min() resolves against an
	   indefinite width and Chromium blows the grid out to 1968px at EVERY
	   viewport — far worse than the 39px it was meant to fix. Neither line works
	   alone: dropping the min() leaves the card clipped at 320px inside a 281px
	   grid, which merely hides the overflow instead of removing it. */
	grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr));
	min-width: 0;
	gap: calc(var(--default-grid-baseline) * 3);
	min-height: 0;
}

.epi-card {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	min-height: 0;
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
}

/* The primary source, said with an edge rather than a word or a new colour. */
.epi-card--lead {
	border-inline-start: 3px solid var(--color-primary-element);
}

/* --color-warning is a pale cream FILL, not the orange; --color-element-warning
   is the orange. Getting these the wrong way round is what made the old degraded
   banner's border invisible (#14). */
.epi-card--degraded {
	border-color: var(--color-element-warning);
}

.epi-card__head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.epi-card__head h3 {
	margin: 0;
	font-size: var(--font-size-small);
	font-weight: var(--font-weight-heading);
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.epi-fresh {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.epi-fresh__dot {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: var(--color-success);
	flex: 0 0 6px;
}

/* Small text on the warning fill must be --color-warning-text: the brand gold
   measures 3.07:1 and does not reach AA below 24px (MAPEO.md §1). */
.epi-fresh--stale {
	color: var(--color-warning-text);
	font-weight: var(--font-weight-heading);
}

.epi-fresh--stale .epi-fresh__dot {
	background: var(--color-element-warning);
}

.epi-card__warn {
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-element);
	background: var(--color-warning);
	color: var(--color-warning-text);
	font-size: var(--font-size-small);
}

.epi-card__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
	overflow: hidden;
	/*
		The card is a grid cell, so its height is whatever the viewport gives it —
		never a whole number of 34px rows. Measured on the instance: 186px of box
		against 204px of content, i.e. 5.47 rows, so the sixth was sliced in half
		and read as broken rendering.

		The fade makes a clipped row read as "there is more", which is true, and the
		"Ver las N" button underneath says where. When the list is shorter than its
		box the fade falls on empty space and is invisible, so this needs no
		overflow detection.
	*/
	-webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 20px), transparent);
	mask-image: linear-gradient(to bottom, #000 calc(100% - 20px), transparent);
}

.epi-card__empty {
	flex: 1 1 auto;
	min-height: 0;
}

.epi-card__more {
	margin-top: auto;
	align-self: flex-start;
}

.epi-row {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	min-height: var(--default-clickable-area);
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-element);
}

.epi-row:hover {
	background: var(--color-background-hover);
}

.epi-row a {
	flex: 1;
	min-width: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	color: var(--color-main-text);
	text-decoration: none;
}

.epi-row a:hover,
.epi-row a:focus-visible {
	text-decoration: underline;
}

.epi-row__when {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.epi-weeks {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
	flex-wrap: wrap;
}

.epi-week {
	flex: 1 1 180px;
	display: flex;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline) * 2.5);
	min-width: 0;
	padding: calc(var(--default-grid-baseline) * 2.5) calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	color: var(--color-main-text);
	text-decoration: none;
}

.epi-week:hover {
	background: var(--color-background-hover);
}

.epi-week--current {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.epi-week b {
	font-size: 1.7em;
	line-height: 1;
	font-weight: var(--font-weight-heading);
	font-variant-numeric: tabular-nums;
}

.epi-week__meta {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.epi-week__meta small {
	font-size: var(--font-size-small);
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	font-weight: var(--font-weight-heading);
}

.epi-week__meta span {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Below the mobile breakpoint the grid becomes one column and the pane scrolls.
   That is fine: what must not scroll is the desktop in the box. */
@media (max-width: 1024px) {
	.epi-inicio {
		height: auto;
		grid-template-rows: none;
	}

	/* Releasing the clipping is not enough on its own: min-height:0 has to come
	   off the card AND the list, and only together.
	   On a desktop the pair is deliberate — the card has the height the viewport
	   gives it and the list shrinks and scrolls inside it. Here the card is
	   content-sized, so a shrinkable list with nothing forcing it open collapsed
	   to EXACTLY 0px: the phone showed each card's header and its "Ver las 75
	   alertas" link with no alerts in between, which is the one thing this pane
	   exists to show. Freeing only the list makes it render at its 204px while
	   the card stays 79px, so the rows spill over the card below it. Measured on
	   the instance for #20 — the pane then scrolls, which is what this block
	   already says mobile should do. */
	.epi-card,
	.epi-card__list {
		min-height: auto;
	}

	.epi-card__list {
		overflow: visible;
	}

	/*
		Two lines instead of one, on a phone only.

		#35 established the rule for the ISP pane and gave the reason: these titles
		put the useful half at the END, so truncation removes exactly what a reader
		needed. Inicio kept one line because "six must fit without scrolling" — but
		that is a DESKTOP argument, and below this breakpoint the pane scrolls
		anyway, which is what the rules above already accept.

		Measured at 360px: one line shows 21 characters of a 252-character oficio
		title, two show 43. Each extra line costs about 66px per card, and there is
		no hover on a phone, so the `title` attribute that rescues this on a desktop
		rescues nothing here. Two is the trade; the full text is one tap away in the
		pane, which is what "Ver las 75 alertas" is for.
	*/
	.epi-row {
		align-items: flex-start;
	}

	.epi-row a {
		white-space: normal;
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
	}
}
</style>
