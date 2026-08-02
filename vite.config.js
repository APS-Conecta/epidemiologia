import { createAppConfig } from '@nextcloud/vite-config'

// App id and version come from appinfo/info.xml, which vite-config parses itself.
// Output: js/epidemiologia-main.mjs and css/epidemiologia-main.css.
export default createAppConfig(
	{ main: 'src/main.js' },
	{
		// EmptyJSDirPlugin only empties js/ — the name is literal. Without adding
		// css/ here, every build leaves the previous content-hashed stylesheet chunk
		// behind, and they accumulate in git one stale copy per rebuild.
		emptyOutputDirectory: { additionalDirectories: ['css'] },
	},
)
