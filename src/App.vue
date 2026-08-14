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
			     It gained a caption when it gained rows: one row named its own
			     publisher, six families cannot each repeat it (#52).
			     Measured on the built app 2026-08-05, not taken from the mockup: all
			     the lists together are 538px, in a column of 682px at 360×740 and more
			     above that. It fits, so ADR-0008 stands and the groups do not collapse.
			     scripts/layout.mjs check 7 asserts it at every viewport, because a
			     seventh row is exactly the kind of thing a comment cannot notice. -->
			<NcAppNavigationList :aria-labelledby="'epi-nav-isp'">
				<NcAppNavigationCaption :name="t('epidemiologia', 'ISP')" heading-id="epi-nav-isp" />
				<NcAppNavigationItem v-for="entry in ispEntries"
					:key="entry.id ?? 'todas'"
					:name="entry.name"
					:active="current === 'isp' && ispFamily === entry.id"
					@click="openIsp(entry.id)">
					<template #icon>
						<component :is="entry.icon" v-if="entry.icon" :size="20" />
						<!-- `otros` carries no glyph (ADR-0013). The slot reserves no column of
						     its own — checked in the compiled template — so an absent icon pulls
						     that row's label left against the four above it. An empty box of the
						     icon's size asserts nothing and keeps the list aligned. -->
						<span v-else class="epi-nav-noicon" aria-hidden="true" />
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
				extracts, which this app never fetches. It reads epi.minsal.cl and
				ispch.gob.cl and nothing else. The reason to credit them is simply that
				neither publishes under a licence and both are named on every screen we
				built out of their work.

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
					v-show, never v-if. The tablero pane holds an iframe whose Power BI
					report takes 15–20 s to paint; unmounting it on every tab switch would
					re-pay that each time. This is the single easiest thing to get wrong here.
				-->
				<InicioPane v-show="current === 'inicio'" @open="open" />
				<AlertsPane v-show="current === 'alertas'" />
				<!-- Dismissing the family token lands on the whole archive, and the
				     navigation follows because both read `ispFamily`. That is the
				     property #64 lost: one destination, one state, one highlighted row. -->
				<IspPane v-show="current === 'isp'"
					:family="ispFamily"
					@clear-family="ispFamily = null" />
				<ReportPane v-show="current === 'informe'" />
				<TableroPane v-show="current === 'tablero'" :mounted="tableroSeen" />
				<EpivigilaPane v-show="current === 'epivigila'" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { defineAsyncComponent, markRaw, ref, watch } from 'vue'
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
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Home from 'vue-material-design-icons/Home.vue'
import Lipstick from 'vue-material-design-icons/Lipstick.vue'
import Pill from 'vue-material-design-icons/Pill.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import Spray from 'vue-material-design-icons/Spray.vue'
import Stethoscope from 'vue-material-design-icons/Stethoscope.vue'
import { familiasPresent } from './familias.js'
import { paneName } from './panes.js'
import { sanitaryAlerts } from './state.js'
const InicioPane = defineAsyncComponent(() => import('./components/InicioPane.vue'))
const AlertsPane = defineAsyncComponent(() => import('./components/AlertsPane.vue'))
const ReportPane = defineAsyncComponent(() => import('./components/ReportPane.vue'))
const TableroPane = defineAsyncComponent(() => import('./components/TableroPane.vue'))
const IspPane = defineAsyncComponent(() => import('./components/IspPane.vue'))
const EpivigilaPane = defineAsyncComponent(() => import('./components/EpivigilaPane.vue'))

const current = ref('inicio')

// Latches on first open and never resets, so the iframe mounts exactly once.
const tableroSeen = ref(false)
watch(current, (pane) => {
	if (pane === 'tablero') {
		tableroSeen.value = true
	}
})

/**
 * Inicio first, then the MINSAL group, then the ISP on its own row.
 *
 * ONE caption, and it groups by publisher. There used to be three, and the third
 * grouped by function: "Notificación" over EPIVIGILA. EPIVIGILA is
 * epivigila.minsal.cl, so under the publisher rule it belongs to MINSAL, and moving
 * it there leaves one rule instead of two. The ISP's row used to label itself, on
 * the grounds that a caption spending 38px on a single row is chrome rather than
 * structure. That held while there was one row. Since #52 there are six, and six
 * rows that each repeat "ISP" would spend far more than 38px saying it.
 *
 * Names come from panes.js. This file used to hold one wording and each pane
 * another; see ADR-0008 for why the groups do not collapse.
 *
 * No counters. Nextcloud uses a counter bubble for what needs action, and neither
 * of ours is that: MINSAL's 75 holds until it republishes, and the ISP's now only
 * grows as the archive accretes. A number that never means "act on me" is
 * decoration that trains people to ignore the one place a real count will one day
 * appear. Each pane states its own count in its toolbar, where it changes as you
 * filter and therefore means something.
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
			pane('informe', ChartBellCurve),
			pane('tablero', ChartBox),
			pane('epivigila', ShieldAccount),
		],
	},
]

/**
 * One glyph per family, for the four the ISP names itself.
 *
 * This navigation used to carry ONE icon for all six rows, so that no glyph would assert a
 * visual taxonomy the ISP does not publish. Half of that survived and half did not
 * ([ADR-0013](../docs/adr/0013-family-icons-where-the-isp-names-the-family.md)):
 * `familias.js` opens by counting that 16 of the ISP's 24 slugs name their own
 * family, so four of the five are the publisher's nomenclature and a glyph asserts
 * nothing new. The fifth is `otros` — "Sin familia declarada por el ISP" — and there
 * the original reasoning is exactly right, so it keeps no glyph.
 *
 * The map lives here rather than in familias.js: that module is plain data whose
 * tests run under vitest's `node` environment, and importing SFCs into it would drag
 * a component runtime into it — the split src/filters.js already records against
 * src/state.js. The navigation is its only consumer.
 */
const FAMILY_ICONS = {
	medicamentos: Pill,
	dispositivos: Stethoscope,
	cosmeticos: Lipstick,
	desinfectantes: Spray,
	// `otros` is deliberately absent, not forgotten. A family the ISP adds tomorrow
	// misses too, and renders with no glyph rather than with a borrowed one.
}

/**
 * The ISP's six rows: everything, then its five families.
 *
 * `Todas` first and with no family, because the archive is what the pane is for —
 * a family is a narrowing of it, not a separate place. The families come from
 * familias.js so the navigation and the rail inside the pane cannot disagree about
 * what a family contains; they held two copies of that test before #52.
 */
const ispEntries = [
	// The pane's own name, from panes.js — NOT "Todas". #64 named this row for what
	// it filters to instead of what it opens, and the app immediately had three names
	// for one destination: Inicio said "Alertas del ISP", the row and the heading said
	// "Todas". layout.mjs check 5 passed throughout, because "Todas" WAS a nav name —
	// the check held while the property it stands for did not. On a phone the heading
	// is the only name shown, so it named nothing at all.
	//
	// Unconditional, unlike the families below it: a degraded ISP must still have a
	// destination, and it is the row that carries the banner.
	//
	// A list glyph, NOT Pill. It shared Pill with Medicamentos and the two rows read as
	// siblings of one kind — seen in a screenshot, which is the only way this was ever
	// going to be seen. A pill would also misdescribe the row: the archive spans
	// cosmetics, devices and disinfectants. "The whole list" is a UI metaphor and
	// asserts no product taxonomy, which is the point ADR-0013 turns on.
	{ id: null, name: paneName('isp'), icon: markRaw(FormatListBulleted) },
	// The families that CARRY alerts, not all five. A family the ISP is not publishing
	// kept a permanent row, and landing on it with no filters set said "Ninguna alerta
	// del ISP coincide con esos filtros" — blaming the reader for a choice they had not
	// made. familiasPresent() was written for exactly this and had no caller; wiring it
	// in deletes the state, where wording it would have been more code than wiring it in.
	...familiasPresent(sanitaryAlerts.items).map((f) => ({
		id: f.id,
		name: f.name,
		icon: FAMILY_ICONS[f.id] ? markRaw(FAMILY_ICONS[f.id]) : null,
	})),
]

/** Which ISP family the pane is showing; null is the whole archive. */
const ispFamily = ref(null)

function openIsp(family) {
	current.value = 'isp'
	ispFamily.value = family
}

/**
 * Opening a pane by name always shows that pane WHOLE.
 *
 * `current = $event` left `ispFamily` wherever the navigation had put it, so opening
 * Cosméticos, going to Inicio and tapping "Ver las 173 alertas del ISP" landed on a pane
 * headed Cosméticos showing 12. One destination, two names, reached through the door that
 * check 5 does not watch — it reads the heading once and never clicks.
 */
function open(pane) {
	if (pane === 'isp') {
		ispFamily.value = null
	}
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

/* The width of the glyph `otros` does not have (ADR-0013). 20px because that is the
   `:size` every other row passes, not a number of its own. */
.epi-nav-noicon {
	display: block;
	width: 20px;
	height: 20px;
}

.epi-nav-footer {
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}
</style>
