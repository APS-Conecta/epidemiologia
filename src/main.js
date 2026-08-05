import { createApp } from 'vue'
import App from './App.vue'

// No fetch on mount, and the archive that arrives is the whole archive.
//
// This comment claimed for a few hours that only the newest slice ships and the
// depth is fetched on demand. It was never true, and ADR-0009 records the
// measurement that says it need not become true: the ISP payload is 15,4 KB
// gzipped, ~126 KB projected at the full 1.423-alert archive, on a page that is
// 41 KB. The OCS route's category and olderThan parameters exist for other apps
// (ADR-0006), not for this one.
createApp(App).mount('#epidemiologia')
