// The layout assertions that no unit test can make (#40).
//
// Seven of the fifteen defects this app has fixed were CSS or markup, and every
// one was found by a person looking at a screen. They are not reachable without a
// browser computing styles: a grid track holding a 320px floor inside a 281px
// column, a flex child collapsing to exactly 0px, a card that does not grow with
// its content — jsdom sees none of that.
//
// Driven by scripts/layout.sh, which mints the credential and revokes it.
//
// Usage: node scripts/layout.mjs <base-url> <user> <password>
import { chromium } from 'playwright'

const [baseUrl, user, password] = process.argv.slice(2)
if (!baseUrl || !user || !password) {
	console.error('usage: node scripts/layout.mjs <base-url> <user> <password>')
	process.exit(2)
}

/**
 * Both sides of the app's only breakpoint (1024px), and the narrow end where the
 * geometry actually breaks. 360 is the phone this was never testable on: a real
 * browser window here cannot go below ~500px, which is why #20 shipped measured
 * rather than seen. A headless viewport has no such floor.
 */
const VIEWPORTS = [
	{ w: 360, h: 740 },
	{ w: 768, h: 900 },
	{ w: 1366, h: 768 },
]

let failures = 0
const pass = (m) => console.log(`    \x1b[32mok\x1b[0m    ${m}`)
const fail = (m, detail = []) => {
	console.log(`    \x1b[31mFAIL\x1b[0m  ${m}`)
	detail.forEach((d) => console.log(`          ${d}`))
	failures++
}

/**
 * Runs inside the page. Three families, and the third is the one the other two
 * cannot see: a list with six rows and zero height does not overflow and does not
 * overlap. It is geometrically perfect and the landing pane has no alerts on it.
 *
 * Measured before it was written: 0 false positives across all six panes healthy,
 * fires on the real signature (clientH=0, scrollH=204, 6 children), 0 again once
 * restored.
 */
const INSPECT = (paneIndex) => {
	const panes = [...document.querySelectorAll('.epi > section')]
	panes.forEach((p, i) => { p.style.display = i === paneIndex ? 'block' : 'none' })
	const pane = panes[paneIndex]
	const label = (pane.querySelector('h2, h1') || {}).textContent?.trim().slice(0, 24) ?? `pane ${paneIndex}`
	const box = (e) => e.getBoundingClientRect()
	const describe = (e) => e.tagName + '.' + (e.className || '').toString().trim().split(/\s+/)[0]

	// 1. nothing wider than the pane that holds it
	const overflow = []
	if (pane.scrollWidth > pane.clientWidth + 1) {
		overflow.push(`${describe(pane)} clientW=${pane.clientWidth} scrollW=${pane.scrollWidth} (+${pane.scrollWidth - pane.clientWidth}px)`)
	}
	for (const e of pane.querySelectorAll('*')) {
		if (e.scrollWidth > e.clientWidth + 1 && getComputedStyle(e).overflowX === 'visible' && e.clientWidth > 0) {
			overflow.push(`${describe(e)} clientW=${e.clientWidth} scrollW=${e.scrollWidth} (+${e.scrollWidth - e.clientWidth}px)`)
		}
	}

	// 2. no two cards drawn on top of each other. Compared only within a column,
	//    because side-by-side cards legitimately share a vertical band.
	const overlap = []
	const cards = [...pane.querySelectorAll('.epi-card')].map(box).sort((a, b) => a.top - b.top)
	for (let i = 1; i < cards.length; i++) {
		const [prev, cur] = [cards[i - 1], cards[i]]
		if (Math.abs(cur.left - prev.left) < 2 && cur.top < prev.bottom - 1) {
			overlap.push(`a card starts at y=${Math.round(cur.top)} while the one above still runs to y=${Math.round(prev.bottom)}`)
		}
	}

	// 3. content present, box collapsed to nothing
	const collapsed = []
	for (const e of pane.querySelectorAll('*')) {
		if (e.scrollHeight > 2 && e.clientHeight === 0 && getComputedStyle(e).display !== 'none') {
			collapsed.push(`${describe(e)} clientH=0 scrollH=${e.scrollHeight} (${e.children.length} children)`)
		}
	}

	return { label, overflow, overlap, collapsed }
}

const browser = await chromium.launch()
// The header is sent on EVERY request, not left to httpCredentials. Playwright
// only answers a 401 challenge, and Nextcloud does not challenge a browser — it
// redirects to /login, so the run silently measured the login page instead of the
// app. Diagnosed, not guessed: core-login.js was among the loaded scripts.
const context = await browser.newContext({
	extraHTTPHeaders: {
		Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
	},
})
const page = await context.newPage()

console.log(`\nEpidemiología — maquetación en ${baseUrl}\n`)

for (const { w, h } of VIEWPORTS) {
	await page.setViewportSize({ width: w, height: h })
	await page.goto(`${baseUrl}/apps/epidemiologia/`, { waitUntil: 'load' })
	// The panes are rendered by Vue after load, so wait for the thing itself
	// rather than for a duration — a sleep long enough to be safe here would be
	// long enough to make the whole run tedious.
	await page.waitForFunction(() => document.querySelectorAll('.epi > section').length === 6, null, { timeout: 20000 })

	console.log(`  \x1b[1m${w}×${h}\x1b[0m`)
	const count = await page.evaluate(() => document.querySelectorAll('.epi > section').length)

	for (let i = 0; i < count; i++) {
		const r = await page.evaluate(INSPECT, i)
		const problems = [
			...r.overflow.map((d) => ['contenido más ancho que su panel', d]),
			...r.overlap.map((d) => ['tarjetas dibujadas una sobre otra', d]),
			...r.collapsed.map((d) => ['contenido con altura cero', d]),
		]
		if (problems.length === 0) {
			pass(`${r.label.padEnd(26)} desborde·solape·altura`)
		} else {
			for (const [what, detail] of problems) {
				fail(`${r.label} — ${what}`, [detail])
			}
		}
	}
}

await browser.close()

if (failures === 0) {
	console.log('\nTodo correcto.\n')
} else {
	console.log(`\n${failures} comprobación(es) fallida(s)\n`)
	process.exit(1)
}
