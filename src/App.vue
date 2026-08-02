<!--
	One NcButton and one line of state. Deliberately trivial: this component's job
	is to prove that @nextcloud/vue renders, that its styles arrive, and that
	IInitialState reached the browser — nothing more. The rewrite replaces it.
-->
<template>
	<div class="epi-toolchain">
		<NcButton variant="primary" @click="seen = true">
			<template #icon>
				<Check :size="20" />
			</template>
			{{ seen ? 'El componente responde' : 'Púlsame' }}
		</NcButton>
		<p class="epi-toolchain__state">
			<template v-if="probe">
				IInitialState llegó: <code>{{ probe }}</code>
			</template>
			<template v-else>
				IInitialState NO llegó — el bundle está viejo o el controlador ya no lo provee.
			</template>
		</p>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { NcButton } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'

defineProps({
	probe: { type: String, default: null },
})

const seen = ref(false)
</script>

<style scoped>
.epi-toolchain {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	flex-wrap: wrap;
	margin-bottom: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-container);
}

.epi-toolchain__state {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
