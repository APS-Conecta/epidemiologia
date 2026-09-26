<!--
	One row in a list of alerts, shared by the two panes that showed them until
	the ISP's retired (ADR-0016).

	It exists because those two panes DIVERGED. The ISP pane rendered its own row
	so titles wrap; the MINSAL pane used NcListItem, which truncates its name to one
	line no matter what — `one-line` does not gate it, the base rule carries nowrap,
	and there is no `name` slot to render into. So at 360px the MINSAL pane showed
	21 characters of a 252-character oficio and six rows read as six identical ones.

	Both fuentes put the useful half at the END of the title — MINSAL after the
	oficio number, the ISP after the product — which is the reasoning #35 already
	recorded for one of them and this applies to both.

	The meta line differs per pane, so it is a slot rather than a prop: what the
	panes share is the row, not what they say about it.
-->
<template>
	<li class="epi-alert">
		<!-- The whole title, always — the only render since the split machinery
		     retired with its last consumer (L7-02; the tags-carrying pane went
		     with ADR-0016). Restorable from git when a tagged pane arrives. -->
		<a class="epi-alert__title" :href="url" target="_blank" rel="noopener noreferrer">{{ title }}</a>
		<p v-if="$slots.meta" class="epi-alert__meta">
			<slot name="meta" />
		</p>
	</li>
</template>

<script setup>
defineProps({
	title: { type: String, required: true },
	url: { type: String, required: true },
})
</script>

<style scoped>
.epi-alert {
	padding: calc(var(--default-grid-baseline) * 2.5) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.epi-alert:hover {
	background: var(--color-background-hover);
}

/*
	Wraps in FULL, on every viewport. No max-width, no clamp, no ellipsis — and
	that is a decision, not an omission.

	Measured with the 75 real alerts: full titles cost 18 screens at 360px against
	12 with a two-line clamp, and on a desktop the difference is under 1% because
	the titles already fit in two lines there. So the clamp is nearly free and was
	proposed on exactly those grounds.

	Rejected 2026-08-03: what is being abbreviated is the subject of an oficio that
	instructs a clinic on what to do about a disease. Someone has to be able to read
	what was issued, not a prefix of it. A list that is long because the content is
	long is honest; a list that is short because it hid the disease name is not.

	If the length ever has to come down, the lever is the boilerplate, not the
	subject: every title opens with the same 25 characters of reference. Moving that
	elsewhere shortens the row while still showing everything.

	overflow-wrap: anywhere, and it is load-bearing. MINSAL titles carry tokens
	like B27_N_1709_REFUERZA_VACUNACION_CONTRA_SARAMPION_2025 — 52 characters with
	no break opportunity in them — so min-content for this row was 505px and the
	pane overflowed a 360px phone by 225. NcListItem never showed that because its
	nowrap + ellipsis hid it; wrapping is what makes it visible.

	`anywhere` rather than `break-word`: only the former shrinks the intrinsic
	minimum, and the intrinsic minimum is precisely what was overflowing.
	Caught by scripts/layout.sh before this reached a commit.
*/
.epi-alert__title {
	color: var(--color-main-text);
	text-decoration: none;
	font-weight: var(--font-weight-heading);
	overflow-wrap: anywhere;
}

.epi-alert__title:hover,
.epi-alert__title:focus-visible {
	text-decoration: underline;
}

.epi-alert__meta {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
	margin: calc(var(--default-grid-baseline) * 1.5) 0 0;
}
</style>
