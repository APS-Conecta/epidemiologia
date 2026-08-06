import { t } from '@nextcloud/l10n'

/**
 * The ISP's product families, read off its own URL nomenclature.
 *
 * 16 of its 24 category slugs name their family in the slug itself — the `-dp`
 * suffix for dispositivos, `cosmet…`, `desinfectante…`, and the medicamentos
 * cluster. Grouping those is reading the ISP's naming, not inventing a taxonomy.
 * The other eight are adscribed by the ISP to nothing, and they stay together
 * under a label that says exactly that.
 *
 * Sorting the eight by resemblance would be publishing our guess as the ISP's —
 * the same thing issue #1 forbids for MINSAL disease tags, and for the same reason:
 * a clinician reads what the app groups as what the source declared.
 *
 * This module exists because the navigation and the pane must agree. They used to
 * hold two copies of `familia()`, which is exactly how a filter and its menu drift
 * into disagreeing about what a family contains.
 *
 * `otros` matches everything and MUST stay last — it is the floor, not a family
 * with a test. The other four are mutually exclusive across today's 24 slugs
 * (checked: `retiro-del-mercado-dispositivo-medico` reads as a medicamento only if
 * you misread `del` as `de`), so their order is presentation, not logic. It is
 * still asserted, because a family list that reshuffles between the navigation and
 * the rail is a bug a reader sees before we do.
 */
export const FAMILIAS = [
	{
		id: 'dispositivos',
		name: () => t('epidemiologia', 'Dispositivos médicos'),
		test: (slug) => /-dp$|dispositivo/.test(slug),
	},
	{
		id: 'cosmeticos',
		name: () => t('epidemiologia', 'Cosméticos'),
		test: (slug) => slug.includes('cosmet'),
	},
	{
		id: 'desinfectantes',
		name: () => t('epidemiologia', 'Desinfectantes'),
		test: (slug) => slug.includes('desinfectante'),
	},
	{
		id: 'medicamentos',
		name: () => t('epidemiologia', 'Medicamentos'),
		test: (slug) => /anamed|farmacovigilancia|retiro-de-mercado|producto-sin-registro|falsificado-producto/.test(slug),
	},
	{
		id: 'otros',
		name: () => t('epidemiologia', 'Sin familia declarada por el ISP'),
		test: () => true,
	},
]

/** Which family a category slug belongs to. Never null — `otros` is the floor. */
export const familiaOf = (slug) => FAMILIAS.find((f) => f.test(slug)).id

/** The family's own label, for a tag or a rail heading. */
export const familiaName = (id) => FAMILIAS.find((f) => f.id === id)?.name() ?? ''

/**
 * Families that actually carry alerts right now, with their counts.
 *
 * Derived from the data on every read rather than from the FAMILIAS list, so a
 * category the ISP stops publishing disappears on its own instead of showing a
 * zero nobody can clear.
 *
 * @param {Array} items - alerts, each with a `categories` array
 * @return {Array} [{ id, name, count }] in FAMILIAS order
 */
export function familiasPresent(items) {
	const counts = {}
	for (const item of items) {
		// An alert belongs to 1,76 categories on average, so it can belong to two
		// families. Counted once per family, never once per membership, or the
		// numbers would add up to more than the archive.
		for (const id of new Set((item.categories ?? []).map(familiaOf))) {
			counts[id] = (counts[id] ?? 0) + 1
		}
	}

	return FAMILIAS
		.filter((f) => counts[f.id])
		.map((f) => ({ id: f.id, name: f.name(), count: counts[f.id] }))
}

/**
 * The category slugs of one family that carry alerts, with their counts.
 *
 * @param {Array} items - alerts, each with a `categories` array
 * @param {?string} family - a family id, or null for every category
 * @return {Array} [{ slug, count }] busiest first
 */
export function categoriesIn(items, family) {
	const counts = {}
	for (const item of items) {
		for (const slug of item.categories ?? []) {
			if (family === null || familiaOf(slug) === family) {
				counts[slug] = (counts[slug] ?? 0) + 1
			}
		}
	}

	return Object.entries(counts)
		.map(([slug, count]) => ({ slug, count }))
		.sort((a, b) => b.count - a.count || a.slug.localeCompare(b.slug))
}
