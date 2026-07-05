# Contributing

Development guide for The Another SEO. The user-facing description is in
[`README.md`](README.md); release history is in [`CHANGELOG.md`](CHANGELOG.md).

## Maintainers

| Handle       | Role        |
| ------------ | ----------- |
| theanother   | Maintainer  |
| ziontrooper  | Maintainer  |

(Mirrors the `Contributors:` header in [`readme.txt`](readme.txt).)

## Architecture

The plugin bootstraps through a small core in `includes/`:

- **Container.php / HookManager.php / Plugin.php** — DI container, hook
  registration, and the top-level plugin lifecycle.
- **Installer.php** — activation/install lifecycle.
- **Blocks.php** — block registration (registers `blocks/breadcrumbs`, built to
  `dist/breadcrumbs/`).

Domain code is grouped by responsibility under `includes/`:

- **Admin** — settings screens and admin UI.
- **Breadcrumbs** — the breadcrumbs block's server-side logic.
- **Database** — the indexable table schema and access.
- **Indexable** — building and maintaining the indexable content index.
- **Meta** — templated titles and meta descriptions.
- **Schema** — Schema.org JSON-LD output.
- **Settings** — persisted plugin settings.
- **Sitemap** — chunked static XML sitemap generation.
- **Social** — Open Graph and Twitter Card meta output.

## Toolchain

Everything runs in Docker via `make`; CI runs the same
`scripts/setup/*` + `scripts/tests/*` scripts natively.

| Command             | What it does                                                    |
| ------------------- | --------------------------------------------------------------- |
| `make install-dev`  | Install composer deps incl. dev (in Docker).                    |
| `make lint`         | PHPCS (`scripts/tests/lint.sh`).                                |
| `make format`       | PHPCBF — **modifies source**.                                   |
| `make test`         | PHPUnit (`scripts/tests/unit.sh`).                              |
| `make coverage`     | PHPUnit with xdebug coverage.                                   |
| `make test-e2e`     | Functional Playwright suite (`scripts/tests/e2e.sh`).           |
| `make check-plugin` | WordPress.org Plugin Check (`scripts/tests/plugin-check.sh`).   |
| `make all`          | install-dev + lint + test.                                      |
| `make release`      | Build the distributable zip into `build/`.                      |
| `make version-patch` / `version-minor` / `version-major` | Bump version + promote `CHANGELOG.md` (no commit). |
| `make clean`        | Remove vendor/, node_modules/, caches, build output.            |

## Releasing

Use the `/deploy-plugin` skill on an open PR branch: it runs the full gate,
bumps the version, curates `CHANGELOG.md` + `readme.txt` from the PR, pushes,
and monitors CI. Merging the PR to `master` triggers
`.github/workflows/release.yml`, which re-gates, builds the zip, tags
`v<version>`, and publishes the GitHub Release.
