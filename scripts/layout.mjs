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
import { PANES, PANE_IDS } from '../src/panes.js'

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
// The third outcome, in the shape scripts/smoke.sh already prints it: a check that
// could not be made, named, and counted against nothing. Deliberately NOT a pass —
// "nobody could check this" and "this held" are different facts, and printing the
// first as the second is precisely how a gate goes quiet.
const skip = (m) => console.log(`    \x1b[33m--\x1b[0m    ${m}`)

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
	// '' and not 'block': v-show hides with an inline display:none, and clearing it
	// hands the pane back to its own stylesheet. Forcing block measured a layout
	// nobody sees — Inicio is a grid, and as a block its cards were never squeezed,
	// so check 2 passed at 1366px while the lab drew text 87px past its cards.
	panes.forEach((p, i) => { p.style.display = i === paneIndex ? '' : 'none' })
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

	//    ...and nothing a card holds is drawn outside it. Two boxes can sit apart and
	//    still overlap in effect: a card squeezed shorter than its content keeps its
	//    border where the grid put it and lets its text run on over the card below.
	//    Measured on the lab after 0.11.0 put six tiles on Inicio: at 1440×900 the
	//    ISP titles ran hundreds of pixels past their cards, and this gate passed —
	//    check 1 looks at widths and the loop above compares the boxes, not what is
	//    drawn in them. One complaint per card; 1px boxes are visually-hidden text.
	for (const card of pane.querySelectorAll('.epi-card')) {
		const c = box(card)
		for (const e of card.querySelectorAll('*')) {
			const r = box(e)
			if (r.width <= 1 || r.height <= 1) {
				continue
			}
			const past = Math.max(r.bottom - c.bottom, r.right - c.right, c.left - r.left, c.top - r.top)
			if (past > 1) {
				overlap.push(`${describe(e)} "${(e.textContent || '').trim().slice(0, 24)}" is drawn ${Math.round(past)}px outside its card`)
				break
			}
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
 * @param {boolean} empty - Whether this pane's fuente carries zero items, whatever the
 *   reason: the one state that makes the missing-options complaint unanswerable.
 * @return {Promise<{complaints: string[], names: string[]}>} One complaint per option
 *   that shows no count, and the radio names its menus render (check 10's input).
 */
const inspectMenus = async (page, paneIndex, empty) => {
	const complaints = []
	const names = []
	const menus = await page.evaluate((i) => {
		const panes = [...document.querySelectorAll('.epi > section')]
		panes.forEach((p, j) => { p.style.display = j === i ? '' : 'none' })
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

		const { options, radios } = await page.evaluate(() => {
			const popper = [...document.querySelectorAll('.v-popper__popper')].pop()
			return {
				options: [...popper.querySelectorAll('.action-radio__text')].map((e) => e.textContent.trim()),
				radios: [...popper.querySelectorAll('input[type="radio"]')].map((e) => e.name),
			}
		})
		names.push(...new Set(radios))

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
		// The pane carries no items, so tally() builds no options and the menu
		// legitimately holds nothing but its "todo" row. Degraded alone does not imply
		// this — AlertRepository::present() keeps serving the last GOOD copy while
		// degraded, so a fuente that degrades after having items still has items to
		// count — and an answered fuente can carry none: a category with nothing
		// published is a publisher saying zero. "Zero items" is the one fact that
		// empties the menus, whatever its reason, and the caller names the reason in
		// its skip. Going red here blames this app for a host it does not own or a
		// publisher's silence — the same DATA-reason red check 8 already refused to go.
		// Only THIS complaint is withheld: the menus are still opened, the pane must
		// still have them, and an option that does appear is still read for its count.
		if (options.length === 1 && !empty) {
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

	return { complaints, names }
}

/**
 * What the page says about one fuente — degraded or not, carrying items or not —
 * read the way the app reads it. Checks 6 and 11 turn on these two facts.
 *
 * Degraded alone does not mean empty: AlertRepository::degrade() answers through
 * present($stored, true), which keeps serving `$stored['items'] ?? []` — the last
 * GOOD copy — so a fuente that degrades after having items still has items, and its
 * menus should build year/month options same as ever. Nor does empty mean degraded:
 * a fuente can answer with nothing. The two facts are read apart and combined by
 * the check that needs them.
 *
 * A key the payload does not carry is both: src/state.js reads a missing key as
 * degraded and empty (L3-14), and that is what the pane renders — so the gate reads
 * it the same way, instead of as a fuente with items it cannot count.
 *
 * Read from the initial-state node rather than from the rendered DegradedNotice: that
 * node is the same contract src/state.js reads (PageController provides ONE `sources`
 * key for every fuente) and loadState() leaves it in the DOM, whereas the notice
 * is @nextcloud/vue markup that can be restyled without the fact changing.
 *
 * A payload that cannot be read fails closed, in the direction that keeps every
 * assertion RUNNING: not degraded, not empty. A detection that broke must leave the
 * gate red rather than quiet — unlike a genuinely empty fuente, which silences one
 * pane's count check, a broken read here would silence every pane at once.
 *
 * @param {import('playwright').Page} page - The page under test.
 * @param {string} fuente - The payload key, as Service/Sources.php spells it.
 * @return {Promise<{degraded: boolean, empty: boolean}>} The two facts.
 */
const fuenteState = (page, fuente) => page.evaluate((key) => {
	try {
		const source = JSON.parse(atob(document.querySelector('#initial-state-epidemiologia-sources').value))[key]
		if (source === undefined) {
			return { degraded: true, empty: true }
		}
		return {
			degraded: source.degraded === true,
			empty: Array.isArray(source.items) && source.items.length === 0,
		}
	} catch {
		return { degraded: false, empty: false }
	}
}, fuente)

/**
 * Check 12, Inicio's hero board, which needs clicks and a page as the app left it.
 *
 * One tile per alert sector, in the registry's order (src/panes.js), plus the
 * informe's card; each tile says whether its own fuente answered; and each tile's
 * button opens its own pane, headed by the name the tile carries. The three are
 * one walk: tile i is clicked and the registry's i-th sector must be the one pane
 * showing — which proves the order, the wiring and the naming together, without
 * this script knowing a single name (paneName() needs the page's l10n).
 *
 * "Says whether it answered" is compared with the payload, not with a guess: a
 * tile is gold exactly when its fuente is degraded — and a key the page does not
 * carry reads as degraded too (src/state.js, L3-14). From this server four
 * sectors are, so the comparison runs against live data both ways.
 *
 * @param {import('playwright').Page} page - A freshly loaded page, v-show untouched.
 * @return {Promise<string[]>} One complaint per way the board is wrong.
 */
const inspectBoard = async (page) => {
	const sectors = PANES.filter((p) => p.source)
	const board = await page.evaluate((keys) => {
		let state = null
		try {
			state = JSON.parse(atob(document.querySelector('#initial-state-epidemiologia-sources').value))
		} catch {
			return null
		}
		const inicio = document.querySelector('.epi > section.epi-inicio')
		const tiles = [...(inicio?.querySelectorAll('.epi-inicio__sources > .epi-card') ?? [])]

		return {
			tiles: tiles.map((tile) => ({
				name: tile.querySelector('h2')?.textContent.trim() ?? '',
				degraded: tile.classList.contains('epi-card--degraded'),
			})),
			degraded: keys.map((key) => state[key] === undefined || state[key].degraded === true),
			informe: inicio?.querySelectorAll(':scope > .epi-card').length ?? 0,
		}
	}, sectors.map((p) => p.source))

	if (board === null) {
		return ['the page carries no readable sources state, so the board cannot be checked against it']
	}
	const complaints = []
	if (board.tiles.length !== sectors.length) {
		complaints.push(`${board.tiles.length} tiles for ${sectors.length} sectors`)
	}
	if (board.informe !== 1) {
		complaints.push(`${board.informe} informe cards below the tiles, not one`)
	}
	board.tiles.forEach((tile, i) => {
		if (i < sectors.length && tile.degraded !== board.degraded[i]) {
			complaints.push(`"${tile.name}" is ${tile.degraded ? '' : 'not '}marked degraded while ${sectors[i].source} ${board.degraded[i] ? 'is' : 'is not'}`)
		}
	})

	for (const [i, sector] of sectors.entries()) {
		if (i >= board.tiles.length) {
			break
		}
		await page.evaluate((n) => {
			document.querySelectorAll('.epi-inicio .epi-inicio__sources > .epi-card')[n].querySelector('.epi-card__more').click()
		}, i)
		// The object, not the heading alone: an empty heading is falsy, and
		// waitForFunction would keep waiting on a pane that did open.
		const opened = await page.waitForFunction((index) => {
			const sections = [...document.querySelectorAll('.epi > section')]
			const shown = sections.filter((section) => getComputedStyle(section).display !== 'none')
			return shown.length === 1 && shown[0] === sections[index]
				? { heading: shown[0].querySelector('h2, h1')?.textContent.trim() ?? '' }
				: undefined
		}, PANE_IDS.indexOf(sector.id), { timeout: 3000 }).then((handle) => handle.jsonValue()).catch(() => null)
		if (opened === null) {
			complaints.push(`tile ${i + 1}, "${board.tiles[i].name}", does not open the ${sector.id} pane alone`)
		} else if (opened.heading !== board.tiles[i].name) {
			complaints.push(`tile "${board.tiles[i].name}" opens a pane headed "${opened.heading}"`)
		}
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
	// long enough to make the whole run tedious. The count is the registry's
	// ids (src/panes.js): no independent literal to disagree with it (L3-18).
	await page.waitForFunction(
		(count) => document.querySelectorAll('.epi > section').length === count,
		PANE_IDS.length,
		{ timeout: 20000 },
	)

	console.log(`  \x1b[1m${w}×${h}\x1b[0m`)

	// Check 12 first, while the page is as the app left it: the loops below force
	// every pane's display by hand, and this one needs v-show alone deciding which
	// pane shows.
	const board = await inspectBoard(page)
	if (board.length === 0) {
		pass(`${'Inicio'.padEnd(26)} one tile per sector, in order, degraded as its fuente, each opening its pane`)
	} else {
		board.forEach((c) => fail('Inicio — the hero board', [c]))
	}

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

	// (Check 8 — the family walk: open a family, go to Inicio, follow "Ver las
	// N alertas del ISP", assert the heading — is deleted with both ends of the
	// walk. The families retired with the alertas sanitarias (ADR-0016): no
	// family rows to open, no ISP card on Inicio to follow, no second
	// destination the heading could lie about. The fresh reload it took is
	// deleted with it: the menu loop below sets every pane's display attributes
	// itself, so nothing needs a clean load. The checks keep their numbers; 8
	// is the retirement's own footprint.)

	// Every pane that carries a toolbar is an alert sector, named here by its registry
	// id beside the fuente that fills its menus — derived from the registry (src/panes.js),
	// so a sector is checked the day it is added and no tuple is left to update. The DOM
	// index is the registry index: App.vue mounts one section per entry, in order.
	// ReportPane never carried a FilterBar, so the sectors are the whole list.
	const radioOwners = new Map()
	for (const { id, source: fuente } of PANES.filter((p) => p.source)) {
		const paneIndex = PANE_IDS.indexOf(id)
		const { degraded, empty } = await fuenteState(page, fuente)
		const { complaints, names } = await inspectMenus(page, paneIndex, empty)
		if (empty) {
			skip(`${id.padEnd(26)} the fuente ${fuente} ${degraded ? 'is degraded' : 'answered'} with no items: not checking the counts in its filter menus`)
		}
		// Not an `else`: the complaints that survive an empty fuente — a pane with no
		// menus at all, an `undefined` label — are still failures. And no pass is
		// printed beside the skip, because a check nobody made is not a check that held.
		if (complaints.length > 0) {
			complaints.forEach((c) => fail(`${id} — count missing`, [c]))
		} else if (!empty) {
			pass(`${id.padEnd(26)} counts in the filter menus`)
		}

		/*
		 * 10. one radio group per menu, per pane.
		 *
		 * Every sector is one AlertsPane and all six are mounted at once (v-show), while
		 * a native radio's `name` is document-wide: a name two panes render is ONE group
		 * spanning both, and choosing a year on one unticks the other's. The names carry
		 * the pane id; this is the place they are seen rendered. Read off every menu the
		 * loop above opened — a degraded pane's menus still render their "todo" radio —
		 * and two menus of one pane sharing a name is the same fault (FilterMenu's prop).
		 */
		const shared = names.filter((name, i) => radioOwners.has(name) || names.indexOf(name) !== i)
		if (shared.length > 0) {
			fail(`${id} — a radio group shared across menus`, shared.map((n) => (radioOwners.has(n)
				? `"${n}" is also ${radioOwners.get(n)}'s`
				: `"${n}" names two of this pane's menus`)))
		} else if (names.length > 0) {
			pass(`${id.padEnd(26)} radio groups of its own (${names.join(', ')})`)
		}
		names.forEach((name) => radioOwners.set(name, radioOwners.get(name) ?? id))

		/*
		 * 11. a fuente nobody could read is never shown as a fuente with nothing.
		 *
		 * Degraded with zero items, the pane owes its reader the warning and nothing
		 * else: an "Sin alertas" beside it is the publisher-said-zero claim L3-14 exists
		 * to refuse. From a datacentre four sectors are in exactly this state (a
		 * Cloudflare 403, an ISP timeout), so this runs against live data rather than a
		 * hypothetical. A fuente that answered, or serves a last copy, cannot be in it.
		 */
		if (!degraded || !empty) {
			skip(`${id.padEnd(26)} the fuente ${fuente} ${degraded ? 'serves its last copy' : 'answered'}: no unread, empty state to check`)
		} else if (await page.evaluate((i) => !!document.querySelectorAll('.epi > section')[i].querySelector('.empty-content'), paneIndex)) {
			fail(`${id} — a fuente nobody could read is shown as empty`, ['the pane renders an empty-content block beside the degraded notice'])
		} else {
			pass(`${id.padEnd(26)} unread, and not shown as empty`)
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
