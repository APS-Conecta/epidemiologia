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
				<NcAppNavigationCaption :name="FUENTES.minsal.caption" heading-id="epi-nav-minsal" />
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
			     The caption arrived when this list gained the alertas sanitarias'
			     six rows, because one row could name its own publisher and six
			     could not each repeat it (#52). Those rows retired with the fuente
			     (ADR-0016); the list holds four again — the informe and three of
			     the ISP's alert categories — and not one of their names says who
			     publishes it, so the institution is still this caption's one job.
			     Whether the rows fit the column is scripts/layout.mjs check 7's to
			     say, at every viewport: a new row is exactly the kind of thing a
			     comment cannot notice, and the last number written here (538px in
			     682px at 360×740, 2026-08-05) measured a different set of rows. -->
			<NcAppNavigationList :aria-labelledby="'epi-nav-isp'">
				<NcAppNavigationCaption :name="FUENTES.isp.caption" heading-id="epi-nav-isp" />
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

			<!-- The OPS/OMS: the third publisher, under a caption of its own like the
			     two above (ADR-0008) and written out the same way. Its one row names
			     what the list holds; the caption names whose it is. -->
			<NcAppNavigationList :aria-labelledby="'epi-nav-ops'">
				<NcAppNavigationCaption :name="FUENTES.ops.caption" heading-id="epi-nav-ops" />
				<NcAppNavigationItem v-for="pane in ops"
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
				attribution for EVERY publisher we read.

				It named only MINSAL until 2026-08-03, while half the app was the ISP's,
				and cited ADR-0004 for the obligation — an ADR about the DEIS REM
				extracts, which this app never fetches. It credits the three
				institutions whose pages the fuentes read: two of MINSAL's, the ISP's
				and the OPS/OMS's (src/pure.js's SITIOS lists each page a reader is
				sent to). The credit is owed whatever the licence: every screen here
				is built out of their work.

				The credit, and not a link beside it: every pane already links to the
				source it came from, on the row that came from it. A second, permanent
				link to the site as a whole is the same fact a fifth time.
			-->
			<template #footer>
				<footer class="epi-nav-footer">
					{{ t('epidemiologia', 'Fuentes: MINSAL, Instituto de Salud Pública y OPS/OMS') }}
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
					first paint, so a fault while drawing one must not take the others
					down with it — the rest survive any one pane's fault. ErrorBoundary
					renders no wrapper element of its own, so each pane's <section>
					stays a direct child of `.epi`: layout.mjs's `.epi > section` count and
					the `:has()` sizing rule below match unchanged. v-show rides the pane
					component, never the boundary: ErrorBoundary renders its slot as a
					fragment, and Vue silently drops directives on a component whose root
					is not an element (prod strips the warning); on the pane component the
					directive lands on its root <section> — an element — and works. Each
					pane component must keep exactly one element root for this to hold.
				-->
				<ErrorBoundary v-for="pane in paneComponents"
					:key="pane.id">
					<!-- `mounted` bound IN-TEMPLATE, not through a v-bind object
					     (F5): nested refs are not unwrapped in v-bind objects,
					     and the tablero's first-open latch would read as
					     always-truthy. The template's render proxy unwraps
					     `tableroSeen` here. `pane` tells the six alert sectors
					     apart, all one AlertsPane; undefined, like `mounted`,
					     renders no attribute on the panes that take neither. -->
					<component :is="pane.component"
						v-show="current === pane.id"
						:pane="pane.sector ? pane.id : undefined"
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
import ClipboardPulse from 'vue-material-design-icons/ClipboardPulse.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import Home from 'vue-material-design-icons/Home.vue'
import Lipstick from 'vue-material-design-icons/Lipstick.vue'
import Pill from 'vue-material-design-icons/Pill.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import Virus from 'vue-material-design-icons/Virus.vue'
import { PANES, paneName } from './panes.js'
import { FUENTES } from './pure.js'
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

/**
 * The registry's icon names → the icon components (names, so panes.js stays
 * node-loadable — L3-18). A name missing here renders no glyph and no error,
 * so panes.test.js reads this map for every name the registry uses.
 *
 * The sector glyphs say what each list holds, not how it feels (ADR-0013's
 * rule): a virus for the Departamento de Epidemiología's outbreak alerts, a
 * pill for ANAMED's medicines — ADR-0013's own glyph for them — a pulse on a
 * clipboard for pharmacovigilance's watch over them, a lipstick for cosmetics
 * (ADR-0013's again), and the globe for the one publisher that is not Chile's.
 */
const ICONS = {
	home: markRaw(Home),
	alert: markRaw(Alert),
	virus: markRaw(Virus),
	'chart-box': markRaw(ChartBox),
	'chart-bell-curve': markRaw(ChartBellCurve),
	'shield-account': markRaw(ShieldAccount),
	pill: markRaw(Pill),
	'clipboard-pulse': markRaw(ClipboardPulse),
	lipstick: markRaw(Lipstick),
	earth: markRaw(Earth),
}

// Declared above the derived lists — the consts below call it at module eval.
const paneIcon = (id) => PANES.find((p) => p.id === id).icon

const entry = (id) => ({ id, name: paneName(id), icon: ICONS[paneIcon(id)] })

/*
 * Inicio first, then the MINSAL group, the ISP list and the OPS/OMS's — all
 * derived from the registry (L3-18), so the navigation and the pane count can
 * no longer disagree about what exists.
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
const ops = PANES.filter((p) => p.group === 'ops').map((p) => entry(p.id))

/*
 * The panes the content area renders, in registry order: the registry cannot
 * hold `.vue` imports and stay node-loadable, so the component map lives here
 * and the panes div walks this list through per-pane boundaries (L3-21).
 * Every alert sector (a registry entry with a `source`) is the one AlertsPane,
 * so the map names only the panes that are a component of their own.
 */
const COMPONENTS = {
	inicio: markRaw(InicioPane),
	informe: markRaw(ReportPane),
	tablero: markRaw(TableroPane),
	epivigila: markRaw(EpivigilaPane),
}

const SECTOR = markRaw(AlertsPane)

const paneComponents = PANES.map((p) => ({
	id: p.id,
	sector: p.source !== undefined,
	component: p.source !== undefined ? SECTOR : COMPONENTS[p.id],
}))

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

/* Inicio takes the pane's whole width for its board of tiles. Not its height:
   it used to be held to the viewport so the board never scrolled, and with six
   tiles that squeezed cards shorter than their text, which then ran over the
   card below (#20 measured the same below 1024px). It scrolls like every other
   pane now — see InicioPane's .epi-inicio. */
.epi:has(> .epi-inicio:not([style*="display: none"])) {
	max-width: none;
	padding-bottom: calc(var(--default-grid-baseline) * 6);
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
