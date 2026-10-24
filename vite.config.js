import { createAppConfig } from '@nextcloud/vite-config'

// App id and version come from appinfo/info.xml, which vite-config parses itself.
// Output: js/epidemiologia-main.mjs and css/epidemiologia-style.css.
export default createAppConfig(
	{ main: 'src/main.js' },
	{
		// EmptyJSDirPlugin only empties js/ — the name is literal. Without adding
		// css/ here, every build leaves the previous content-hashed stylesheet chunk
		// behind, and they accumulate in git one stale copy per rebuild.
		emptyOutputDirectory: { additionalDirectories: ['css'] },

		// One stylesheet, not a stub that @imports a content-hashed chunk.
		//
		// Nextcloud versions a linked stylesheet with ?v=<app cache buster>, but that
		// query does not reach an @import inside it. So a browser holding a cached
		// stub kept importing the PREVIOUS chunk name — a file the next build had
		// already deleted — and rendered the app with whatever CSS it still had, or
		// with none. Measured live: new JS, month-old CSS, no error anywhere.
		//
		// Per-component imports, never the '@nextcloud/vue' barrel (L3-13): the
		// package declares no `sideEffects`, so the barrel defeats tree-shaking
		// and shipped emoji-mart, vue-select, striptags and the axios adapter —
		// none used — at 1.08 MB for a 359 KB app. The js/ and css/
		// artifacts are rebuilt with this change; a new component import goes
		// through the same subpath or the bundle silently regrows.
		config: { build: { cssCodeSplit: false } },
	},
)
