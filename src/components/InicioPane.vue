<!--
	The landing pane: what the sources last published, visible without scrolling.

	Two things about it are decisions, not implementation details:

	1. It is NOT personalised. "Latest" is a property of the source, not of the
	   reader, so there is no per-user state and nothing to seed on first run.
	   MINSAL items carry a month and no day anyway, so "new since you last looked"
	   was never constructible from them.

	2. Freshness and degradation are PER CARD. The card's own feed carries its
	   own fetched_at and degraded flag, so one banner across the pane would
	   force a choice between alarming about everything and staying quiet about
	   everything; only the failing source turns gold. The informe card below
	   carries the degraded sentence too (L3-16) but no freshness badge: its
	   weeks are the series the table holds, and the pane it opens is where
	   that fuente's freshness renders.
-->
<template>
	<section class="epi-inicio">
		<PaneHeader :title="paneName('inicio')" />

		<!--
			auto-fit rather than a fixed column count: one source fills the row, two
			split it, and a third wraps. Adding a source is adding an entry to
			`sources`, which is what makes this pane scale without a redesign.
		-->
		<div class="epi-inicio__sources">
			<article v-for="source in sources"
				:key="source.id"
				class="epi-card"
				:class="{ 'epi-card--degraded': source.feed.degraded }">
				<div class="epi-card__head">
					<h2 class="epi-card__title">{{ source.name }}</h2>
					<!-- Only when there is something to report. With neither a degraded flag
					     nor a fetched_at, this rendered a bare 6px green dot and no text:
					     colour asserting health nothing had measured, and silence to a
					     screen reader. -->
					<span v-if="source.feed.degraded || source.feed.fetched_at"
						class="epi-fresh"
						:class="{ 'epi-fresh--stale': source.feed.degraded }">
						<span class="epi-fresh__dot" />
						<template v-if="source.feed.degraded">{{ t('epidemiologia', 'sin contacto') }}</template>
						<Procedencia v-else-if="source.feed.fetched_at" :fetched-at="source.feed.fetched_at" />
					</span>
				</div>

				<p v-if="source.feed.degraded" class="epi-card__warn">
					{{ source.degradedText }}
				</p>

				<!-- Zero items with no degraded flag is a real answer, not a fault.
				     A set with no alertas vigentes in it is MINSAL's to publish,
				     and an empty answer must not look like the warning above, or the
				     warning stops meaning anything. -->
				<NcEmptyContent v-if="!source.feed.items.length && !source.feed.degraded"
					class="epi-card__empty"
					:name="t('epidemiologia', 'Sin alertas')"
					:description="source.emptyText">
					<template #icon>
						<CheckCircle :size="20" />
					</template>
				</NcEmptyContent>

				<ul v-else class="epi-card__list">
					<li v-for="item in source.feed.items.slice(0, PER_CARD)" :key="item.url" class="epi-row">
						<!-- No truncation. Both fuentes put the useful half at the END of the
						     title, so an ellipsis removes exactly what a reader needed — the
						     same reasoning #35 recorded for the ISP pane. Showing one whole
						     title beats showing six prefixes. -->
						<a :href="item.url"
							target="_blank"
							rel="noopener noreferrer">{{ item.title }}</a>
						<span class="epi-row__when">{{ item.date ? fecha(item.date) : mes(item.month) }}</span>
					</li>
				</ul>

				<NcButton variant="tertiary" class="epi-card__more" @click="$emit('open', source.pane)">
					{{ source.moreText }}
				</NcButton>
			</article>
		</div>

		<article class="epi-card">
			<div class="epi-card__head">
				<h2 class="epi-card__title">{{ paneName('informe') }}</h2>
				<NcButton variant="tertiary" @click="$emit('open', 'informe')">
					{{ t('epidemiologia', 'Ver todos') }}
				</NcButton>
			</div>

			<!-- Degraded first (L3-16): an informe card with nothing in it used to
			     assert "El ISP no ha publicado el informe de esta semana" for a
			     fuente the page had not managed to READ — a publication claim
			     about a publisher nobody asked. The alertas card above has had
			     this branch since it existed; the informe card was the drift. -->
			<p v-if="reports.degraded" class="epi-card__warn">
				{{ degradedText(FUENTES.isp) }}
			</p>

			<NcEmptyContent v-else-if="!reports.items.length"
				class="epi-card__empty"
				:name="t('epidemiologia', 'Sin informes')"
				:description="t('epidemiologia', 'El ISP no ha publicado el informe de esta semana.')">
				<template #icon>
					<CheckCircle :size="20" />
				</template>
			</NcEmptyContent>

			<!-- The week number, not a thumbnail of the PDF's first page: the runtime
			     image ships imagick but no ghostscript, so rasterising is impossible
			     without a derived production image (ADR-0002). -->
			<div v-else class="epi-weeks">
				<!-- Same weight on all three, and the same wording: the order says
				     which is current, and the card's own title says these are weeks.
				     The first used to be tinted and spell out "Semana epidemiológica"
				     while the others said "Semana", which made one row read as a
				     different kind of object than the two beside it. -->
				<a v-for="report in reports.items.slice(0, PER_CARD_WEEKS)"
					:key="report.url"
					class="epi-week"
					:href="report.url"
					target="_blank"
					rel="noopener noreferrer">
					<b>{{ report.se || '—' }}</b>
					<span class="epi-week__meta">
						<small>{{ t('epidemiologia', 'Semana') }}</small>
						<span>{{ t('epidemiologia', 'Influenza · PDF') }} ↗</span>
					</span>
				</a>
			</div>
		</article>
	</section>
