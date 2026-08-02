<template>
	<section>
		<PaneHeader :title="t('epidemiologia', 'Informe semanal de vigilancia ETI e IRAG')"
			:fetched-at="reports.fetched_at" />

		<DegradedNotice v-if="reports.degraded" />

		<template v-if="latest">
			<!--
				The page's single accent, and deliberately not a component: none of the
				99 in @nextcloud/vue is a hero-number card, and NcNoteCard is the
				*warning* component — already used above for "problemas técnicos", so
				reusing its shape here would give two identical cards opposite meanings.

				Every value below is a token. The number is 2.6em on the 15px base
				rather than a fixed 40px, so it moves when the theme moves.
			-->
			<div class="epi-week">
				<p class="epi-week__se">
					<small>{{ t('epidemiologia', 'Semana epi.') }}</small>
					{{ latest.se || '—' }}
				</p>
				<div class="epi-week__body">
					<strong>{{ latest.title }}</strong>
					<NcButton variant="secondary" :href="latest.url" target="_blank">
						<template #icon>
							<FilePdfBox :size="20" />
						</template>
						{{ t('epidemiologia', 'Abrir el informe (PDF)') }} ↗
					</NcButton>
				</div>
			</div>

			<details v-if="earlier.length" class="epi-archive">
				<summary>
					{{ n('epidemiologia', 'Semana anterior de este año', 'Semanas anteriores de este año (%n)', earlier.length) }}
				</summary>
				<ul class="epi-list">
					<NcListItem v-for="report in earlier"
						:key="report.url"
						one-line
						:name="report.title"
						:href="report.url"
						target="_blank"
						:details="report.se ? `SE ${report.se}` : ''" />
				</ul>
			</details>
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
import { computed } from 'vue'
import { NcButton, NcEmptyContent, NcListItem } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PaneHeader from './PaneHeader.vue'
import DegradedNotice from './DegradedNotice.vue'
import { reports } from '../state.js'

const latest = computed(() => reports.items[0] ?? null)
const earlier = computed(() => reports.items.slice(1))
</script>

<style scoped>
.epi-week {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 5);
	flex-wrap: wrap;
	padding: calc(var(--default-grid-baseline) * 5) calc(var(--default-grid-baseline) * 6);
	border-radius: var(--border-radius-container);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.epi-week__se {
	margin: 0;
	font-size: 2.6em;
	font-weight: var(--font-weight-heading);
	line-height: 1;
	font-variant-numeric: tabular-nums;
}

.epi-week__se small {
	display: block;
	font-size: 0.27em;
	font-weight: var(--font-weight-heading);
	letter-spacing: 0.08em;
	text-transform: uppercase;
	opacity: 0.85;
}

.epi-week__body {
	flex: 1;
	min-width: 220px;
}

.epi-week__body strong {
	display: block;
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	font-weight: var(--font-weight-heading);
}

.epi-archive {
	margin-top: calc(var(--default-grid-baseline) * 6);
	border-top: 1px solid var(--color-border);
	padding-top: calc(var(--default-grid-baseline) * 4);
}

.epi-archive summary {
	cursor: pointer;
	font-weight: var(--font-weight-heading);
}

.epi-list {
	list-style: none;
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	padding: 0;
}
</style>
