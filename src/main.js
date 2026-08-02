import { createApp } from 'vue'
import App from './App.vue'

// No fetch on mount: the server already holds both feeds in Redis and hands them
// over through IInitialState, so a round trip to ourselves would buy a spinner
// and nothing else. The OCS routes exist for the other consumers.
createApp(App).mount('#epidemiologia')
