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
 * 'isp' under the ISP caption.
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
 * No pane name spells a publisher out in words any more: the one that did
 * retired with its pane (ADR-0016). The informe carries its publisher the way
 * it always has — by wearing the ISP's own title for it instead of MINSAL's —
 * and the institution is still said, once, by the caption above the row in
 * App.vue's navigation; see ADR-0008.
 */
export const PANES = [
	{ id: 'inicio', group: 'top', icon: 'home' },
	{ id: 'alertas', group: 'minsal', icon: 'alert' },
	{ id: 'tablero', group: 'minsal', icon: 'chart-box' },
	{ id: 'epivigila', group: 'minsal', icon: 'shield-account' },
	{ id: 'informe', group: 'isp', icon: 'chart-bell-curve' },
]

const PANE_NAMES = {
	inicio: () => t('epidemiologia', 'Inicio'),
	alertas: () => t('epidemiologia', 'Alertas vigentes'),
	informe: () => t('epidemiologia', 'Circulación de Virus Respiratorios'),
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
