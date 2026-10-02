<!--
	The landing pane: what each alert sector last published, one tile per sector,
	and the informe's latest weeks below them.

	Three things about it are decisions, not implementation details:

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

	3. The tiles are the registry's sectors, in its order — the navigation's
	   order (src/panes.js). Not grouped under publisher headings, which would
	   repeat the navigation's captions as a second layout; not newest first,
	   which would reshuffle the board every time a publisher posts and leave a
	   reader hunting for the tile they read yesterday. Each tile names its
	   publisher above its own name instead, because on this pane no caption
	   sits over it to say whose it is.
-->
<template>
	<section class="epi-inicio">
		<PaneHeader :title="paneName('inicio')" />

		<!--
			auto-fit rather than a fixed column count: the six tiles fill as many
			columns as the width allows and wrap into as many rows as that leaves.
			Adding a sector is adding a registry entry (src/panes.js), which is
			what makes this pane scale without a redesign.
		-->
		<div class="epi-inicio__sources">
			<article v-for="tile in tiles"
				:key="tile.id"
				class="epi-card"
				:class="{ 'epi-card--degraded': tile.feed.degraded }">
				<div class="epi-card__head">
					<div class="epi-card__name">
						<p class="epi-card__eyebrow">{{ tile.publisher }}</p>
						<h2 class="epi-card__title">{{ tile.name }}</h2>
					</div>
					<!-- Only when there is something to report. With neither a degraded flag
					     nor a fetched_at, this rendered a bare 6px green dot and no text:
					     colour asserting health nothing had measured, and silence to a
					     screen reader. -->
					<span v-if="tile.feed.degraded || tile.feed.fetched_at"
						class="epi-fresh"
						:class="{ 'epi-fresh--stale': tile.feed.degraded }">
						<span class="epi-fresh__dot" />
						<template v-if="tile.feed.degraded">{{ t('epidemiologia', 'sin contacto') }}</template>
						<Procedencia v-else-if="tile.feed.fetched_at" :fetched-at="tile.feed.fetched_at" />
					</span>
				</div>

				<p v-if="tile.feed.degraded" class="epi-card__warn">
					{{ tile.degradedText }}
				</p>

				<!-- Zero items with no degraded flag is a real answer, not a fault.
				     A set with no alertas vigentes in it is the publisher's to publish,
				     and an empty answer must not look like the warning above, or the
				     warning stops meaning anything. Zero items WITH the flag is neither,
				     and the warning is all the tile says. -->
				<NcEmptyContent v-if="!tile.feed.items.length && !tile.feed.degraded"
					class="epi-card__empty"
					:name="t('epidemiologia', 'Sin alertas')"
					:description="tile.emptyText">
					<template #icon>
						<CheckCircle :size="20" />
					</template>
				</NcEmptyContent>

				<ul v-else-if="tile.feed.items.length" class="epi-card__list">
					<li v-for="item in tile.feed.items.slice(0, PER_CARD)" :key="item.url" class="epi-row">
						<!-- No truncation. The publishers put the useful half at the END of
						     the title, so an ellipsis removes exactly what a reader needed —
						     the same reasoning #35 recorded for the ISP pane. Showing one
						     whole title beats showing six prefixes. The day where the
						     publisher gives one, the month where it does not (MINSAL). -->
						<a :href="item.url"
							target="_blank"
							rel="noopener noreferrer">{{ item.title }}</a>
						<span class="epi-row__when">{{ item.date ? fecha(item.date) : mes(item.month) }}</span>
					</li>
				</ul>

				<NcButton variant="tertiary"
					class="epi-card__more"
					:aria-label="tile.ariaMoreText"
					@click="$emit('open', tile.id)">
					{{ tile.moreText }}
				</NcButton>
			</article>
		</div>

		<article class="epi-card">
			<div class="epi-card__head">
				<h2 class="epi-card__title">{{ paneName('informe') }}</h2>
				<NcButton variant="tertiary"
					:aria-label="t('epidemiologia', 'Ver todos los informes')"
					@click="$emit('open', 'informe')">
					{{ t('epidemiologia', 'Ver todos') }}
				</NcButton>
			</div>

			<!-- Degraded first (L3-16): an informe card with nothing in it used to
			     assert "El ISP no ha publicado el informe de esta semana" for a
			     fuente the page had not managed to READ — a publication claim
			     about a publisher nobody asked. The alertas card above has had
			     this branch since it existed; the informe card was the drift. -->
			<p v-if="reports.degraded" class="epi-card__warn">
				{{ degradedText(FUENTES.isp.name) }}
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t } from '@nextcloud/l10n'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import PaneHeader from './PaneHeader.vue'
import Procedencia from './Procedencia.vue'
import { PANES, paneName } from '../panes.js'
import { reports, source } from '../state.js'
import { degradedText, emptyText, fecha, mes, FUENTES } from '../pure.js'

