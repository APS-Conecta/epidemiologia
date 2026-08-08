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
// Usage: EPI_TOKEN=<token> node scripts/layout.mjs <base-url> <user>
import { chromium } from 'playwright'

// The token comes through the ENVIRONMENT, not argv. A command line is readable from
// /proc/*/cmdline by any local user for as long as the run lasts, and this one holds a
// live app password. Mint-and-revoke limits the window; it does not close it.
const [baseUrl, user] = process.argv.slice(2)
const password = process.env.EPI_TOKEN
if (!baseUrl || !user || !password) {
	console.error('usage: EPI_TOKEN=<token> node scripts/layout.mjs <base-url> <user>')
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

	/*
	 * 7. the navigation fits its own column (#52, ADR-0008).
	 *
	 * ADR-0008 says the groups do not collapse. That is only affordable while the
	 * rows fit: putting all 24 ISP categories in the column was measured at 1.134px
	 * against a 506px column, which is what forced families instead. So MEASURE it
	 * rather than assert it in a comment — the number is what the decision rests on,
	 * and a comment cannot notice when a seventh row is added.
	 *
	 * The footer is excluded: it is pinned to the bottom and does not compete for the
	 * scrolling area the lists live in.
	 */
	const fit = []
	const nav = document.querySelector('.app-navigation')
	const lists = [...document.querySelectorAll('.app-navigation ul')]
	if (nav && lists.length) {
		const rows = lists.reduce((sum, l) => sum + l.getBoundingClientRect().height, 0)
		const column = nav.getBoundingClientRect().height
		if (rows > column) {
			fit.push(`the navigation's rows are ${Math.round(rows)}px in a ${Math.round(column)}px column — ADR-0008 says the groups do not collapse, which stops being free here`)
		}
	}

	/*
	 * 9. the toolbar keeps the one thing that speaks, and names the one that filters.
	 *
	 * #81 fixed seven accessibility defects and left nothing watching them, so the
	 * count's live region survived only because nobody happened to touch it. It is
	 * still the ONLY live region in the app: without it, typing in Buscar tells a
	 * screen-reader user nothing at all, not even that the list fell to zero.
	 *
	 * The token group is checked for a name rather than for existence — it is absent
	 * whenever nothing is filtered, which is correct — because an unlabelled group of
	 * dismiss buttons is a row of anonymous crosses.
	 */
	const a11y = []
	const bar = pane.querySelector('.epi-bar')
	if (bar) {
		const live = bar.querySelector('[role="status"][aria-live]')
		if (!live) {
			a11y.push('the toolbar has no live region — the result count is the only thing this app announces (#81)')
		}
	}
	// The active-filter group is NOT checked here. It does not exist until something
	// is filtered, and this runs on a freshly loaded pane — a copy of the assertion
	// lived here and could never fire, which is the same "check that holds while the
	// property does not" that #64 already cost. It is in check 6, after the click.

	return { label, overflow, overlap, collapsed, affordance, naming, fit, a11y }
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

	// No menus is a FAILURE, not a pass. This check pointed at pane 4 for months —
	// Tablero, which has no toolbar — so the loop below never ran and the check printed
	// ok about the pane it never opened. A check that reports success on finding nothing
	// to check is worse than no check: it occupies the slot.
	if (menus === 0) {
		complaints.push('this pane has no filter menus at all — wrong pane index?')
	}

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

		// Choosing an option must MARK it, and the mark is the icon — NcActionRadio
		// renders `checked ? mdiRadioboxMarked : mdiRadioboxBlank`, where checked is
		// `model === value`.
		//
		// Two traps this has already fallen into, both of which made it pass against
		// the very defect it exists for:
		//  - asserting the <input>'s .checked proves nothing, because a native radio
		//    flips itself on click whatever the component thinks;
		//  - reading the icon in the same evaluate() as the click reads it BEFORE Vue
		//    re-renders, so it always looks unmarked. Hence waitForFunction below.
		if (options.length > 1) {
			await page.evaluate(() => {
				const popper = [...document.querySelectorAll('.v-popper__popper')].pop()
				const rows = [...popper.querySelectorAll('.action-radio')]
				rows[1]?.querySelector('input[type="radio"]')?.click()
			})

			const marked = await page.waitForFunction(() => {
				const popper = [...document.querySelectorAll('.v-popper__popper')].pop()
				if (!popper) {
					return null
				}
				const rows = [...popper.querySelectorAll('.action-radio')]
				const glyph = (row) => row?.querySelector('.action-radio__icon path')?.getAttribute('d') ?? ''
				const chosen = glyph(rows[1])
				return chosen !== '' && chosen !== glyph(rows[0]) ? true : undefined
			}, null, { timeout: 3000 }).then(() => true).catch(() => false)

			if (!marked) {
				complaints.push('choosing a filter option leaves its circle empty')
			}

			/*
			 * Having chosen one, the toolbar must SAY so — and say it somewhere that
			 * survives a scroll, which the menu's own tick does not.
			 *
			 * Asserted here rather than in INSPECT because the group does not exist
			 * until something is filtered: the copy that lived there could never run.
			 * The label matters more than the tokens: a group of dismiss buttons with
			 * no name is a row of anonymous crosses.
			 */
			const tokens = await page.evaluate(() => {
				const pane = [...document.querySelectorAll('.epi > section')].find((s) => s.offsetParent !== null)
				const group = pane?.querySelector('.epi-bar__active')

				return { there: !!group, label: group?.getAttribute('aria-label') ?? null }
			})
			if (!tokens.there) {
				complaints.push('a chosen filter shows no token in the toolbar, so scrolling hides what is applied')
			} else if (!tokens.label) {
				complaints.push('the active-filter group has no aria-label, so its dismiss buttons are unnamed crosses')
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

console.log(`\nEpidemiología — layout at ${baseUrl}\n`)

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
			...r.overflow.map((d) => ['content wider than its pane', d]),
			...r.overlap.map((d) => ['cards drawn on top of each other', d]),
			...r.collapsed.map((d) => ['content collapsed to zero height', d]),
			...r.affordance.map((d) => ['something clickable identical to the text beside it', d]),
			...r.naming.map((d) => ['the pane goes by two names', d]),
			...r.fit.map((d) => ['the navigation does not fit its column', d]),
			...r.a11y.map((d) => ['the toolbar lost an accessibility affordance', d]),
		]
		if (problems.length === 0) {
			pass(`${r.label.padEnd(26)} overflow·overlap·height·affordance·names·column·a11y`)
		} else {
			for (const [what, detail] of problems) {
				fail(`${r.label} — ${what}`, [detail])
			}
		}
	}

	// Check 8. The only check that NAVIGATES — check 6 clicks too, but within one pane —
	// and the only one that could have caught
	// this: opening a family and then following Inicio's "Ver las N alertas del ISP"
	// landed on a pane headed with the family's name, not the one the link said. Check
	// 5 reads the heading once, in the app's initial state, and never navigates — so a
	// destination that lies only after you have been somewhere else is invisible to it.
	//
	// Done on a fresh load, because everything above leaves display attributes set.
	await page.goto(`${baseUrl}/apps/epidemiologia/`, { waitUntil: 'load' })
	await page.waitForFunction(() => document.querySelectorAll('.epi > section').length === 6, null, { timeout: 20000 })

	const landed = await page.evaluate(async () => {
		const nav = [...document.querySelectorAll('.app-navigation-entry__name')]
		const click = (el) => el.closest('a, button')?.click()
		const settle = () => new Promise((r) => setTimeout(r, 150))

		// A family, then Inicio, then the card's own link back to the ISP.
		// Whichever family the archive happens to carry, not a named one. Since the nav
		// lists only families with alerts, hard-coding 'Cosméticos' made this check go red
		// for a DATA reason — a quiet week, or a degraded ISP — which is not what a layout
		// gate is for. With no family at all there is nothing to walk, so skip rather than
		// fail: the defect needs two destinations to exist before it can be shown.
		const inicio = nav.find((e) => e.textContent.trim() === 'Inicio')
		const family = nav.find((e, i) => i > nav.indexOf(
			nav.find((n) => n.textContent.trim() === 'Alertas del ISP')) && e.textContent.trim() !== '')
		if (!inicio) {
			return { error: 'navigation has no Inicio row' }
		}
		if (!family) {
			return { skipped: 'no ISP family carries alerts right now' }
		}
		click(family); await settle()
		click(inicio); await settle()

		const more = [...document.querySelectorAll('.epi-card__more')]
			.find((b) => /ISP/.test(b.textContent))
		if (!more) {
			return { error: 'Inicio has no link back to the ISP' }
		}
		more.click(); await settle()

		const pane = [...document.querySelectorAll('.epi > section')]
			.find((p) => getComputedStyle(p).display !== 'none')
		return { heading: pane?.querySelector('h2, h1')?.textContent.trim() ?? '' }
	})

	if (landed.skipped) {
		pass('Inicio'.padEnd(26) + ` skipped — ${landed.skipped}`)
	} else if (landed.error) {
		fail(`Inicio — ${landed.error}`)
	} else if (landed.heading !== 'Alertas del ISP') {
		fail('Inicio — the link goes somewhere it did not name', [
			`"Ver las … alertas del ISP" landed on a pane headed "${landed.heading}"`,
		])
	} else {
		pass('Inicio'.padEnd(26) + ' the link goes where it says')
	}

	// The two panes that carry a toolbar. Done after INSPECT because it leaves the
	// panes' display attributes set, and this needs to set them again anyway.
	for (const [paneIndex, name] of [[1, 'Alertas vigentes'], [2, 'Alertas del ISP']]) {
		const complaints = await inspectMenus(page, paneIndex)
		if (complaints.length === 0) {
			pass(`${name.padEnd(26)} counts in the filter menus`)
		} else {
			complaints.forEach((c) => fail(`${name} — count missing`, [c]))
		}
	}
}

await browser.close()

if (failures === 0) {
	console.log('\nAll good.\n')
} else {
	console.log(`\n${failures} check(s) failed\n`)
	process.exit(1)
}
