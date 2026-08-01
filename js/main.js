/**
 * Tabs, the alert filter, and the deferred tablero. That is the whole frontend —
 * every row is already in the DOM, server-rendered, so nothing here fetches.
 */
(function () {
	'use strict'

	var tabs = Array.prototype.slice.call(document.querySelectorAll('.epi-tab'))
	if (!tabs.length) {
		return
	}

	function show(tab) {
		tabs.forEach(function (other) {
			var on = other === tab
			other.setAttribute('aria-selected', on ? 'true' : 'false')
			document.getElementById(other.getAttribute('aria-controls')).hidden = !on
		})

		// The report takes ~15-20 s to paint, so it is mounted on first open rather
		// than on page load: nobody reading the alert list should wait for it.
		var frame = document.querySelector('#p-tablero .epi-frame')
		if (tab.id === 't-tablero' && frame && !frame.src) {
			frame.src = frame.dataset.src
		}
	}

	tabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			show(tab)
		})
		tab.addEventListener('keydown', function (event) {
			var step = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key]
			if (!step) {
				return
			}
			event.preventDefault()
			var next = tabs[(tabs.indexOf(tab) + step + tabs.length) % tabs.length]
			next.focus()
			show(next)
		})
	})

	// --- filter: free text + year, over rows already on the page.
	// 75 alerts is ~15 KB; paginating that on the server would be theatre.
	var query = document.getElementById('epi-q')
	var year = document.getElementById('epi-anio')
	var count = document.getElementById('epi-conteo')
	var rows = Array.prototype.slice.call(document.querySelectorAll('#epi-alertas li'))
	if (!query || !year || !count) {
		return
	}
	var total = count.dataset.total

	// Accents folded on both sides, so "sarampion" typed in a hurry finds
	// "sarampión". MinsalRepository::fold() does the same for global search — two
	// languages, so the duplication cannot be removed, only kept honest.
	function fold(text) {
		return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
	}

	rows.forEach(function (row) {
		row.dataset.fold = fold(row.textContent)
	})

	function filter() {
		var needle = fold(query.value.trim())
		var pick = year.value
		var shown = 0

		rows.forEach(function (row) {
			var ok = (!needle || row.dataset.fold.indexOf(needle) >= 0)
				&& (!pick || row.dataset.year === pick)
			row.hidden = !ok
			if (ok) {
				shown++
			}
		})

		count.textContent = (needle || pick)
			? shown + ' de ' + total + ' alertas' + (shown ? '' : ' — sin coincidencias')
			: total + ' alertas vigentes'
	}

	query.addEventListener('input', filter)
	year.addEventListener('change', filter)
})()
