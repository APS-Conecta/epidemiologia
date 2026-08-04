import { defineConfig } from 'vitest/config'

// Vitest reuses the Vite that @nextcloud/vite-config already puts in the tree, so
// this is a runner rather than a second toolchain. It covers the pure logic in
// src/ — the option shapes the panes share — and deliberately not components:
// what a rendered pane looks like is scripts/layout.sh's job, against a real
// browser and a real instance.
export default defineConfig({
	test: {
		include: ['tests/unit/**/*.test.js'],
		// No jsdom. state.js calls loadState() at module scope, which only needs a
		// querySelector that answers nothing before it falls back — see setup.js.
		environment: 'node',
		setupFiles: ['tests/setup.js'],
	},
})
