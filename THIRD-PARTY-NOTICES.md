# Third-party notices — epidemiologia

This software is licensed under the GNU Affero General Public License v3.0 or later
(see [`LICENSE`](LICENSE)). It incorporates the third-party components listed below,
which are licensed by their own authors under their own terms. This file exists to
satisfy the attribution those licences require.

**Derived from `js/epidemiologia-main.mjs.license`**, which the build emits beside the bundle and
which the release tarball carries, so the notices travel with the distribution as AGPL §4 requires.
That file is the
authority: it names every package the build actually pulled into `js/`, with its version. This one is
its summary, grouped by licence.

Regenerating is reading it again: run `npm run build`, then update the table from the `Included
packages:` list in `js/epidemiologia-main.mjs.license`. A manifest summary would read the
*manifests* instead, so it is a cross-check and never the source — the manifests name 5 runtime
dependencies and the bundle carries 44, because a dependency's own dependencies ship too.

## Bundled JavaScript dependencies

44 third-party components are compiled into the files under `js/`. Two entries in the emitted list are
not third-party or not distinct: `epidemiologia` is our own code, and `@floating-ui/dom` appears at two
versions.

| Licence | Components |
|---|---|
| AGPL-3.0-or-later | `@nextcloud/vue` |
| GPL-3.0-or-later | `@nextcloud/auth`, `@nextcloud/axios`, `@nextcloud/browser-storage`, `@nextcloud/capabilities`, `@nextcloud/event-bus`, `@nextcloud/initial-state`, `@nextcloud/l10n`, `@nextcloud/logger`, `@nextcloud/router` |
| MIT | `@floating-ui/core`, `@floating-ui/dom`, `@floating-ui/utils`, `@nextcloud/vue-select`, `@vitejs/plugin-vue`, `@vue/reactivity`, `@vue/runtime-core`, `@vue/runtime-dom`, `@vue/shared`, `@vuepic/vue-datepicker`, `@vueuse/core`, `@vueuse/shared`, `axios`, `date-fns`, `debounce`, `escape-html`, `eventemitter3`, `floating-vue`, `focus-trap`, `linkifyjs`, `p-queue`, `p-timeout`, `splitpanes`, `striptags`, `tabbable`, `tributejs`, `ts-md5`, `vite`, `vite-plugin-node-polyfills`, `vue-material-design-icons`, `vue-router` |
| BSD-3-Clause | `emoji-mart-vue-fast` |
| ISC | `semver` |
| MPL-2.0 OR Apache-2.0 | `dompurify` |

Two readings of that table would be wrong. **`vue` is absent because the bundle imports its runtime
packages** — `@vue/reactivity`, `@vue/runtime-core`, `@vue/runtime-dom`, `@vue/shared` — not the
umbrella package `package.json` names. And **`vite`, `@vitejs/plugin-vue` and
`vite-plugin-node-polyfills` are build-time packages** that the licence plugin records because they
contribute code or transforms to the output; they are left in, because over-attribution is permitted
by every licence here and omitting one is not.

## Copyleft components

`@nextcloud/vue` is AGPL-3.0-or-later and is compiled into the bundles under `js/`.
The nine Nextcloud packages under GPL-3.0-or-later are likewise linked into them. This is why
this app is AGPL-3.0-or-later and could not be licensed otherwise: the obligation is
inherited from what it links, not chosen.

`js/epidemiologia-main.mjs.license` carries an SPDX identifier and a copyright line per component,
**not the licence texts**: MIT and BSD ask for the text itself, so what ships is short of that. The
texts are in `node_modules/<name>/LICENSE` in a development checkout, and the gap closes by having
the build concatenate them — worth doing before this app is distributed to anyone outside the
organisation.
