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
 * Runs inside the page. Four families, and each sees what the others cannot.
 *
 * The third: a list with six rows and zero height does not overflow and does not
 * overlap. It is geometrically perfect and the landing pane has no alerts on it.
 * Measured before it was written: 0 false positives across all six panes healthy,
 * fires on the real signature (clientH=0, scrollH=204, 6 children), 0 again once
 * restored.
 *
 * The fourth is about meaning rather than geometry, and the geometry checks are
 * blind to it by construction: the ISP categories filter when clicked and were
 * pixel-identical to the static date beside them — same 13px, same weight 400,
 * same #6b6b6b, underline transparent — with cursor:pointer the only difference.
 * That is invisible until the pointer is already on it, and on a touch screen it
 * is never visible at all. Nothing was overflowing, overlapping or collapsed.
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

	// 4. nothing that acts like a control looks exactly like the static text
	//    beside it. Compared only against SIBLINGS, because that is the comparison
	//    a reader actually makes — two words on one row, one of which does
	//    something. Compared on everything that could carry the difference, so a
	//    background, a border or a weight all count as "distinguishable".
	const affordance = []
	const ownText = (e) => [...e.childNodes]
		.filter((n) => n.nodeType === 3)
		.map((n) => n.textContent)
		.join('')
		.trim()
	const signature = (e) => {
		const s = getComputedStyle(e)
		// A transparent underline is not an underline. This is exactly how the
		// defect hid: text-decoration-line said underline the whole time.
		const decoration = s.textDecorationLine === 'none' || s.textDecorationColor === 'rgba(0, 0, 0, 0)'
			? 'none'
			: s.textDecorationLine
		return [
			s.fontSize, s.fontWeight, s.fontStyle, s.color, decoration,
			s.backgroundColor, s.textTransform,
			s.borderTopStyle === 'none' ? 'no-border' : s.borderTopWidth + s.borderTopStyle,
		].join(' · ')
	}
	for (const parent of pane.querySelectorAll('*')) {
		const kids = [...parent.children].filter((e) => ownText(e))
		if (kids.length < 2) {
			continue
		}
		const clickable = kids.filter((e) => getComputedStyle(e).cursor === 'pointer')
		const inert = kids.filter((e) => getComputedStyle(e).cursor !== 'pointer')
		for (const c of clickable) {
			const twin = inert.find((i) => signature(i) === signature(c))
			if (twin) {
				affordance.push(`${describe(c)} "${ownText(c).slice(0, 24)}" is indistinguishable from ${describe(twin)} "${ownText(twin).slice(0, 24)}" beside it — ${signature(c)}`)
			}
		}
	}

	// 5. one name per pane: the heading has to be a name the navigation also uses.
	//    Every pane used to carry its own wording, so a phone tapped one string and
	//    landed on another; panes.js is what makes this true, and this is what
	//    keeps it true.
	const navNames = [...document.querySelectorAll('.app-navigation-entry__name')]
		.map((e) => e.textContent.trim())
	const naming = []
	const heading = (pane.querySelector('h2, h1') || {}).textContent?.trim()
	if (heading && navNames.length && !navNames.includes(heading)) {
		naming.push(`heading "${heading}" is not one of the navigation's names (${navNames.join(', ')})`)
	}

	return { label, overflow, overlap, collapsed, affordance, naming }
}

/**
 * Check 6, which needs a click and therefore cannot live in INSPECT.
 *
 * Every option a filter menu offers, apart from its "todo" row, states how many
 * items it holds. That was written in #12 and had never once appeared: NcActionRadio
 * takes its label from ActionGlobalMixin.getText(), which returns
 * `$slots.default()[0].children` — the FIRST vnode of the slot, and only if its
 * children is a string. The count sat in a second vnode and was dropped in silence.
 *
 * So this asserts the rendered text, not the source: it is the only place the
 * difference between "written" and "shown" is visible.
 *
 * @param {import('playwright').Page} page - The page under test.
 * @param {number} paneIndex - Which `.epi > section` to open.
 * @return {Promise<string[]>} One complaint per option that shows no count.
 */
