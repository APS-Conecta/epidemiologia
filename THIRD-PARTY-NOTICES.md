# Third-party notices — epidemiologia

This software is licensed under the GNU Affero General Public License v3.0 or later
(see [`LICENSE`](LICENSE)). It incorporates the third-party components listed below,
which are licensed by their own authors under their own terms. This file exists to
satisfy the attribution those licences require.

Generated from the dependency manifests. Regenerate with
`python3 repo-docs/scripts/docs.py licences epidemiologia` after changing dependencies.


## Bundled JavaScript dependencies

These are compiled into the files under `js/` that ship with this app.

| Licence | Component |
|---|---|
| AGPL-3.0-or-later | `@nextcloud/vue` |
| GPL-3.0-or-later | `@nextcloud/initial-state` |
| GPL-3.0-or-later | `@nextcloud/l10n` |
| MIT | `vue` |
| MIT | `vue-material-design-icons` |

## Copyleft components

`@nextcloud/vue` is AGPL-3.0-or-later and is compiled into the bundles under `js/`.
The Nextcloud packages under GPL-3.0-or-later are likewise linked into them. This is why
this app is AGPL-3.0-or-later and could not be licensed otherwise: the obligation is
inherited from what it links, not chosen.

Full licence texts for every component above are in `node_modules/<name>/LICENSE` in a
development checkout, and the build preserves each component's own copyright banner in
the `*.LICENSE.txt` file emitted next to each bundle.
