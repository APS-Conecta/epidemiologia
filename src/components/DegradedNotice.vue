<!--
	Shared by the alerts and the report pane. Extracted rather than duplicated
	because this is the only signal a clinician gets that the list in front of them
	may be incomplete — two copies of that wording would eventually disagree.
-->
<template>
	<NcNoteCard type="warning">
		<strong>{{ t('epidemiologia', 'Problemas técnicos.') }}</strong>
		{{ t('epidemiologia', 'No hemos podido contactar a MINSAL, así que esta lista puede estar incompleta.') }}
		<a href="https://epi.minsal.cl/" target="_blank" rel="noopener noreferrer">
			{{ t('epidemiologia', 'Ver en epi.minsal.cl') }} ↗
		</a>
	</NcNoteCard>
</template>

<script setup>
import { NcNoteCard } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
</script>
