import { createAppConfig } from '@nextcloud/vite-config'

// App id and version come from appinfo/info.xml, which vite-config parses itself.
// Output: js/epidemiologia-main.mjs and css/epidemiologia-main.css.
export default createAppConfig({ main: 'src/main.js' })
