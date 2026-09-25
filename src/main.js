import { createApp } from 'vue'
import App from './App.vue'

// No fetch on mount, and what arrives is whole.
//
// The page reads one `sources` initial-state key, provided by PageController from
// the same mint point the panes serve from — nothing is fetched here, ever.
//
// "Whole" used to be a measured claim about the ISP's alert archive (ADR-0009:
// 15,4 KB gzipped). That archive retired with the alertas sanitarias (ADR-0016),
// and what ships now is whole by construction instead: the in-force alertas set,
// and the informes series the table holds — the >100-row truncation trap the
// narrowing API existed for cannot exist where nothing can be narrowed, and the
// route has answered 400 to a narrowing parameter since 0.10.0.
createApp(App).mount('#epidemiologia')
