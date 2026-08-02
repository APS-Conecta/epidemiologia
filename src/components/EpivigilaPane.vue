<template>
	<section>
		<PaneHeader :title="t('epidemiologia', 'EPIVIGILA · Notificación de ENO')">
			{{ t('epidemiologia', 'Un enlace al sistema oficial. No se incrusta, y aquí no se guarda nada.') }}
		</PaneHeader>

		<div class="epi-what">
			<strong>{{ t('epidemiologia', 'Qué es.') }}</strong>
			{{ t('epidemiologia', 'La plataforma del MINSAL donde se notifican las Enfermedades de Notificación Obligatoria. Cada profesional entra con su clave única personal, y los datos de pacientes viven allá — nunca en esta intranet.') }}
		</div>

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

		<NcNoteCard type="warning">
			<strong>{{ t('epidemiologia', 'Acceso restringido.') }}</strong>
			{{ t('epidemiologia', 'El MINSAL mantiene EPIVIGILA en funcionamiento excepcional: solo ingresan los establecimientos públicos con conexión a Red MINSAL. Si no puedes entrar, notifica con los formularios PDF a la SEREMI de tu región.') }}
			<p class="epi-source">
				{{ t('epidemiologia', 'Fuente: Colegio Médico de Chile, 1 de julio de 2026 · verificado el 1 de agosto de 2026') }}
			</p>
		</NcNoteCard>

		<p class="epi-source">
			{{ t('epidemiologia', 'Por qué no va incrustado: EPIVIGILA es un formulario de acceso con credenciales. Mostrarlo dentro de esta intranet le enseñaría al personal a escribir su clave del MINSAL en una ventana que no es del MINSAL.') }}
		</p>
	</section>
</template>

<script setup>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'
import PaneHeader from './PaneHeader.vue'
</script>

<style scoped>
.epi-what {
	padding: calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-background-hover);
	line-height: 1.6;
}

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
