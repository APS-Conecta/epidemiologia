<!--
	Shared by every pane that shows a list. Extracted rather than duplicated
	because this is the only signal a clinician gets that the list in front of them
	may be incomplete — two copies of that wording would eventually disagree.

	It used to name MINSAL in its own template, which is exactly why the ISP pane
	wrote a second copy and Inicio a third and fourth. The fuente is a prop now, and
	the sentence lives in state.js so there is one of it.
-->
<template>
	<NcNoteCard type="warning">
		<strong>{{ t('epidemiologia', 'Problemas técnicos.') }}</strong>
		{{ degradedText(source) }}
		<a :href="url" target="_blank" rel="noopener noreferrer">
			{{ t('epidemiologia', 'Ver en el sitio oficial') }} ↗
		</a>
	</NcNoteCard>
</template>

<script setup>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { t } from '@nextcloud/l10n'
import { degradedText } from '../state.js'

defineProps({
	/** How the fuente is named mid-sentence: "MINSAL", "el ISP". */
	source: { type: String, required: true },
	url: { type: String, required: true },
})
</script>
