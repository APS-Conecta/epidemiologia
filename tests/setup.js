/**
 * The smallest browser these tests need.
 *
 * `src/state.js` calls `loadState()` at module scope, and @nextcloud/initial-state
 * reads a `<script>` element out of the document. With no element it returns the
 * fallback, which is exactly the "no source reached the page" path the module is
 * written to survive — so answering nothing is both sufficient and the honest
 * state to test against.
 *
 * A full jsdom would be a dependency and a second environment for three lines.
 */
globalThis.window ??= globalThis
globalThis.document ??= {
	querySelector: () => null,
	getElementById: () => null,
	// @nextcloud/l10n reads the locale off <html data-locale>. Nextcloud serves this
	// instance in Spanish, so es_CL is what the app actually formats dates in — and
	// mes() must render "julio de 2026", never "July 2026".
	documentElement: { dataset: { locale: 'es_CL', language: 'es' }, lang: 'es' },
}
