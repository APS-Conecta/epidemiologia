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
				<NcAppNavigationItem v-for="pane in panes"
					:key="pane.id"
					:name="pane.name"
					:active="current === pane.id"
					@click="current = pane.id">
					<template #icon>
						<component :is="pane.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<NcAppNavigationList v-for="group in groups"
				:key="group.id"
				:aria-labelledby="`epi-nav-${group.id}`">
				<NcAppNavigationCaption :name="group.name" :heading-id="`epi-nav-${group.id}`" />
				<NcAppNavigationItem v-for="pane in group.panes"
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
				<NcAppNavigationItem :name="ispReport.name"
					:active="current === ispReport.id"
					@click="current = ispReport.id">
					<template #icon>
						<component :is="ispReport.icon" :size="20" />
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
			<!-- Around `.epi`, not inside it. The panes are selected as `.epi > section`
			     by scripts/layout.mjs, and `.epi:has(> .epi-inicio…)` below sizes Inicio
			     to the viewport — both are direct-child rules, so the boundary stays
			     outside the element they are written about rather than relying on a
			     fragment root staying transparent. -->
			<ErrorBoundary>
				<div class="epi">
					<!--
						v-show, never v-if. The tablero pane holds an iframe whose Power BI
						report takes 15–20 s to paint; unmounting it on every tab switch would
						re-pay that each time. This is the single easiest thing to get wrong here.
					-->
					<InicioPane v-show="current === 'inicio'" @open="open" />
					<AlertsPane v-show="current === 'alertas'" />
					<!-- (The IspPane mount that stood here is deleted with its
					     fuente — ADR-0016. Five panes: Inicio, Alertas vigentes,
					     the informe, the tablero, EPIVIGILA — the count
					     scripts/layout.mjs waits on.) -->
					<ReportPane v-show="current === 'informe'" />
					<TableroPane v-show="current === 'tablero'" :mounted="tableroSeen" />
					<EpivigilaPane v-show="current === 'epivigila'" />
				</div>
			</ErrorBoundary>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { markRaw, ref, watch } from 'vue'
import {
	NcAppContent,
	NcAppNavigation,
	NcAppNavigationCaption,
	NcAppNavigationItem,
	NcAppNavigationList,
	NcContent,
} from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import Alert from 'vue-material-design-icons/Alert.vue'
import ChartBellCurve from 'vue-material-design-icons/ChartBellCurve.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import Home from 'vue-material-design-icons/Home.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import { paneName } from './panes.js'
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
 * Inicio first, then the MINSAL group, then the ISP list.
 *
 * ONE caption per group, and it groups by publisher — not by pane type, and not by
 * whether a row narrows an archive or opens a whole pane. There used to be three
 * captions, and the third grouped by function: "Notificación" over EPIVIGILA.
 * EPIVIGILA is epivigila.minsal.cl, so under the publisher rule it belongs to
 * MINSAL, and moving it there leaves one rule instead of two. The ISP's rows used
 * to label themselves, on the grounds that a caption spending 38px on a single row
 * is chrome rather than structure; #52 made it six rows and the caption arrived.
 * The six retired with the alertas sanitarias (ADR-0016) and the caption stayed
 * over the one row left: that row's own name — the ISP's title for its weekly
 * report — does not say the institution, so the 38px buys the one word the list
 * would otherwise lose.
 *
 * The informe moved from this MINSAL group to the ISP list when its fuente did
 * (Sources::name(REPORTS)): the rule is by publisher, so a pane stays only as long
 * as its publisher does, same as Sources itself and panes.js's title for it.
 *
 * Names come from panes.js. This file used to hold one wording and each pane
 * another; see ADR-0008 for why the groups do not collapse.
 *
 * No counters. Nextcloud uses a counter bubble for what needs action, and neither
 * of ours is that: MINSAL's 75 holds until it republishes, and the ISP's series
 * grows one week at a time. A number that never means "act on me" is
 * decoration that trains people to ignore the one place a real count will one day
 * appear. Each pane states its own count where the number can change — the alertas
 * toolbar as you filter, the informe header as the series grows — and therefore
 * means something.
 */
const pane = (id, icon) => ({ id, name: paneName(id), icon: markRaw(icon) })

/** Ungrouped, above the caption. */
const panes = [pane('inicio', Home)]

const groups = [
	{
		id: 'minsal',
		name: t('epidemiologia', 'MINSAL'),
		panes: [
			pane('alertas', Alert),
			pane('tablero', ChartBox),
			pane('epivigila', ShieldAccount),
		],
	},
]

/**
 * The ISP's own weekly report, and the ISP list's whole content since the
 * alertas sanitarias retired (ADR-0016): a plain row rather than a caption of
 * its own, because the caption above it already says the institution — the
 * same reasoning that holds for the MINSAL group's rows holds for this one.
 */
const ispReport = pane('informe', ChartBellCurve)

/*
 * (FAMILY_ICONS, ispEntries, `ispFamily` and `openIsp()` are deleted with the
 * rows they served: the families were narrowings of the ISP's alert archive,
 * and the archive retired with the alertas sanitarias (ADR-0016). Nothing else
 * reads familias.js, which this slice deletes with its two pinning suites.)
 */

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
