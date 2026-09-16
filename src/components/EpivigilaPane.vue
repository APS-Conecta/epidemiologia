<!--
	A door, not a page. Everything here exists to get someone into EPIVIGILA.

	It used to carry a second button to the PDF forms that replace EPIVIGILA when Red
	MINSAL will not have a clinician, on the same dead MINSAL host App.vue's footer
	comment names. That host returns 403 to every network and has since October 2025,
	and no equivalent page was found on www.minsal.cl, degreyd.minsal.cl or
	deis.minsal.cl when the fuentes moved — so the button is gone rather than pointed
	somewhere that 403s. The NcNoteCard below still tells a clinician what to do, but
	said so as "notifica con los formularios PDF a la SEREMI" for a full review round
	after the button was already gone — an instruction that outlived its own means,
	because nothing left in the pane said where those forms came from. Reworded so it
	names the SEREMI as where notification still goes and the forms as the SEREMI's
	own, without implying this pane hands one over.

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
			{{ t('epidemiologia', 'Solo ingresan los establecimientos públicos con conexión a Red MINSAL. Si no puedes entrar, notifica a la SEREMI: sus formularios PDF de notificación obligatoria no se distribuyen aquí.') }}
		</NcNoteCard>

		<div class="epi-links">
			<NcButton variant="primary" href="https://epivigila.minsal.cl/" target="_blank"
				rel="noopener noreferrer">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ t('epidemiologia', 'Abrir EPIVIGILA') }}
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
