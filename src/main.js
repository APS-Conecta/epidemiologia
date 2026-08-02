/**
 * Toolchain smoke test, not UI.
 *
 * This file exists to prove the whole pipeline end to end before any real
 * interface is written against it: npm build → committed bundle → git pull →
 * Nextcloud serves it → the browser executes it → loadState() hands over data the
 * PHP side provided. Every one of those links can fail on its own, and finding
 * out during a four-pane rewrite is the expensive way.
 *
 * The frontend rewrite replaces this entirely.
 */
import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import App from './App.vue'

const mount = document.getElementById('epidemiologia-vue')
if (mount) {
	// Third argument on purpose: loadState() throws when the key is absent, and an
	// absent key is exactly what a forgotten rebuild or a renamed provider produces.
	const probe = loadState('epidemiologia', 'toolchain_probe', null)

	createApp(App, { probe }).mount(mount)
}
