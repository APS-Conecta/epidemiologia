import { createApp } from 'vue'
import App from './App.vue'

// Still no fetch on mount, but the reasoning has changed and the old one no longer
// holds. It used to be that IInitialState carried EVERYTHING, so a round trip to
// ourselves bought a spinner and nothing else. Since #49 the ISP archive accretes
// past 1.423 alerts — ~357 KB of JSON in the HTML of every page load, paid by every
// user including the ones who never open the pane — so IInitialState now carries
// only the newest slice.
//
// Mount stays fetch-free because that slice is what the pane opens on. The depth is
// fetched on demand, by the pane, through the OCS route's category and olderThan
// parameters (#51). A fetch here would put the spinner back for everyone to load
// what almost nobody scrolls to.
createApp(App).mount('#epidemiologia')
