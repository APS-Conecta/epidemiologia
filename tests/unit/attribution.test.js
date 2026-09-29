/**
 * What a clinician sees has to match what the app actually reads.
 *
 * The reports fuente moved to the ISP and the alerts fuente moved to a different
 * MINSAL host, but the user-facing surfaces kept crediting the old institution and
 * linking a host that had stopped answering. CONTEXT.md is explicit that conflating
 * fuente with institución is exactly what once made an ISP outage report itself as
 * MINSAL — this guards the same class of defect on the frontend.
 *
 * Read as TEXT rather than imported: these components run under vitest's `node`
 * environment on purpose (vitest.config.js), so a single-file component cannot be
 * imported. The registry and the maps are plain modules, and are imported.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { PANES } from '../../src/panes.js'
import { FUENTES, SITIOS } from '../../src/pure.js'

const src = (path) => readFileSync(new URL(`../../src/${path}`, import.meta.url), 'utf8')

const SURFACES = [
	'App.vue',
	'components/AlertsPane.vue',
	'components/ReportPane.vue',
	'components/InicioPane.vue',
	'components/EpivigilaPane.vue',
]

const SECTORS = PANES.filter((p) => p.source)

describe('attribution', () => {
	// This used to pin one host by name — epi.minsal.cl, "unreachable since October
	// 2025". It answered the owner's residential connection again on 2026-09-27, and
	// the Departamento de Epidemiología pane now sends its readers there on purpose,
	// from SITIOS. What survives is the half that was always the point (L6-01): a
	// list's address lives in one place, so an address that dies is fixed in one place.
	it('keeps every list address in SITIOS, never inline in a surface', () => {
		for (const path of SURFACES) {
			for (const address of Object.values(SITIOS)) {
				expect(src(path), path).not.toContain(address)
			}
		}
		// The two panes that send a reader somewhere name no host at all, not even in prose.
		for (const { host } of Object.values(SITIOS).map((address) => new URL(address))) {
			expect(src('components/AlertsPane.vue')).not.toContain(host)
			expect(src('components/ReportPane.vue')).not.toContain(host)
		}
	})

	it('credits each fuente to the institution that publishes it', () => {
		// "el ISP", with the article: the constants document that the article
		// differs between "MINSAL" and "el ISP" (L3-20 — FUENTES, in
		// src/pure.js), and every pane carries the constant, never a literal.
		expect(src('components/ReportPane.vue')).toContain(':source="FUENTES.isp.name"')
		expect(src('components/ReportPane.vue')).toContain(':url="SITIOS.reports"')

		// The sector pane names its publisher through the registry's group and sends
		// its reader to its own list's page — so every sector's group has a publisher
		// to name and a noun to count in, and every sector's list has a page.
		expect(src('components/AlertsPane.vue')).toContain('const fuente = FUENTES[sector.group]')
		expect(src('components/AlertsPane.vue')).toContain(':source="fuente.name" :url="SITIOS[sector.source]"')
		for (const { id, group, source } of SECTORS) {
			expect(typeof FUENTES[group]?.name, id).toBe('string')
			expect(typeof FUENTES[group]?.count, id).toBe('function')
			expect(SITIOS[source], id).toMatch(/^https:\/\//)
		}

		// Each list's page, not its publisher's front door — the ISP's four lists are
		// four pages, and the informe's is not ANAMED's.
		expect(SITIOS.alerts).toBe('https://degreyd.minsal.cl/alertas-sanitarias-y-epidemiologicas-vigentes-en-chile/')
		expect(SITIOS.reports).toBe('https://www.ispch.gob.cl/virusrespiratorios/')
		expect(new Set(Object.values(SITIOS)).size).toBe(Object.keys(SITIOS).length)
	})
})
