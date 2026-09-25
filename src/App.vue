<template>
	<NcContent app-name="epidemiologia">
		<!--
			aria-label, because the library warns without it and Files sets one
			("Archivos"). Measured on the instance in #26: ours was null, which is
			the one unambiguous defect that ticket found.
		-->
		<NcAppNavigation :aria-label="t('epidemiologia', 'Epidemiología')">
			<!--
				The DEFAULT slot, not `#list`, and that is forced rather than chosen.
				`#list` wraps its content in one NcAppNavigationList — a single <ul> —
				so several captioned lists inside it produce `ul > ul`, which is invalid
				and destroys list semantics. Measured on the instance before this was
				fixed; it is the same class of defect #14 closed. `#list` is for one flat
				list. Files uses the default slot too (#26).

				Each caption is the first <li> of the list it labels, because a caption
				without `is-heading` renders an <li> and an <li> loose in the body <div>
				is equally invalid. It labels its own list through aria-labelledby.

				Not `is-heading`: that renders h{max(2, level)} and the navigation
				precedes the content, so the captions would sit at level 2 immediately
				above the content's level 1 — the level-skip audit tools flag. ADR-0005
				records the cost: a screen-reader user loses H-key jumping between groups.
			-->
			<NcAppNavigationList :aria-label="t('epidemiologia', 'Vistas')">
				<NcAppNavigationItem v-for="pane in top"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<!-- The registry's 'minsal' group, rendered under one caption because
			     the rows share a publisher (ADR-0008) — see src/panes.js. -->
			<NcAppNavigationList :aria-labelledby="'epi-nav-minsal'">
				<NcAppNavigationCaption :name="t('epidemiologia', 'MINSAL')" heading-id="epi-nav-minsal" />
				<NcAppNavigationItem v-for="pane in minsal"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<!-- The ISP, in its own list because an <li> loose in this <div> is as
			     invalid as a nested <ul> — ADR-0005 measured that.
			     The caption predates the retirement: it arrived when this list
			     gained the alertas sanitarias' six rows, because one row could
			     name its own publisher and six could not each repeat it (#52).
			     Those rows retired with the fuente (ADR-0016), and the caption
			     stays over the row that is left — the informe — for the same
			     reason it was needed at six: the row's own name does not say who
			     publishes it. "Circulación de Virus Respiratorios" is the ISP's
			     own title for its weekly report (Sources::name(REPORTS)), so the
			     institution is this caption's one job now.
			     Measured on the built app 2026-08-05, not taken from the mockup:
			     all the lists together are 538px, in a column of 682px at 360×740
			     and more above that — measured with more rows in this list than
			     any list carries now, since the retirement only removed them. It
			     fits, so ADR-0008 stands and the groups do not collapse.
			     scripts/layout.mjs check 7 asserts it at every viewport, because
			     a new row is exactly the kind of thing a comment cannot notice. -->
			<NcAppNavigationList :aria-labelledby="'epi-nav-isp'">
				<NcAppNavigationCaption :name="t('epidemiologia', 'ISP')" heading-id="epi-nav-isp" />
				<!-- The list's only row: a plain pane row, the same shape as the
				     MINSAL group's items, because this list groups by publisher and
				     the informe's publisher is the ISP (Sources::name(REPORTS)) —
				     not by whether a row narrows an archive, a kind of row this app
				     no longer has. -->
				<NcAppNavigationItem v-for="pane in isp"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<!--
				The footer is what gives the column a bottom. #26 measured four items
				totalling 164px floating in a 562px column, against Files anchoring its
				own with a search field above and settings below — that emptiness, not
				the slot, is what read as "not a Nextcloud app". It carries the source
				attribution for BOTH publishers we read.

				It named only MINSAL until 2026-08-03, while half the app was the ISP's,
				and cited ADR-0004 for the obligation — an ADR about the DEIS REM
				extracts, which this app never fetches. It reads degreyd.minsal.cl and
				ispch.gob.cl and nothing else — the MINSAL host this once named has
				been unreachable from every network since October 2025, which is why
				both fuentes that used to read it were repointed. The reason to credit
				them is simply that neither publishes under a licence and both are
				named on every screen we built out of their work.

				The credit, and not a link beside it: every pane already links to the
				source it came from, on the row that came from it. A second, permanent
				link to the site as a whole is the same fact a fifth time.
			-->
			<template #footer>
				<footer class="epi-nav-footer">
					{{ t('epidemiologia', 'Fuentes: Departamento de Epidemiología (MINSAL) e Instituto de Salud Pública') }}
				</footer>
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="t('epidemiologia', 'Epidemiología')">
			<div class="epi">
				<!--
					v-show, never v-if — the tablero pane holds an iframe whose Power BI
					report takes 15–20 s to paint; unmounting it on every tab switch would
					re-pay that each time.

					One boundary PER PANE (L3-21): every pane is v-show-mounted together on
					first paint, so a fault while drawing one must not take the other four
					down with it — four panes survive the fifth's fault. ErrorBoundary
					renders no wrapper element of its own, so each pane's <section>
					stays a direct child of `.epi`: layout.mjs's `.epi > section` count and
					the `:has()` sizing rule below match unchanged, and v-show on the
					boundary lands on that same section (the component's root element).
				-->
				<ErrorBoundary v-for="pane in paneComponents"
					:key="pane.id"
					v-show="current === pane.id">
					<!-- `mounted` bound IN-TEMPLATE, not through a v-bind object
					     (F5): nested refs are not unwrapped in v-bind objects,
					     and the tablero's first-open latch would read as
					     always-truthy. The template's render proxy unwraps
					     `tableroSeen` here. -->
					<component :is="pane.component"
						:mounted="pane.id === 'tablero' ? tableroSeen : undefined"
						@open="open" />
				</ErrorBoundary>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { markRaw, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcContent from '@nextcloud/vue/components/NcContent'
import { t } from '@nextcloud/l10n'
import Alert from 'vue-material-design-icons/Alert.vue'
import ChartBellCurve from 'vue-material-design-icons/ChartBellCurve.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import Home from 'vue-material-design-icons/Home.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import { PANES, paneName } from './panes.js'
import ErrorBoundary from './components/ErrorBoundary.vue'
import InicioPane from './components/InicioPane.vue'
import AlertsPane from './components/AlertsPane.vue'
import ReportPane from './components/ReportPane.vue'
import TableroPane from './components/TableroPane.vue'
import EpivigilaPane from './components/EpivigilaPane.vue'

const current = ref('inicio')

// Latches on first open and never resets, so the iframe mounts exactly once.
const tableroSeen = ref(false)
watch(current, (pane) => {
	if (pane === 'tablero') {
		tableroSeen.value = true
	}
})

/** The registry's icon names → the icon components (names, so panes.js stays node-loadable — L3-18). */
const ICONS = {
	home: markRaw(Home),
	alert: markRaw(Alert),
	'chart-box': markRaw(ChartBox),
	'chart-bell-curve': markRaw(ChartBellCurve),
	'shield-account': markRaw(ShieldAccount),
}

// Declared above the derived lists — the consts below call it at module eval.
const paneIcon = (id) => PANES.find((p) => p.id === id).icon

const entry = (id) => ({ id, name: paneName(id), icon: ICONS[paneIcon(id)] })

/*
 * Inicio first, then the MINSAL group, then the ISP list — all three derived
 * from the registry (L3-18), so the navigation and the pane count can no
 * longer disagree about what exists.
 *
 * ONE caption per group, and it groups by publisher (ADR-0008) — the rule's
 * whole story lives in src/panes.js's registry docblock and the caption
 * comments above. Names come from panes.js; no counters (a number that never
 * means "act on me" is decoration that trains people to ignore the one place
 * a real count will one day appear — each pane states its own count where the
 * number can change).
 */
const top = PANES.filter((p) => p.group === 'top').map((p) => entry(p.id))
const minsal = PANES.filter((p) => p.group === 'minsal').map((p) => entry(p.id))
const isp = PANES.filter((p) => p.group === 'isp').map((p) => entry(p.id))

/*
 * The panes the content area renders, in registry order: the registry cannot
 * hold `.vue` imports and stay node-loadable, so the component map lives here
 * and the panes div walks this list through per-pane boundaries (L3-21).
 */
const COMPONENTS = {
	inicio: markRaw(InicioPane),
	alertas: markRaw(AlertsPane),
	informe: markRaw(ReportPane),
	tablero: markRaw(TableroPane),
	epivigila: markRaw(EpivigilaPane),
}

const paneComponents = PANES.map((p) => ({ id: p.id, component: COMPONENTS[p.id] }))

/**
 * Opening a pane by name always shows that pane WHOLE.
 *
 * The ispFamily-clearing guard this carried died with the only pane that held
 * navigation-owned filter state. Every pane is whole by construction now, so
 * the handler is a plain assignment — and the property it guarded needs no
 * guard: there is nothing left to clear.
 */
function open(pane) {
	current.value = pane
}
</script>

<style scoped>
/*
	No font sizes and no raw pixels. core/css/styles.scss already sets body to
	--default-font-size with --default-line-height, and apps.scss sets h2 to 1.8em;
	the old stylesheet fought both, which is why the app looked wrong beside
	official ones. Everything here is a token or a multiple of the grid baseline.
*/
/*
	The left inset clears the navigation toggle, and that is the whole reason it is
	not a round number.

	`.app-navigation-toggle-wrapper` is `position: absolute; left: 308px` INSIDE the
	navigation, so the button floats over the first ~42px of the content area — and
	it is not a child of `main`, so nothing in a pane can push it out of the way.
	At 32px of inset it sat on top of the first letter of every pane heading:
	`elementFromPoint` at the "I" of "Informe" returned the toggle's icon, not the
	text. Files leaves the same corner clear, which is why its toolbar begins after
	the hamburger rather than under it.

	One clickable area plus FOUR baselines, not two: two clears the button by a
	single pixel, which both looks cramped and would collide again the moment the
	platform nudges that wrapper. Four leaves ~9px of air.
*/
.epi {
	max-width: 1100px;
	padding: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 8)
		calc(var(--default-grid-baseline) * 10)
		calc(var(--default-clickable-area) + var(--default-grid-baseline) * 4);
}

/* Inicio fills the pane instead of scrolling it, so its grid can size to the
   viewport. The other panes keep their own scroll. */
.epi:has(> .epi-inicio:not([style*="display: none"])) {
	display: flex;
	flex-direction: column;
	height: 100%;
	max-width: none;
	padding-bottom: calc(var(--default-grid-baseline) * 6);
}

/* ...but only above the mobile breakpoint. "Fills the pane" means a height fixed
   to the viewport, and below 1024px InicioPane releases its cards to be sized by
   their content instead of by the grid row. Keeping the cap as well gives cards
   that are taller than the box holding them, so they draw on top of each other —
   measured on the instance for #20. Released here rather than in InicioPane
   because this rule is App.vue's, and a component cannot unset its parent. */
@media (max-width: 1024px) {
	.epi:has(> .epi-inicio:not([style*="display: none"])) {
		height: auto;
	}
}

/*
 * (.epi-nav-noicon is deleted with the family rows it aligned: the empty box
 * existed so `otros` — the one family with no glyph (ADR-0013) — would not
 * pull its label left against the four rows above it. No rows, no box.)
 */

.epi-nav-footer {
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