defineEmits(['open'])

/**
 * ONE alert per card, shown whole.
 *
 * What the pane is for is the latest thing each fuente published, complete. Six
 * abbreviated titles were six rows of "OFICIO CP N°15844 / 2026 Actualización
 * de…" — the same prefix repeated, with the disease name cut off every time.
 *
 * The trade is count for completeness, decided 2026-08-03: what an oficio
 * instructs a clinic to do is not something to show a prefix of. The rest are
 * one click away, in a pane that shows them in full. "Latest" is the first
 * item: every fuente's list arrives newest first, sorted by the server.
 */
const PER_CARD = 1
const PER_CARD_WEEKS = 3

/**
 * One tile per alert sector: the registry's entries that carry a `source`, in
 * its order, each named as the navigation names it and its publisher as
 * FUENTES does. The informe stays out on purpose, as it always was: it is not
 * an alert sector, and its card below shows the series' weeks, not a latest row.
 *
 * A plain array rather than a computed: everything in it is read once from the
 * initial state, which never changes on this page.
 */
const tiles = PANES.filter((pane) => pane.source).map((pane) => {
	const feed = source(pane.source)
	const fuente = FUENTES[pane.group]

	return {
		id: pane.id,
		name: paneName(pane.id),
		publisher: fuente.caption,
		feed,
		// The count in the publisher's own noun ("Ver 13 alertas vigentes", "Ver 54
		// publicaciones"). A tile with nothing to count still opens its pane: that is
		// where a degraded fuente's notice sends the reader to the publisher's site.
		moreText: feed.items.length
			? t('epidemiologia', 'Ver {cuantas}', { cuantas: fuente.count(feed.items.length) })
			: t('epidemiologia', 'Ver la sección'),
		ariaMoreText: feed.items.length
			? t('epidemiologia', 'Ver {cuantas} de {seccion}', { cuantas: fuente.count(feed.items.length), seccion: paneName(pane.id) })
			: t('epidemiologia', 'Ver la sección {seccion}', { seccion: paneName(pane.id) }),
		emptyText: emptyText(fuente.name),
		degradedText: degradedText(fuente.name),
	}
})
</script>

<style scoped>
/* Every card is as tall as what it holds, at every width, and the pane scrolls
   like every other pane when that is taller than the screen.

   Inicio used to be held to the viewport's height on a desktop, with its cards
   allowed below their content (min-height: 0) so the board never scrolled. That
   held for two cards. With six tiles it cannot: the grid squeezed each card
   shorter than its text, the border stayed where the grid put it, and the text
   ran on over the card below — measured on the lab at 1440×900 and 1100×900
   after 0.11.0, hundreds of pixels on the ISP's long titles. Below 1024px the
   cap had already been released for the same reason (#20); now nothing sets it,
   so no title of any length can leave its card. scripts/layout.sh fails if one
   ever does again.

   Rows are packed to the top rather than stretched: empty space inside a bordered
   card reads as something that failed to load, where the same space below the
   content reads as nothing more to show. */
.epi-inicio {
	display: grid;
	align-content: start;
	gap: calc(var(--default-grid-baseline) * 4);
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
}

.epi-card {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
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

/* The name and the publisher above it, as one flex item of the head: the
   freshness badge beside them aligns with the first line, the publisher's. */
.epi-card__name {
	min-width: 0;
}

/* The publisher, above the name. "Farmacovigilancia" alone does not say whose it
   is, and on this pane no navigation caption sits over the tile to say it. The
   same small, spaced, grey label as a week's "Semana" below, so the two read as
   one kind of thing — a label, never a second title. */
.epi-card__eyebrow {
	margin: 0;
	font-size: var(--font-size-small);
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	font-weight: var(--font-weight-heading);
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
}

.epi-card__more {
	margin-top: auto;
	align-self: flex-start;
}

/* The date sits under the title at every width. Beside it, "23 de septiembre de
   2026" took ~150px of a ~330px tile, and the ISP's all-caps titles broke inside
   their words (FARMACOVIGILAN / CIA) in the column that was left. Stacked, the
   title gets the tile's full width back — the rule a phone already had (#20). */
.epi-row {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 0.5);
	min-height: var(--default-clickable-area);
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-element);
}

.epi-row:hover {
	background: var(--color-background-hover);
}

/* A tile is a summary: its latest title whole, at the small size, and the pane
   it opens at full size. At the body size the ISP's 170-character titles made
   their tiles twice as tall as their neighbours. */
.epi-row a {
	flex: 1;
	min-width: 0;
	font-size: var(--font-size-small);
	line-height: 1.4;
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

</style>
