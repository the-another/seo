# Changelog

All notable changes to The Another SEO are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> How releases are cut: add notes under **[Unreleased]** as you work. Running `make version-patch|version-minor|version-major` promotes the `[Unreleased]` section here into a dated release entry, opens a fresh empty `[Unreleased]`, and retargets the comparison links below. (It separately appends a `* Version bump` stub to [`readme.txt`](readme.txt), the WordPress.org listing — replace that stub with the same notes when curating a release.)

## [Unreleased]

### Added
- Developer documentation: `README.md`, `CONTRIBUTORS.md`, and this `CHANGELOG.md`.
- Portable CI/CD pipeline: shared `scripts/setup/*` (toolchain) and `scripts/tests/*` (one suite each) shell scripts that run identically inside the local Docker images (now `ubuntu:24.04`-based) and natively on GitHub's `ubuntu-24.04` runners; a four-job PR gate (`.github/workflows/ci.yml` — PHPCS, PHPUnit, Functional E2E, Plugin Check); and a GitHub release pipeline (`.github/workflows/release.yml`) that, on every push to `master`, re-runs the full gate, builds the release zip, tags `v<version>` from `package.json`, and publishes a GitHub Release.
- `/deploy-plugin` project skill: preps a versioned release on the PR branch (full local gate, version bump, changelog curation, lock-file validation, push, CI monitoring).
- Site verification: `google-site-verification`, `msvalidate.01`, `yandex-verification`, `y_key`, and `facebook-domain-verification` meta tags on the front page, plus virtually-served verification files (`google<token>.html`, `BingSiteAuth.xml`, `yandex_<token>.html`) with byte-exact bodies and no file written to disk.
- Tracking snippets: GA4 (`gtag.js`), Google Tag Manager, and Meta Pixel, configured on a new **Webmaster Tools** settings tab.
- Filters for programmatic extension: `taseo_verification_tags`, `taseo_verification_files`, `taseo_verification_should_print`, `taseo_analytics_ga4_ids`, `taseo_analytics_gtm_ids`, `taseo_analytics_gtag_config`, `taseo_meta_pixel_ids`, and three consent gates — `taseo_tracking_should_print`, `taseo_analytics_should_print`, `taseo_meta_pixel_should_print`.

### Changed
- Both Docker base images moved Alpine 3.24 → `ubuntu:24.04`; the musl-Chromium, ffmpeg-symlink, and `memory_limit` workarounds are removed in favour of Playwright's own Chromium. The Playwright sandbox toggle is now `THE_ANOTHER_SEO_CHROMIUM_NO_SANDBOX`.

### Fixed
- Saving settings now returns you to the tab you were on instead of bouncing to General.

## [0.1.0] - 2026-07-02

### Added
- Initial release.
- Indexable content table built at catalog scale, with templated titles and meta descriptions.
- Open Graph and Twitter Card meta output.
- Schema.org JSON-LD structured data.
- Breadcrumbs block.
- Chunked static XML sitemaps.

[Unreleased]: https://github.com/the-another/seo/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/the-another/seo/releases/tag/v0.1.0