</template>

<script setup>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t, n } from '@nextcloud/l10n'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import PaneHeader from './PaneHeader.vue'
import Procedencia from './Procedencia.vue'
import { paneName } from '../panes.js'
import { alerts, degradedText, emptyText, fecha, mes, reports, FUENTES } from '../state.js'

defineEmits(['open'])

/**
 * ONE alert per card, shown whole.
 *
 * What the pane is for is the latest thing each fuente published, complete. Six
 * abbreviated titles were six rows of "OFICIO CP N°15844 / 2026 Actualización
 * de…" — the same prefix repeated, with the disease name cut off every time.
 *
 * The trade is count for completeness, decided 2026-08-03: what an oficio
 * instructs a clinic to do is not something to show a prefix of. The other 74
 * are one click away, in a pane that now also shows them in full.
 */
const PER_CARD = 1
const PER_CARD_WEEKS = 3

/**
 * One entry per alertas fuente, so the next fuente joins by adding an entry —
 * which is the whole point of the auto-fit row above. The ISP's alertas card
 * was the second entry until its fuente retired (ADR-0016); the informe stays
 * out of this list on purpose, as it always was: it is not an alertas fuente,
 * and its card below shows the series' weeks, not a latest row.
 */
const sources = computed(() => [
	{
		id: 'minsal-alertas',
		name: paneName('alertas'),
		feed: alerts,
		pane: 'alertas',
		moreText: n('epidemiologia', 'Ver %n alerta', 'Ver las %n alertas', alerts.items.length),
		emptyText: emptyText(FUENTES.minsal),
		degradedText: degradedText(FUENTES.minsal),
	},
])
</script>

<style scoped>
/* The pane still fills the height it is given so nothing scrolls on a desktop,
   but its rows are sized by their CONTENT and packed to the top.
   
   They used to stretch: the cards took 1fr and grew to fill whatever was left.
   That was right when a card held six rows and needed the room. With one whole
   alert per card (PER_CARD) it left ~120px of void between the text and the
   button — and empty space inside a bordered card reads as something that failed
   to load, where the same space below the content reads as nothing more to show. */
.epi-inicio {
	display: grid;
	grid-template-rows: auto auto auto;
	align-content: start;
	gap: calc(var(--default-grid-baseline) * 4);
	height: 100%;
	min-height: 0;
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
/* --color-warning is a pale cream FILL, not the orange; --color-element-warning
   is the orange. Getting these the wrong way round is what made the old degraded
   banner's border invisible (#14). */
.epi-card--degraded {
	border-color: var(--color-element-warning);
}

/* Wraps: the title and the freshness are independent, and at 360px they no
   longer share a line once the pane clears the navigation toggle. Caught by
   scripts/layout.sh at +5px rather than by looking. */
.epi-card__head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

/* Measured on core's own Dashboard, the closest thing to this pane in Nextcloud:
   20px / 700 / no transform / -0.3px / --color-main-text. Ours was 13px, weight
   600, UPPERCASE, +0.65px and maxcontrast grey — the opposite on all four axes,
   which left the alert text outweighing the label that named it. */
.epi-card__title {
	margin: 0;
	font-size: 20px;
	font-weight: 700;
	letter-spacing: -0.3px;
	color: var(--color-main-text);
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
	/*
		No clipping and no fade any more, and their removal is the point.

		Both existed to serve a card stretched by a 1fr row: it held six titles in
		whatever height the viewport left, so the last row was sliced mid-line and a
		gradient turned that into "there is more" rather than broken rendering.

		The card is now sized to its content and holds ONE whole alert, so the fade
		had nothing to signal and instead dimmed the final line of a complete title —
		making finished text look cut off, which is the exact opposite of the reason
		the titles are shown whole. Its old comment even said the fade would "fall on
		empty space and be invisible"; that was true of a stretched card and stopped
		being true here.
	*/
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
	align-items: flex-start;
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
	color: var(--color-main-text);
	text-decoration: none;
	/* MINSAL titles carry 52-character tokens with no break opportunity in them
	   (B27_N_1709_REFUERZA_VACUNACION_CONTRA_SARAMPION_2025), and the intrinsic
	   minimum is what overflows a phone. Same rule, same reason, as AlertRow. */
	overflow-wrap: anywhere;
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

	/* The card can still be squeezed by the flex column above it, and min-height:0
	   lets it shrink past its content — which is how the phone once showed a card
	   header and its "Ver las 75 alertas" link with no alert in between (#20). The
	   list no longer needs the same release: it stopped carrying min-height:0 and
	   overflow:hidden when the card became content-sized. */
	.epi-card {
		min-height: auto;
	}

	/* The date moves under the title rather than beside it. At 360px the row is
	   ~280px and the date was taking 90 of them, so a single alert ran to 13 lines
	   and filled the whole screen. Stacked, the title gets the full width back. */
	.epi-row {
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 0.5);
	}

}
</style>
