<!--
	A door, not a page. Everything here exists to get someone into EPIVIGILA, or —
	when Red MINSAL will not have them — to the PDF that replaces it.

	The pane carried 878 characters of prose across four blocks: what EPIVIGILA is,
	who may enter, why it is not embedded, and where that was verified. Three of the
	four told a clinician something they already know or do not act on. What is left
	is the one fact that changes what they do (the access restriction and its
	fallback) and the one that is a promise about us (the password is typed on
	MINSAL's site, never here).
-->
<template>
	<section>
		<PaneHeader :title="paneName('epivigila')" />

		<NcNoteCard type="warning">
			{{ t('epidemiologia', 'Solo ingresan los establecimientos públicos con conexión a Red MINSAL. Si no puedes entrar, notifica con los formularios PDF a la SEREMI.') }}
		</NcNoteCard>

		<div class="epi-links">
			<NcButton variant="primary" href="https://epivigila.minsal.cl/" target="_blank">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ t('epidemiologia', 'Abrir EPIVIGILA') }}
			</NcButton>
			<NcButton variant="secondary"
				href="https://epi.minsal.cl/formularios-de-notificacion-obligatoria/"
				target="_blank">
				<template #icon>
					<FilePdfBox :size="20" />
				</template>
				{{ t('epidemiologia', 'Formularios PDF de la SEREMI') }}
			</NcButton>
		</div>

		<p class="epi-source">
			{{ t('epidemiologia', 'Se abre en el sitio del MINSAL: la clave única no se escribe nunca aquí.') }}
		</p>
	</section>
</template>

<script setup>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'
import PaneHeader from './PaneHeader.vue'
import { paneName } from '../panes.js'
</script>

<style scoped>
.epi-links {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
	flex-wrap: wrap;
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.epi-source {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
