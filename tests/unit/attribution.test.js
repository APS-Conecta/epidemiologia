/**
 * What a clinician sees has to match what the app actually reads.
 *
 * The reports fuente moved to the ISP and the alerts fuente moved to a different
 * MINSAL host, but the user-facing surfaces kept crediting the old institution and
 * linking a host that has been unreachable since October 2025 (epi.minsal.cl).
 * CONTEXT.md is explicit that conflating fuente with institución is exactly what
 * once made an ISP outage report itself as MINSAL — this guards the same class of
 * defect on the frontend.
 *
 * Read as TEXT rather than imported, for the reason family-icons.test.js and
 * isp-terms.test.js give: these components run under vitest's `node` environment
 * on purpose (vitest.config.js), so a single-file component cannot be imported.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const src = (path) => readFileSync(new URL(`../../src/${path}`, import.meta.url), 'utf8')

const SURFACES = [
	'App.vue',
	'components/AlertsPane.vue',
	'components/ReportPane.vue',
	'components/IspPane.vue',
	'components/InicioPane.vue',
	'components/EpivigilaPane.vue',
]

describe('attribution', () => {
	it('never points a clinician at a host that no longer answers', () => {
		for (const path of SURFACES) {
			expect(src(path), path).not.toContain('epi.minsal.cl')
		}
	})

	it('credits each fuente to the institution that publishes it', () => {
		expect(src('components/ReportPane.vue')).toContain('source="ISP"')
		expect(src('components/AlertsPane.vue')).toContain('degreyd.minsal.cl')
	})
})
