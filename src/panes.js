import { t } from '@nextcloud/l10n'

/**
 * The pane registry (L3-18): id → name, icon, group, order — one place.
 *
 * App.vue used to hold the ids as string literals twice over and the count
 * was pinned independently by scripts/layout.mjs; the three disagreed at
 * every change. The registry is the single source now: App.vue renders the
 * groups from it, layout.mjs derives its count from its ids, and the suites
 * walk it. Icons are NAMES, not imports — this module stays loadable by
 * plain node, which is the property layout.mjs and the tests consume.
 *
 * The group is the navigation's, and it groups by publisher (ADR-0008):
 * 'top' renders before the captions, 'minsal' under the MINSAL caption,
 * 'isp' under the ISP caption, 'ops' under the OPS/OMS caption. A sector
 * pane names its publisher by the same key — FUENTES[group], in src/pure.js
 * — so a new group needs an entry there too (attribution.test.js).
 *
 * `source` marks an alert sector: the backend id (Service/Sources.php) whose
 * list the pane shows. Each of the six is one instance of AlertsPane, told
 * apart by its registry id, and their order here is the order they appear
 * in. The informe reads a fuente too, `reports`, but it is no alert sector —
 * ReportPane reads it by name — so it carries none.
 *
 * The names themselves follow two rules, both visible in what they dropped:
 *
 * - **No word the surrounding chrome already says.** "Alertas epidemiológicas" is
 *   redundant inside an app called Epidemiología, and "Tablero MINSAL" sat directly
 *   under a navigation caption reading MINSAL.
 * - **Keep a word that carries a fact.** ETI e IRAG stays on the tablero, because it
 *   says which surveillance MINSAL's own Power BI report is about, and nothing else
 *   does. The informe dropped it: that fuente now reads from the ISP, whose own name
 *   for it is Circulación de Virus Respiratorios, and ETI e IRAG was the dead MINSAL
 *   page's name, not this one's (see Sources::name(REPORTS)).
 *
 * No pane name spells an institution out: the caption above the row in App.vue's
 * navigation says it, once (ADR-0008). Two names carry a unit of one instead, and
 * both by the second rule. "Departamento de Epidemiología" is what tells its row
 * from Alertas vigentes — both are MINSAL alert lists, read from two different
 * pages (Sources::name(EPI)). "ANAMED" is the ISP's own name for the category its
 * pane reads, the slug in that feed's address.
 */
export const PANES = [
	{ id: 'inicio', group: 'top', icon: 'home' },
	{ id: 'alertas', group: 'minsal', icon: 'alert', source: 'alerts' },
	{ id: 'epi', group: 'minsal', icon: 'virus', source: 'epi_alerts' },
	{ id: 'tablero', group: 'minsal', icon: 'chart-box' },
	{ id: 'epivigila', group: 'minsal', icon: 'shield-account' },
	{ id: 'informe', group: 'isp', icon: 'chart-bell-curve' },
	{ id: 'anamed', group: 'isp', icon: 'pill', source: 'isp_anamed' },
	{ id: 'farmacovigilancia', group: 'isp', icon: 'clipboard-pulse', source: 'isp_pharmacovigilance' },
	{ id: 'cosmeticos', group: 'isp', icon: 'lipstick', source: 'isp_cosmetics' },
	{ id: 'ops', group: 'ops', icon: 'earth', source: 'paho_alerts' },
]

const PANE_NAMES = {
	inicio: () => t('epidemiologia', 'Inicio'),
	alertas: () => t('epidemiologia', 'Alertas vigentes'),
	epi: () => t('epidemiologia', 'Departamento de Epidemiología'),
	informe: () => t('epidemiologia', 'Circulación de Virus Respiratorios'),
	anamed: () => t('epidemiologia', 'Medicamentos (ANAMED)'),
	farmacovigilancia: () => t('epidemiologia', 'Farmacovigilancia'),
	cosmeticos: () => t('epidemiologia', 'Vigilancia de cosméticos'),
	ops: () => t('epidemiologia', 'Alertas y actualizaciones'),
	tablero: () => t('epidemiologia', 'Tablero ETI e IRAG'),
	epivigila: () => t('epidemiologia', 'EPIVIGILA'),
}

/** Thunks rather than plain strings: `t()` needs the l10n bundle loaded. An
 * unknown id answers undefined (F2) — the registry has no entry for it, and
 * crashing mid-render over a typo'd id is the caller's fault, named as such
 * by the unit pin. */
export function paneName(id) {
	return PANE_NAMES[id]?.()
}

/** The registry's ids, in order — what layout.mjs counts and the tests walk. */
export const PANE_IDS = PANES.map((pane) => pane.id)
