import { t } from '@nextcloud/l10n'

/**
 * What each pane is called. Once.
 *
 * Every pane used to be named two or three times over — the navigation said
 * "Informe semanal", the Inicio card said "Informes ETI/IRAG" and the heading said
 * "Informe semanal de vigilancia ETI e IRAG", written in three files. On a phone the
 * heading is the only thing naming the pane, so a reader tapped one wording and
 * landed on another.
 *
 * This is the same move `Sources` makes on the PHP side: the fact lives in one
 * place, and the places that need it read it from here.
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
 * `Alertas del ISP` is the one name that carries its publisher, because its row is
 * the only one outside the MINSAL group — see ADR-0008 and App.vue's navigation.
 */
const PANE_NAMES = {
	inicio: () => t('epidemiologia', 'Inicio'),
	alertas: () => t('epidemiologia', 'Alertas vigentes'),
	informe: () => t('epidemiologia', 'Circulación de Virus Respiratorios'),
	tablero: () => t('epidemiologia', 'Tablero ETI e IRAG'),
	epivigila: () => t('epidemiologia', 'EPIVIGILA'),
	isp: () => t('epidemiologia', 'Alertas del ISP'),
}

/**
 * Thunks rather than plain strings: `t()` needs the l10n bundle loaded, and a
 * module evaluated at import time can run before it is. Calling at use site is
 * what every pane already does.
 *
 * @param {string} id - The pane's id, as used by App.vue's `current`.
 * @return {string} Its name, translated.
 */
export function paneName(id) {
	return PANE_NAMES[id]()
}