const inspectMenus = async (page, paneIndex) => {
	const complaints = []
	const menus = await page.evaluate((i) => {
		const panes = [...document.querySelectorAll('.epi > section')]
		panes.forEach((p, j) => { p.style.display = j === i ? 'block' : 'none' })
		return panes[i].querySelectorAll('.epi-bar .action-item button').length
	}, paneIndex)

	for (let m = 0; m < menus; m++) {
		await page.evaluate(({ i, m: which }) => {
			const pane = [...document.querySelectorAll('.epi > section')][i]
			pane.querySelectorAll('.epi-bar .action-item button')[which].click()
		}, { i: paneIndex, m })
		await page.waitForFunction(() => !!document.querySelector('.v-popper__popper'), null, { timeout: 5000 })

		const options = await page.evaluate(() => {
			const popper = [...document.querySelectorAll('.v-popper__popper')].pop()
			return [...popper.querySelectorAll('.action-radio__text')].map((e) => e.textContent.trim())
		})

		// The first row is "Todos los años" / "Todos los tipos": no filter, so
		// nothing to count. Every row after it names a subset and states its size.
		for (const option of options.slice(1)) {
			if (!/\(\d+\)$/.test(option)) {
				complaints.push(`filter option "${option}" shows no count`)
			}
			// `undefined (12)` shipped for weeks: tally() returns no label and one pane
			// mapped one on while the other did not. It has a count, so the check above
			// passes it quite happily — which is why this one exists beside it.
			if (/\bundefined\b/.test(option)) {
				complaints.push(`filter option "${option}" renders an undefined label`)
			}
		}
		if (options.length === 1) {
			complaints.push('a filter menu offers nothing but its "todo" row')
		}

		// Choosing an option must mark it. NcActionRadio computes checked as
		// `model === value`, so passing a boolean and no value left every circle
		// empty while the filter itself worked — invisible to every other check here.
		if (options.length > 1) {
			const marked = await page.evaluate(() => {
				const popper = [...document.querySelectorAll('.v-popper__popper')].pop()
				const radios = [...popper.querySelectorAll('.action-radio input[type="radio"]')]
				radios[1]?.click()
				return {
					total: radios.length,
					checked: radios.filter((r) => r.checked).length,
					second: !!radios[1]?.checked,
				}
			})
			if (marked.total > 0 && !marked.second) {
				complaints.push('choosing a filter option leaves its circle empty')
			}
			if (marked.checked > 1) {
				complaints.push(`${marked.checked} filter options are marked at once`)
			}
		}

		await page.keyboard.press('Escape')
		await page.waitForFunction(() => !document.querySelector('.v-popper__popper'), null, { timeout: 5000 })
			.catch(() => {})
	}

	return complaints
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
			...r.affordance.map((d) => ['algo pulsable idéntico al texto de al lado', d]),
			...r.naming.map((d) => ['el panel se llama de dos maneras', d]),
		]
		if (problems.length === 0) {
			pass(`${r.label.padEnd(26)} desborde·solape·altura·afordancia·nombres`)
		} else {
			for (const [what, detail] of problems) {
				fail(`${r.label} — ${what}`, [detail])
			}
		}
	}

	// The two panes that carry a toolbar. Done after INSPECT because it leaves the
	// panes' display attributes set, and this needs to set them again anyway.
	for (const [paneIndex, name] of [[1, 'Alertas vigentes'], [4, 'Alertas del ISP']]) {
		const complaints = await inspectMenus(page, paneIndex)
		if (complaints.length === 0) {
			pass(`${name.padEnd(26)} recuentos en los filtros`)
		} else {
			complaints.forEach((c) => fail(`${name} — recuento ausente`, [c]))
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
