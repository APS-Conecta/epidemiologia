import { createAppConfig } from '@nextcloud/vite-config'

// The app id and version come from appinfo/info.xml, which vite-config parses
// itself — so there is nothing to keep in sync here. Output lands at
// js/epidemiologia-main.mjs and css/epidemiologia-main.css.
export default createAppConfig(
	{ main: 'src/main.js' },
	{
		// EmptyJSDirPlugin wipes js/ before every build, which would delete the
		// hand-written js/main.js the current page still depends on. Off until the
		// frontend rewrite removes that file for real; turning it back on then is
		// one line, and until then a build must not break the running page.
		emptyOutputDirectory: false,
	},
)
