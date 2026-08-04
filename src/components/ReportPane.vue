<!--
	Every informe, one row each, newest first.

	It used to be a hero card for the current week plus 28 collapsed rows. Measured
	against the live data: all 29 titles are the SAME seven words — "Actualización
	Informe Influenza SE N Año N", 29 of 29, no exceptions — so the pane printed
	that sentence 29 times and stated the week number twice per row (once inside the
	title, once in a details column) and three times for the current one.

	The semana epidemiológica IS the identity of an informe: CONTEXT.md says one is
	"identified by its semana epidemiológica". So the number leads, the month says
	when, and the title stops being repeated on screen — it stays as each row's
	accessible name, so nothing is lost to a screen reader.

	No hero and no highlight on the current week: the order says which is current,
	and every week is equally openable.
-->
<template>
	<section>
		<!-- Mobile only, as on the alert panes: above the breakpoint the navigation
		     names the pane. PaneHeader stopped taking a freshness prop — that line
		     now sits beside the count, on one row instead of its own. -->
		<PaneHeader class="epi-head--mobile"
			:title="t('epidemiologia', 'Informe semanal de vigilancia ETI e IRAG')" />

		<DegradedNotice v-if="reports.degraded" source="MINSAL" url="https://epi.minsal.cl/" />

		<template v-if="reports.items.length">
			<p class="epi-count">
				{{ n('epidemiologia', '%n informe', '%n informes', reports.items.length) }}
				<template v-if="reports.fetched_at">
					· {{ t('epidemiologia', 'leído') }}
					<NcDateTime :timestamp="reports.fetched_at * 1000" relative-time="long" />
				</template>
			</p>

			<ul class="epi-weeks">
				<li v-for="report in reports.items" :key="report.url">
					<a class="epi-week"
						:href="report.url"
						:aria-label="report.title"
						target="_blank"
						rel="noopener noreferrer">
						<b class="epi-week__se">{{ report.se || '—' }}</b>
						<span class="epi-week__when">{{ mes(report.month) }}</span>
						<span class="epi-week__pdf">
							<FilePdfBox :size="16" />
							PDF ↗
						</span>
					</a>
				</li>
			</ul>
		</template>

		<NcEmptyContent v-else
			:name="t('epidemiologia', 'Sin informes')"
			:description="t('epidemiologia', 'No hay informes disponibles en este momento.')">
			<template #icon>
				<FileDocumentOutline :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script setup>
import { NcDateTime, NcEmptyContent } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PaneHeader from './PaneHeader.vue'
import DegradedNotice from './DegradedNotice.vue'
import { mes, reports } from '../state.js'
</script>

<style scoped>
.epi-count {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}

.epi-head--mobile {
	display: none;
}

@media (max-width: 1024px) {
	.epi-head--mobile {
		display: block;
	}
}

.epi-weeks {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.epi-week {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 4);
	min-height: var(--default-clickable-area);
	padding: calc(var(--default-grid-baseline) * 1.5) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	color: var(--color-main-text);
	text-decoration: none;
}

.epi-week:hover,
.epi-week:focus-visible {
	background: var(--color-background-hover);
}

/* The number carries the identity, so it is the only thing given weight — and
   tabular figures so 9 and 29 line up down the column. */
.epi-week__se {
	font-size: 1.2em;
	font-weight: var(--font-weight-heading);
	font-variant-numeric: tabular-nums;
	min-width: 2.2em;
}

/* No text-transform. Intl writes "julio de 2026" and Spanish keeps months in
   lower case (RAE); `capitalize` also hit the "de", giving "Julio De 2026". */
.epi-week__when {
	color: var(--color-text-maxcontrast);
}

.epi-week__pdf {
	margin-inline-start: auto;
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
	white-space: nowrap;
}
</style>
