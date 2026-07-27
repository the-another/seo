# Image Fields: Media Picker and Override Filters — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the four attachment-ID number boxes with core's media picker, give each image slot a URL override, and make every resolved image URL filterable.

**Architecture:** Each image slot keeps its existing attachment-ID field (now a hidden input driven by `wp.media`) and gains a URL sibling that wins when set. A shared `Meta\ImageResolver` owns "first non-empty candidate" and "attachment ID → URL" so `SocialOutput` and `SchemaGraph` do not each grow their own copy. A shared `Admin\ImageField` renders identical markup on the settings page, the post metabox, and term edit screens.

**Tech Stack:** PHP 8.2+, WordPress, `wp.media` via `wp_enqueue_media()`, `@wordpress/scripts` build, PHPUnit 11 + Brain Monkey + Mockery, Playwright.

## Global Constraints

- **Established WordPress components only. No stylesheet, no custom CSS class.** Core classes (`button`, `large-text`, `p.description`) only.
- **WPCS:** tabs for indentation, `array()` long syntax, Yoda conditions, no closing `?>`, `@param`/`@return` on every docblock, text domain `the-another-seo`.
- **Never add `wp_localize_script`** or a second JSON copy of anything already serialised into the DOM.
- **Do not bump any version number and do not edit `CHANGELOG.md`** except where a task says to.
- **The stored attachment ID keeps its exact field name** in every form, so existing sanitizers and stored data keep working.
- **Everything must degrade** with JavaScript off: the hidden ID input still submits its stored value, and the URL field is a plain text input.
- New indexables columns are `TEXT NULL`, matching `permalink` and `canonical_url` in that table.
- `Plugin::maybe_flag_upgrade_backfill()`'s `version_compare( $installed, '1.1.0', '<' )` condition **must not change**. These columns need no backfill; widening it triggers a full-catalog resync for nothing.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `includes/Meta/ImageResolver.php` | Two static helpers: first non-empty candidate, and attachment ID → URL. Shared by `SocialOutput` and `SchemaGraph`. |
| `includes/Admin/ImageField.php` | Renders one image field's markup (hidden ID input, preview, Select/Remove buttons, URL override input). Used by `SettingsPage` and `Metabox`. |
| `assets/src/media-picker/index.js` | Binds `wp.media` to every `[data-taseo-image-field]` on the page. |
| `tests/Unit/Meta/ImageResolverTest.php` | Unit tests for the resolver. |
| `tests/Unit/Admin/ImageFieldTest.php` | Unit tests for the markup. |

**Modified:**

| File | Change |
|---|---|
| `includes/Database/IndexablesTable.php` | Two `TEXT NULL` columns; `DB_VERSION` `1.1.0` → `1.2.0`. |
| `includes/Indexable/IndexableRepository.php` | `OVERRIDE_COLUMNS` gains both column names. |
| `includes/Settings/Settings.php` | `get_default_social_image_url()`, `get_site_logo_url()`. |
| `includes/Admin/SettingsPage.php` | Renders both sitewide slots through `ImageField`; sanitizes both URL keys; enqueues the picker. |
| `includes/Admin/Metabox.php` | `FIELDS` gains the two URL fields; `render_fields()` renders image slots through `ImageField`; enqueues the picker on post and term screens. |
| `includes/Social/SocialOutput.php` | OG and Twitter resolution chains plus their filters. |
| `includes/Schema/SchemaGraph.php` | Logo resolution chain plus its filter. |
| `package.json` | Third build entry for the media-picker bundle. |

---

## Task 1: Database columns and the repository allowlist

**Files:**
- Modify: `includes/Database/IndexablesTable.php:24` (`DB_VERSION`), `includes/Database/IndexablesTable.php:49-85` (`get_schema()`)
- Modify: `includes/Indexable/IndexableRepository.php:28-43` (`OVERRIDE_COLUMNS`)
- Test: `tests/Unit/Database/IndexablesTableTest.php`, `tests/Unit/Indexable/IndexableRepositoryTest.php`, `tests/Unit/PluginTest.php`

**Interfaces:**
- Produces: two new columns named `og_image_url` and `twitter_image_url`, both `TEXT NULL`, readable through `IndexableRepository::find()` in the same row array as `og_image_id`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Database/IndexablesTableTest.php`:

```php
	public function test_schema_declares_the_image_url_override_columns(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4' );

		$schema = IndexablesTable::get_schema();

		// TEXT NULL, matching permalink and canonical_url in this same table.
		// dbDelta is exacting about type spelling, so the type is asserted,
		// not merely the column name.
		$this->assertStringContainsString( 'og_image_url TEXT NULL', $schema );
		$this->assertStringContainsString( 'twitter_image_url TEXT NULL', $schema );
	}

	public function test_db_version_is_bumped_for_the_new_columns(): void {
		$this->assertSame( '1.2.0', IndexablesTable::DB_VERSION );
	}
```

Add to `tests/Unit/Indexable/IndexableRepositoryTest.php`:

```php
	/**
	 * OVERRIDE_COLUMNS is an allowlist: a column missing from it is dropped
	 * on write with no error at all, so the column existing in the schema is
	 * not enough on its own.
	 */
	public function test_override_columns_allows_the_image_url_overrides(): void {
		$this->assertContains( 'og_image_url', IndexableRepository::OVERRIDE_COLUMNS );
		$this->assertContains( 'twitter_image_url', IndexableRepository::OVERRIDE_COLUMNS );
	}
```

Add to `tests/Unit/PluginTest.php`:

```php
	/**
	 * The 1.1.0 upgrade needed a backfill because its new columns held values
	 * that had to be derived for existing rows. The image URL overrides have
	 * no derived value — absent means "not set", which is what NULL already
	 * means — so a 1.1.0 to 1.2.0 upgrade must NOT trigger a full-catalog
	 * resync. This test fails if someone widens the version_compare bound
	 * along with the DB_VERSION bump.
	 */
	public function test_upgrade_from_1_1_0_does_not_flag_a_backfill(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'taseo_db_version' === $name ? '1.1.0' : $default
		);

		$flagged = array();

		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$flagged ): bool {
				$flagged[ $name ] = $value;

				return true;
			}
		);

		$this->invoke_maybe_flag_upgrade_backfill();

		$this->assertArrayNotHasKey( Installer::NEEDS_BACKFILL_OPTION, $flagged );
	}
```

`invoke_maybe_flag_upgrade_backfill()` is a private-method call; use the same reflection helper the surrounding file already uses for private methods. If `PluginTest.php` has no such helper, add:

```php
	private function invoke_maybe_flag_upgrade_backfill(): void {
		$method = new \ReflectionMethod( Plugin::class, 'maybe_flag_upgrade_backfill' );
		$method->setAccessible( true );
		$method->invoke( $this->plugin );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — `og_image_url TEXT NULL` not found in schema; `'1.1.0'` is not `'1.2.0'`; `og_image_url` not in `OVERRIDE_COLUMNS`.

The backfill test should **pass already** — it is a regression guard, not a driver. Confirm it passes now, and say so in your report; if it fails now, stop and report, because that means the current code already over-flags.

- [ ] **Step 3: Add the columns and bump the version**

In `includes/Database/IndexablesTable.php`, inside `get_schema()`'s `CREATE TABLE`, immediately after `og_image_id BIGINT UNSIGNED NULL,`:

```sql
			og_image_url TEXT NULL,
```

and immediately after `twitter_image_id BIGINT UNSIGNED NULL,`:

```sql
			twitter_image_url TEXT NULL,
```

Change the constant:

```php
	public const DB_VERSION = '1.2.0';
```

- [ ] **Step 4: Add both columns to the repository allowlist**

In `includes/Indexable/IndexableRepository.php`, inside `OVERRIDE_COLUMNS`, after `'og_image_id',`:

```php
		'og_image_url',
```

and after `'twitter_image_id',`:

```php
		'twitter_image_url',
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `make test`
Expected: PASS, with a higher test count than before.

- [ ] **Step 6: Commit**

```bash
git add includes/Database/IndexablesTable.php includes/Indexable/IndexableRepository.php tests/
git commit -m "feat: add image URL override columns to the indexables table"
```

---

## Task 2: `Meta\ImageResolver`

**Files:**
- Create: `includes/Meta/ImageResolver.php`
- Test: `tests/Unit/Meta/ImageResolverTest.php`

**Interfaces:**
- Produces:
  - `ImageResolver::attachment_url( int $id ): string` — `''` when `$id <= 0` or the attachment cannot resolve.
  - `ImageResolver::first( array $candidates ): string` — first non-empty string, `''` when all are empty.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/ImageResolverTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\ImageResolver;

#[CoversClass( ImageResolver::class )]
class ImageResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_attachment_url_returns_the_resolved_url(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/img.jpg' );

		$this->assertSame( 'https://example.com/img.jpg', ImageResolver::attachment_url( 42 ) );
	}

	public function test_attachment_url_asks_for_the_full_size(): void {
		$seen = array();

		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( $id, $size ) use ( &$seen ): string {
				$seen = array( $id, $size );

				return 'https://example.com/img.jpg';
			}
		);

		ImageResolver::attachment_url( 42 );

		$this->assertSame( array( 42, 'full' ), $seen );
	}

	public function test_attachment_url_is_empty_for_a_missing_id(): void {
		$this->assertSame( '', ImageResolver::attachment_url( 0 ) );
		$this->assertSame( '', ImageResolver::attachment_url( -1 ) );
	}

	/**
	 * A deleted attachment leaves its ID behind in the row. WordPress returns
	 * false, which must become '' so the caller falls through to the next
	 * candidate rather than emitting content="".
	 */
	public function test_attachment_url_is_empty_when_the_attachment_is_gone(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		$this->assertSame( '', ImageResolver::attachment_url( 42 ) );
	}

	public function test_first_returns_the_first_non_empty_candidate(): void {
		$this->assertSame(
			'https://example.com/second.jpg',
			ImageResolver::first( array( '', 'https://example.com/second.jpg', 'https://example.com/third.jpg' ) )
		);
	}

	public function test_first_is_empty_when_every_candidate_is_empty(): void {
		$this->assertSame( '', ImageResolver::first( array( '', '', '' ) ) );
		$this->assertSame( '', ImageResolver::first( array() ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL with `Class "TheAnother\Plugin\SEO\Meta\ImageResolver" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Meta/ImageResolver.php`:

```php
<?php
/**
 * Shared image URL resolution.
 *
 * @package TheAnother\SEO
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Turns image candidates into a URL.
 *
 * Both the social output and the schema graph resolve an image the same way —
 * an explicit URL override beats an attachment ID, and a missing attachment
 * falls through rather than emitting an empty value. This holds that logic
 * once so the two cannot drift apart.
 */
final class ImageResolver {

	/**
	 * URL for an attachment ID.
	 *
	 * Returns '' rather than false for an attachment that no longer exists,
	 * so callers can treat "not set" and "deleted since" identically and fall
	 * through to their next candidate.
	 *
	 * @param int $id Attachment ID.
	 * @return string URL, or '' when it cannot resolve.
	 */
	public static function attachment_url( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'full' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * First non-empty candidate.
	 *
	 * @param array<int, string> $candidates Ordered candidates, most specific first.
	 * @return string First non-empty candidate, or ''.
	 */
	public static function first( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Meta/ImageResolver.php tests/Unit/Meta/ImageResolverTest.php
git commit -m "feat: add a shared image URL resolver"
```

---

## Task 3: Storage — settings getters and both sanitizers

**Files:**
- Modify: `includes/Settings/Settings.php` (after `get_default_social_image_id()` at :188 and `get_site_logo_id()` at :251)
- Modify: `includes/Admin/SettingsPage.php:1126-1130` (the `absint` loop in `sanitize_settings()`)
- Modify: `includes/Admin/Metabox.php:30-45` (`FIELDS`)
- Test: `tests/Unit/Settings/SettingsTest.php`, `tests/Unit/Admin/SettingsPageTest.php`, `tests/Unit/Admin/MetaboxTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `Settings::get_default_social_image_url(): string`
  - `Settings::get_site_logo_url(): string`
  - Settings keys `default_social_image_url` and `site_logo_url`, both sanitized with `esc_url_raw()`.
  - `Metabox::FIELDS` entries `'og_image_url' => 'url'` and `'twitter_image_url' => 'url'`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Settings/SettingsTest.php`:

```php
	public function test_image_url_overrides_default_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', $this->settings->get_default_social_image_url() );
		$this->assertSame( '', $this->settings->get_site_logo_url() );
	}

	public function test_image_url_overrides_are_read_from_the_option(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'default_social_image_url' => 'https://cdn.example.com/social.jpg',
				'site_logo_url'            => 'https://cdn.example.com/logo.png',
			)
		);

		$this->assertSame( 'https://cdn.example.com/social.jpg', $this->settings->get_default_social_image_url() );
		$this->assertSame( 'https://cdn.example.com/logo.png', $this->settings->get_site_logo_url() );
	}
```

Add to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	public function test_sanitize_stores_the_image_url_overrides(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'default_social_image_url' => 'https://cdn.example.com/social.jpg',
				'site_logo_url'            => 'https://cdn.example.com/logo.png',
			),
			'social'
		);

		$this->assertSame( 'https://cdn.example.com/social.jpg', $clean['default_social_image_url'] );
		$this->assertSame( 'https://cdn.example.com/logo.png', $clean['site_logo_url'] );
	}

	/**
	 * The URL override is a sibling of the ID, not a replacement: saving one
	 * must not disturb the other.
	 */
	public function test_sanitize_keeps_the_attachment_ids_alongside_the_urls(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'default_social_image_id'  => '42',
				'default_social_image_url' => 'https://cdn.example.com/social.jpg',
			),
			'social'
		);

		$this->assertSame( 42, $clean['default_social_image_id'] );
		$this->assertSame( 'https://cdn.example.com/social.jpg', $clean['default_social_image_url'] );
	}
```

`esc_url_raw` is stubbed as `returnArg()` in that file's `setUp()` already; if it is not, add `Functions\when( 'esc_url_raw' )->returnArg();` there.

Add to `tests/Unit/Admin/MetaboxTest.php`:

```php
	public function test_image_url_overrides_are_registered_as_url_fields(): void {
		$this->assertSame( 'url', Metabox::FIELDS['og_image_url'] );
		$this->assertSame( 'url', Metabox::FIELDS['twitter_image_url'] );
	}

	public function test_sanitize_runs_image_url_overrides_through_esc_url_raw(): void {
		$seen = array();

		Functions\when( 'esc_url_raw' )->alias(
			static function ( $value ) use ( &$seen ): string {
				$seen[] = $value;

				return $value;
			}
		);

		$clean = $this->metabox->sanitize_submission(
			array(
				'og_image_url'      => 'https://cdn.example.com/og.jpg',
				'twitter_image_url' => 'https://cdn.example.com/tw.jpg',
			)
		);

		$this->assertSame( 'https://cdn.example.com/og.jpg', $clean['og_image_url'] );
		$this->assertSame( 'https://cdn.example.com/tw.jpg', $clean['twitter_image_url'] );
		$this->assertContains( 'https://cdn.example.com/og.jpg', $seen );
	}
```

`sanitize_submission()` is public and `$this->metabox` already exists in that file's `setUp()` — no reflection helper is needed.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — `Call to undefined method …get_default_social_image_url()`, and the `Metabox::FIELDS` keys are undefined.

- [ ] **Step 3: Add the settings getters**

In `includes/Settings/Settings.php`, directly after `get_default_social_image_id()`:

```php
	/**
	 * Sitewide fallback social image URL, overriding the attachment.
	 *
	 * @return string URL, or '' when unset.
	 */
	public function get_default_social_image_url(): string {
		return (string) $this->get( 'default_social_image_url', '' );
	}
```

and directly after `get_site_logo_id()`:

```php
	/**
	 * Logo URL for the Organization node, overriding the attachment.
	 *
	 * @return string URL, or '' when unset.
	 */
	public function get_site_logo_url(): string {
		return (string) $this->get( 'site_logo_url', '' );
	}
```

- [ ] **Step 4: Sanitize both settings keys**

In `includes/Admin/SettingsPage.php`, immediately after the existing loop over `array( 'default_social_image_id', 'site_logo_id' )`:

```php
		foreach ( array( 'default_social_image_url', 'site_logo_url' ) as $url_key ) {
			if ( isset( $raw[ $url_key ] ) ) {
				$clean[ $url_key ] = esc_url_raw( (string) $raw[ $url_key ] );
			}
		}
```

- [ ] **Step 5: Register the metabox fields**

In `includes/Admin/Metabox.php`, inside `FIELDS`, after `'og_image_id' => 'image_id',`:

```php
		'og_image_url'        => 'url',
```

and after `'twitter_image_id' => 'image_id',`:

```php
		'twitter_image_url'   => 'url',
```

Keep the `=>` alignment of the surrounding array; WPCS enforces it.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `make test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/Settings/Settings.php includes/Admin/SettingsPage.php includes/Admin/Metabox.php tests/
git commit -m "feat: store an image URL override beside each attachment ID"
```

---

## Task 4: `Admin\ImageField` and its three render sites

**Files:**
- Create: `includes/Admin/ImageField.php`
- Modify: `includes/Admin/SettingsPage.php:665-670` (default social image row), `includes/Admin/SettingsPage.php:709-714` (logo row)
- Modify: `includes/Admin/Metabox.php:128-148` (`render_fields()`)
- Test: `tests/Unit/Admin/ImageFieldTest.php`, `tests/Unit/Admin/SettingsPageTest.php`, `tests/Unit/Admin/MetaboxTest.php`

**Interfaces:**
- Consumes: settings getters and `Metabox::FIELDS` entries from Task 3.
- Produces: `ImageField::render( string $id_name, int $id_value, string $url_name, string $url_value, string $html_id ): void`, echoing a `[data-taseo-image-field]` wrapper containing `[data-taseo-image-id]`, `[data-taseo-image-select]`, `[data-taseo-image-remove]`, an optional `[data-taseo-image-preview]`, and a URL input with `id="{$html_id}-url"`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Admin/ImageFieldTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\ImageField;

#[CoversClass( ImageField::class )]
class ImageFieldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/thumb.jpg' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( int $id_value = 0, string $url_value = '' ): string {
		ob_start();
		ImageField::render(
			'taseo_settings[default_social_image_id]',
			$id_value,
			'taseo_settings[default_social_image_url]',
			$url_value,
			'taseo-default-social-image'
		);

		return (string) ob_get_clean();
	}

	/**
	 * The ID travels in a hidden input under its original field name, so the
	 * form submits exactly what it submitted before and every existing
	 * sanitizer keeps working untouched.
	 */
	public function test_the_attachment_id_is_submitted_under_its_original_name(): void {
		$html = $this->render( 42 );

		$this->assertStringContainsString( 'type="hidden"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[default_social_image_id]"', $html );
		$this->assertStringContainsString( 'value="42"', $html );
	}

	public function test_it_renders_select_and_remove_buttons_using_core_classes(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-taseo-image-select', $html );
		$this->assertStringContainsString( 'data-taseo-image-remove', $html );
		$this->assertStringContainsString( 'class="button"', $html );
	}

	public function test_it_renders_a_labelled_url_override_input(): void {
		$html = $this->render( 0, 'https://cdn.example.com/social.jpg' );

		$this->assertStringContainsString( 'name="taseo_settings[default_social_image_url]"', $html );
		$this->assertStringContainsString( 'id="taseo-default-social-image-url"', $html );
		$this->assertStringContainsString( 'for="taseo-default-social-image-url"', $html );
		$this->assertStringContainsString( 'https://cdn.example.com/social.jpg', $html );
	}

	public function test_a_preview_renders_only_when_an_attachment_is_set(): void {
		$this->assertStringNotContainsString( 'data-taseo-image-preview', $this->render( 0 ) );
		$this->assertStringContainsString( 'data-taseo-image-preview', $this->render( 42 ) );
	}

	/**
	 * The wrapper is how the picker script finds its fields; without it the
	 * markup renders but nothing binds to it.
	 */
	public function test_the_wrapper_carries_the_hook_the_script_binds_to(): void {
		$this->assertStringContainsString( 'data-taseo-image-field', $this->render() );
	}

	/**
	 * No stylesheet ships with this plugin, so every class here must be one
	 * WordPress already defines. `data-taseo-image-*` attributes are the
	 * script's binding hooks and are fine; a class of our own is not, which
	 * is why this asserts on `class="taseo` rather than on the bare string
	 * `taseo-image` — the latter appears in every data attribute and would
	 * fail against correct markup.
	 */
	public function test_it_introduces_no_class_of_our_own(): void {
		$this->assertStringNotContainsString( 'class="taseo', $this->render( 42 ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL with `Class "TheAnother\Plugin\SEO\Admin\ImageField" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Admin/ImageField.php`:

```php
<?php
/**
 * One image field: attachment picker plus URL override.
 *
 * @package TheAnother\SEO
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Admin;

/**
 * Renders an image field.
 *
 * The attachment ID travels in a hidden input under the field name it always
 * had, so the submitted form is unchanged and every existing sanitizer keeps
 * working. The picker script fills that input; with JavaScript off the input
 * still submits whatever was stored, and the URL override beside it is an
 * ordinary text field — so the field degrades to something usable rather than
 * silently discarding what was saved.
 *
 * Core classes only: `button` and `large-text`. No stylesheet ships with this
 * plugin.
 */
final class ImageField {

	/**
	 * Render one image field.
	 *
	 * @param string $id_name   Form name for the attachment ID input.
	 * @param int    $id_value  Current attachment ID, 0 when unset.
	 * @param string $url_name  Form name for the URL override input.
	 * @param string $url_value Current URL override, '' when unset.
	 * @param string $html_id   Prefix for this field's HTML ids.
	 * @return void
	 */
	public static function render(
		string $id_name,
		int $id_value,
		string $url_name,
		string $url_value,
		string $html_id
	): void {
		printf(
			'<div data-taseo-image-field><input type="hidden" name="%1$s" value="%2$d" data-taseo-image-id />',
			esc_attr( $id_name ),
			$id_value
		);

		if ( $id_value > 0 ) {
			$preview = wp_get_attachment_image_url( $id_value, 'thumbnail' );

			if ( is_string( $preview ) ) {
				printf(
					'<img src="%s" alt="" width="80" height="80" data-taseo-image-preview /><br />',
					esc_url( $preview )
				);
			}
		}

		printf(
			'<button type="button" class="button" data-taseo-image-select>%1$s</button> <button type="button" class="button" data-taseo-image-remove>%2$s</button>',
			esc_html__( 'Select image', 'the-another-seo' ),
			esc_html__( 'Remove', 'the-another-seo' )
		);

		printf(
			'<p><label for="%1$s-url">%2$s</label><br /><input type="url" id="%1$s-url" name="%3$s" value="%4$s" class="large-text" placeholder="https://…" /></p>',
			esc_attr( $html_id ),
			esc_html__( 'Image URL (overrides the selected image)', 'the-another-seo' ),
			esc_attr( $url_name ),
			esc_attr( $url_value )
		);

		echo '</div>';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Render both settings rows through it**

In `includes/Admin/SettingsPage.php`, replace the default social image row:

```php
		echo '<tr><th scope="row">' . esc_html__( 'Default social image', 'the-another-seo' ) . '</th><td>';
		ImageField::render(
			'taseo_settings[default_social_image_id]',
			(int) $this->settings->get_default_social_image_id(),
			'taseo_settings[default_social_image_url]',
			$this->settings->get_default_social_image_url(),
			'taseo-default-social-image'
		);
		echo '</td></tr>';
```

and the logo row:

```php
		echo '<tr><th scope="row">' . esc_html__( 'Logo', 'the-another-seo' ) . '</th><td>';
		ImageField::render(
			'taseo_settings[site_logo_id]',
			(int) $this->settings->get_site_logo_id(),
			'taseo_settings[site_logo_url]',
			$this->settings->get_site_logo_url(),
			'taseo-site-logo'
		);
		echo '</td></tr>';
```

Add `use TheAnother\Plugin\SEO\Admin\ImageField;` only if `SettingsPage` is in a different namespace — it is not, both are `TheAnother\Plugin\SEO\Admin`, so no import is needed.

- [ ] **Step 6: Render the metabox image slots through it**

In `includes/Admin/Metabox.php`, `render_fields()` currently branches checkbox / textarea / else-text, and both `image_id` fields fall into the text branch. Give them their own branch, and skip the `url` partners because `ImageField` renders them:

```php
		foreach ( self::FIELDS as $field => $type ) {
			$value = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			$label = ucwords( str_replace( '_', ' ', $field ) );
			$name  = 'taseo_meta[' . $field . ']';

			// The URL override is rendered by ImageField alongside its
			// attachment ID, so it must not also render on its own here.
			if ( 'og_image_url' === $field || 'twitter_image_url' === $field ) {
				continue;
			}

			echo '<p>';

			if ( 'image_id' === $type ) {
				$url_field = str_replace( '_id', '_url', $field );

				echo '<label>' . esc_html( $label ) . '</label><br />';
				ImageField::render(
					$name,
					(int) $value,
					'taseo_meta[' . $url_field . ']',
					isset( $row[ $url_field ] ) ? (string) $row[ $url_field ] : '',
					'taseo-meta-' . str_replace( '_', '-', $field )
				);
			} elseif ( 'checkbox' === $type ) {
```

Leave the remaining `checkbox` / `textarea` / `else` branches exactly as they are.

- [ ] **Step 7: Write and run tests for the two render sites**

Add to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	public function test_social_tab_renders_an_image_picker_not_a_number_box(): void {
		$_GET['tab'] = 'social';

		$html = $this->render_page();

		$this->assertStringContainsString( 'data-taseo-image-field', $html );
		$this->assertStringContainsString( 'name="taseo_settings[default_social_image_url]"', $html );
		$this->assertStringNotContainsString(
			'<input type="number" name="taseo_settings[default_social_image_id]"',
			$html
		);
	}
```

Add to `tests/Unit/Admin/MetaboxTest.php`:

```php
	/**
	 * Both image slots previously fell through to the plain-text branch and
	 * rendered as bare boxes labelled "Og Image Id".
	 */
	public function test_metabox_renders_image_slots_as_pickers(): void {
		$html = $this->render_metabox_fields( array( 'og_image_id' => '42' ) );

		$this->assertStringContainsString( 'data-taseo-image-field', $html );
		$this->assertStringContainsString( 'name="taseo_meta[og_image_url]"', $html );
	}

	/**
	 * The URL override must appear exactly once — ImageField renders it, so
	 * the field loop must not render it a second time.
	 */
	public function test_the_url_override_is_not_rendered_twice(): void {
		$html = $this->render_metabox_fields( array() );

		$this->assertSame( 1, substr_count( $html, 'name="taseo_meta[og_image_url]"' ) );
	}
```

`render_fields()` is private and `MetaboxTest.php` has no rendering helper today, so add one:

```php
	private function render_metabox_fields( array $row ): string {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/thumb.jpg' );

		$method = new \ReflectionMethod( Metabox::class, 'render_fields' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->metabox, $row );

		return (string) ob_get_clean();
	}
```

Run: `make test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/Admin/ImageField.php includes/Admin/SettingsPage.php includes/Admin/Metabox.php tests/
git commit -m "feat: render every image field as a picker with a URL override"
```

---

## Task 5: Resolution chains and the three filters

**Files:**
- Modify: `includes/Social/SocialOutput.php:140-143` (Twitter image), `includes/Social/SocialOutput.php:161-173` (`resolve_image_url()`)
- Modify: `includes/Schema/SchemaGraph.php:113-121` (logo)
- Test: `tests/Unit/Social/SocialOutputTest.php`, `tests/Unit/Schema/SchemaGraphTest.php`

**Interfaces:**
- Consumes: `ImageResolver::first()` and `ImageResolver::attachment_url()` from Task 2; `Settings::get_default_social_image_url()` and `get_site_logo_url()` from Task 3; row keys `og_image_url` / `twitter_image_url` from Task 1.
- Produces: filters `taseo_og_image_url( string $url, array $ctx )`, `taseo_twitter_image_url( string $url, array $ctx )`, `taseo_logo_url( string $url )`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Social/SocialOutputTest.php`:

```php
	public function test_a_row_image_url_beats_the_row_attachment(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/attachment.jpg' );

		$html = $this->render_social(
			array(
				'og_image_url' => 'https://cdn.example.com/override.jpg',
				'og_image_id'  => 42,
			)
		);

		$this->assertStringContainsString( 'https://cdn.example.com/override.jpg', $html );
		$this->assertStringNotContainsString( 'https://example.com/attachment.jpg', $html );
	}

	public function test_the_sitewide_url_is_used_when_the_row_has_nothing(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );
		$this->settings->shouldReceive( 'get_default_social_image_url' )->andReturn( 'https://cdn.example.com/site.jpg' );

		$html = $this->render_social( array() );

		$this->assertStringContainsString( 'https://cdn.example.com/site.jpg', $html );
	}

	/**
	 * The filter is applied last, so add_filter is always the final word.
	 */
	public function test_the_og_image_filter_wins_over_every_stored_value(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->andReturn( 'https://filtered.example.com/og.jpg' );

		$html = $this->render_social( array( 'og_image_url' => 'https://cdn.example.com/override.jpg' ) );

		$this->assertStringContainsString( 'https://filtered.example.com/og.jpg', $html );
	}

	public function test_an_empty_filter_return_suppresses_the_og_tag(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->andReturn( '' );

		$html = $this->render_social( array( 'og_image_url' => 'https://cdn.example.com/override.jpg' ) );

		$this->assertStringNotContainsString( 'og:image', $html );
	}

	/**
	 * Twitter falls back to the OG image, and the value it inherits has
	 * already been through taseo_og_image_url. So a plugin that rewrites the
	 * OG image moves the Twitter image with it unless Twitter is set
	 * separately — an ordering rule, pinned here rather than left to
	 * inference.
	 */
	public function test_twitter_inherits_the_filtered_og_url(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->andReturn( 'https://filtered.example.com/og.jpg' );

		$html = $this->render_social( array() );

		$this->assertStringContainsString(
			'<meta name="twitter:image" content="https://filtered.example.com/og.jpg" />',
			$html
		);
	}

	/**
	 * A filter returning null must not emit content="" or fatal; the (string)
	 * cast turns it into '', which suppresses the tag like any other empty
	 * result.
	 */
	public function test_a_filter_returning_null_suppresses_the_tag(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->andReturn( null );

		$html = $this->render_social( array( 'og_image_url' => 'https://cdn.example.com/override.jpg' ) );

		$this->assertStringNotContainsString( 'og:image', $html );
	}

	public function test_a_row_twitter_url_beats_the_og_fallback(): void {
		$html = $this->render_social(
			array(
				'og_image_url'      => 'https://cdn.example.com/og.jpg',
				'twitter_image_url' => 'https://cdn.example.com/tw.jpg',
			)
		);

		$this->assertStringContainsString(
			'<meta name="twitter:image" content="https://cdn.example.com/tw.jpg" />',
			$html
		);
	}
```

`SocialOutputTest.php` already has `page_context( ?array $row = null ): array` and renders with `ob_start()` / `$this->social->print_tags()`. Add the helper these tests use on top of it:

```php
	private function render_social( array $row ): string {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context( $row ) );

		ob_start();
		$this->social->print_tags();

		return (string) ob_get_clean();
	}
```

Import `Brain\Monkey\Filters` at the top of the file if it is not imported already.

**`setUp()` needs a default for the new getter.** That file mocks `Settings` with Mockery, so the first call to an un-stubbed `get_default_social_image_url()` fails the test with a Mockery error rather than a useful assertion. Add to `setUp()`, beside the existing `Settings` expectations:

```php
		$this->settings->shouldReceive( 'get_default_social_image_url' )->andReturn( '' )->byDefault();
```

Individual tests then override it with their own `shouldReceive`.

Add to `tests/Unit/Schema/SchemaGraphTest.php`:

```php
	public function test_the_logo_url_beats_the_logo_attachment(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/attachment.png' );
		$this->settings->shouldReceive( 'get_site_logo_url' )->andReturn( 'https://cdn.example.com/logo.png' );
		$this->settings->shouldReceive( 'get_site_logo_id' )->andReturn( 42 );

		$graph = $this->build_graph();

		$this->assertSame( 'https://cdn.example.com/logo.png', $this->find_identity_node( $graph )['logo'] );
	}

	public function test_the_logo_filter_is_applied_last(): void {
		Filters\expectApplied( 'taseo_logo_url' )->andReturn( 'https://filtered.example.com/logo.png' );
		$this->settings->shouldReceive( 'get_site_logo_url' )->andReturn( 'https://cdn.example.com/logo.png' );
		$this->settings->shouldReceive( 'get_site_logo_id' )->andReturn( 0 );

		$graph = $this->build_graph();

		$this->assertSame( 'https://filtered.example.com/logo.png', $this->find_identity_node( $graph )['logo'] );
	}

	/**
	 * SchemaGraph omits the key entirely rather than emitting "logo": "".
	 */
	public function test_an_empty_filter_return_omits_the_logo_key(): void {
		Filters\expectApplied( 'taseo_logo_url' )->andReturn( '' );
		$this->settings->shouldReceive( 'get_site_logo_url' )->andReturn( 'https://cdn.example.com/logo.png' );
		$this->settings->shouldReceive( 'get_site_logo_id' )->andReturn( 0 );

		$graph = $this->build_graph();

		$this->assertArrayNotHasKey( 'logo', $this->find_identity_node( $graph ) );
	}
```

`SchemaGraphTest.php` builds with `$this->graph->build()` and locates a node by looping over the returned array. Add these two helpers:

```php
	private function build_graph(): array {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		return $this->graph->build();
	}

	/**
	 * @param array<int, array<string, mixed>> $graph Built graph.
	 * @return array<string, mixed> The Organization or Person node.
	 */
	private function find_identity_node( array $graph ): array {
		foreach ( $graph as $node ) {
			if ( 'Organization' === $node['@type'] || 'Person' === $node['@type'] ) {
				return $node;
			}
		}

		$this->fail( 'No identity node in the graph.' );
	}
```

**`setUp()` needs a default for the new getter**, for the same Mockery reason as `SocialOutputTest`. That file already has `$this->settings->shouldReceive( 'get_site_logo_id' )->andReturn( 0 )->byDefault();` — add its sibling right beside it:

```php
		$this->settings->shouldReceive( 'get_site_logo_url' )->andReturn( '' )->byDefault();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — the row URL is ignored, and no filter is applied.

- [ ] **Step 3: Rewrite the OG chain**

In `includes/Social/SocialOutput.php`, add the import:

```php
use TheAnother\Plugin\SEO\Meta\ImageResolver;
```

and replace `resolve_image_url()`:

```php
	/**
	 * Image: row URL → row attachment → site URL → site attachment → ''.
	 *
	 * Within a level the explicit URL wins; a more specific level beats a
	 * less specific one. The filter runs last, so add_filter is the final
	 * word and can point this anywhere — returning '' suppresses the tag.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string URL or ''.
	 */
	private function resolve_image_url( array $ctx ): string {
		$url = ImageResolver::first(
			array(
				(string) ( $ctx['row']['og_image_url'] ?? '' ),
				ImageResolver::attachment_url( (int) ( $ctx['row']['og_image_id'] ?? 0 ) ),
				$this->settings->get_default_social_image_url(),
				ImageResolver::attachment_url( $this->settings->get_default_social_image_id() ),
			)
		);

		return (string) apply_filters( 'taseo_og_image_url', $url, $ctx );
	}
```

- [ ] **Step 4: Rewrite the Twitter chain**

In the same file, replace the `$tw_image` assignment inside `print_twitter()`:

```php
		$tw_image = ImageResolver::first(
			array(
				(string) ( $ctx['row']['twitter_image_url'] ?? '' ),
				ImageResolver::attachment_url( (int) ( $ctx['row']['twitter_image_id'] ?? 0 ) ),
				$image_url,
			)
		);

		$tw_image = (string) apply_filters( 'taseo_twitter_image_url', $tw_image, $ctx );

		if ( '' !== $tw_image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $tw_image ) . '" />' . "\n";
		}
```

The old `false !== $tw_image` half of the guard goes: `ImageResolver` and the `(string)` cast make `false` unreachable here.

- [ ] **Step 5: Rewrite the logo chain**

In `includes/Schema/SchemaGraph.php`, add the import:

```php
use TheAnother\Plugin\SEO\Meta\ImageResolver;
```

and replace the logo block:

```php
		$logo_url = ImageResolver::first(
			array(
				$this->settings->get_site_logo_url(),
				ImageResolver::attachment_url( $this->settings->get_site_logo_id() ),
			)
		);

		$logo_url = (string) apply_filters( 'taseo_logo_url', $logo_url );

		if ( '' !== $logo_url ) {
			$node['logo'] = $logo_url;
		}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `make test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/Social/SocialOutput.php includes/Schema/SchemaGraph.php tests/
git commit -m "feat: resolve images through URL overrides and per-slot filters"
```

---

## Task 6: The `wp.media` picker and its enqueues

**Files:**
- Create: `assets/src/media-picker/index.js`
- Modify: `package.json:9` (`build` script)
- Modify: `includes/Admin/ImageField.php` (add `enqueue()`)
- Modify: `includes/Admin/SettingsPage.php` (`enqueue_assets()`)
- Modify: `includes/Admin/Metabox.php` (new `admin_enqueue_scripts` registration and handler)
- Test: `tests/Unit/Admin/SettingsPageTest.php`, `tests/Unit/Admin/MetaboxTest.php`

**Interfaces:**
- Consumes: `ImageField::render()` and the `[data-taseo-image-field]` markup from Task 4.
- Produces: `ImageField::enqueue(): void` — the single enqueue used by both callers — and script handle `taseo-media-picker`, built to `dist/media-picker/index.js`.

- [ ] **Step 1: Write the picker script**

Create `assets/src/media-picker/index.js`:

```js
/**
 * Binds core's media modal to every image field on the page.
 *
 * The hidden input is the source of truth: the modal writes an attachment ID
 * into it and the form submits exactly what it always submitted. With this
 * script absent the input still holds and submits its stored value, so the
 * field degrades to a plain (if unhelpful) control rather than losing data.
 */

const FIELD = '[data-taseo-image-field]';

/**
 * Open the media modal for one field.
 *
 * @param {Element} field The field wrapper.
 */
function openPicker( field ) {
	const input = field.querySelector( '[data-taseo-image-id]' );

	if ( ! input || ! window.wp || ! window.wp.media ) {
		return;
	}

	const frame = window.wp.media( {
		title: field.dataset.taseoImageTitle || '',
		button: { text: field.dataset.taseoImageButton || '' },
		library: { type: 'image' },
		multiple: false,
	} );

	frame.on( 'select', () => {
		const attachment = frame.state().get( 'selection' ).first().toJSON();

		input.value = attachment.id;
		setPreview( field, previewUrl( attachment ) );
	} );

	frame.open();
}

/**
 * Smallest usable preview URL for an attachment.
 *
 * @param {Object} attachment The selected attachment.
 * @return {string} URL, or '' when the attachment has none.
 */
function previewUrl( attachment ) {
	if ( attachment.sizes && attachment.sizes.thumbnail ) {
		return attachment.sizes.thumbnail.url;
	}

	return attachment.url || '';
}

/**
 * Show, replace, or drop a field's preview image.
 *
 * @param {Element} field The field wrapper.
 * @param {string}  url   Preview URL, or '' to remove it.
 */
function setPreview( field, url ) {
	let img = field.querySelector( '[data-taseo-image-preview]' );

	if ( '' === url ) {
		if ( img ) {
			img.remove();
		}

		return;
	}

	if ( ! img ) {
		img = document.createElement( 'img' );
		img.width = 80;
		img.height = 80;
		img.alt = '';
		img.setAttribute( 'data-taseo-image-preview', '' );
		field.insertBefore( img, field.querySelector( '[data-taseo-image-select]' ) );
	}

	img.src = url;
}

// One delegated listener rather than one per field: the term edit screen adds
// rows without reloading, and metaboxes can be reordered after load.
document.addEventListener( 'click', ( event ) => {
	const select = event.target.closest( `${ FIELD } [data-taseo-image-select]` );

	if ( select ) {
		event.preventDefault();
		openPicker( select.closest( FIELD ) );

		return;
	}

	const remove = event.target.closest( `${ FIELD } [data-taseo-image-remove]` );

	if ( remove ) {
		event.preventDefault();

		const field = remove.closest( FIELD );
		const input = field.querySelector( '[data-taseo-image-id]' );

		if ( input ) {
			input.value = '0';
		}

		setPreview( field, '' );
	}
} );
```

- [ ] **Step 2: Add the build entry**

In `package.json`, extend the `build` script with a third entry:

```json
		"build": "wp-scripts build blocks/breadcrumbs/index.js --output-path=dist/breadcrumbs && wp-scripts build assets/src/settings/index.js --output-path=dist/settings && wp-scripts build assets/src/media-picker/index.js --output-path=dist/media-picker",
```

- [ ] **Step 3: Build and verify the bundle exists**

Run: `npm run build`
Expected: `dist/media-picker/index.js` and `dist/media-picker/index.asset.php` both exist. Confirm with `ls dist/media-picker/`.

**`dist/` is gitignored** (`.gitignore` line 3) and `.distignore` deliberately declines to exclude it, so it is rebuilt into the release zip at package time. Commit nothing under `dist/`.

- [ ] **Step 4: Write the failing enqueue tests**

Add to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	/**
	 * wp.media is undefined without wp_enqueue_media(), so the picker would
	 * silently do nothing.
	 */
	public function test_the_settings_page_enqueues_the_media_library(): void {
		$called  = false;
		$restore = $this->with_built_asset_file();

		Functions\when( 'wp_enqueue_media' )->alias(
			static function () use ( &$called ): void {
				$called = true;
			}
		);

		$this->enqueue_on_our_page();

		$restore();

		$this->assertTrue( $called );
	}
```

Add to `tests/Unit/Admin/MetaboxTest.php`:

```php
	public function test_the_picker_enqueues_on_post_and_term_screens(): void {
		foreach ( array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' ) as $hook ) {
			$this->assertContains( $hook, Metabox::PICKER_SCREENS, "missing {$hook}" );
		}
	}
```

Reuse `with_built_asset_file()` and the existing enqueue helper in `SettingsPageTest.php`; if the helper only writes `dist/settings/index.asset.php`, extend it to write `dist/media-picker/index.asset.php` too.

- [ ] **Step 5: Give `ImageField` the enqueue, and call it from the settings page**

`SettingsPage` and `Metabox` both need this script and share no base class. Rather than each carrying its own copy of the enqueue, `ImageField` owns it: the class already renders the markup, so owning the script that drives that markup keeps both in one place and means a change to the handle or path cannot land in one caller and not the other.

Add to `includes/Admin/ImageField.php`:

```php
	/**
	 * Enqueue the picker bundle and core's media library.
	 *
	 * wp_enqueue_media() is what defines wp.media; without it the picker
	 * loads and silently does nothing. The file_exists() guard matches the
	 * settings bundle's: a source checkout that has not been built must not
	 * fatal inside wp-admin.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$asset_file = THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/media-picker/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_media();
		wp_enqueue_script(
			'taseo-media-picker',
			THE_ANOTHER_SEO_PLUGIN_URL . 'dist/media-picker/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}
```

In `includes/Admin/SettingsPage.php`, at the end of `enqueue_assets()` (inside the existing `$hook_suffix` guard, after `wp_enqueue_style( 'wp-components' );`):

```php
		ImageField::enqueue();
```

- [ ] **Step 6: Enqueue on post and term screens**

In `includes/Admin/Metabox.php`, add the screen list as a class constant beside `FIELDS`:

```php
	/**
	 * Admin screens that render image fields.
	 *
	 * render_term_fields() reuses render_fields(), so term screens carry the
	 * same picker as the post metabox.
	 *
	 * @var array<int, string>
	 */
	public const PICKER_SCREENS = array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' );
```

register the hook alongside the existing ones in the constructor:

```php
		$hook_manager->register_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_picker' ) );
```

and add the handler, which screens the hook and then delegates to the same `ImageField::enqueue()` the settings page uses:

```php
	/**
	 * Enqueue the image picker on screens that render image fields.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_media_picker( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::PICKER_SCREENS, true ) ) {
			return;
		}

		ImageField::enqueue();
	}
```

- [ ] **Step 7: Run the tests**

Run: `make test && make lint && npm run lint:js`
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add assets/src/media-picker/index.js package.json includes/Admin/SettingsPage.php includes/Admin/Metabox.php tests/
git commit -m "feat: wire core's media modal to every image field"
```

---

## Task 7: E2E coverage

**Files:**
- Modify: `tests/e2e/functional/specs/webmaster-admin.spec.ts`

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Write the e2e tests**

Add to `tests/e2e/functional/specs/webmaster-admin.spec.ts`:

```ts
const SOCIAL_TAB_URL =
	'/wp-admin/options-general.php?page=taseo&tab=social';

test( 'the default social image is a picker, not a number box', async ( {
	page,
} ) => {
	await page.goto( SOCIAL_TAB_URL );

	const field = page.locator(
		'[data-taseo-image-field]:has(input[name="taseo_settings[default_social_image_id]"])'
	);

	await expect( field ).toBeVisible();
	await expect(
		field.locator( '[data-taseo-image-select]' )
	).toBeVisible();
	await expect(
		page.locator( 'input[type="number"][name="taseo_settings[default_social_image_id]"]' )
	).toHaveCount( 0 );
} );

test( 'a URL override saves and survives a reload', async ( { page } ) => {
	await page.goto( SOCIAL_TAB_URL );

	await page
		.locator( 'input[name="taseo_settings[default_social_image_url]"]' )
		.fill( 'https://cdn.example.com/social.jpg' );
	await page.locator( '#submit' ).click();

	await page.goto( SOCIAL_TAB_URL );
	await expect(
		page.locator( 'input[name="taseo_settings[default_social_image_url]"]' )
	).toHaveValue( 'https://cdn.example.com/social.jpg' );
} );

/**
 * The degradation assertion. With the picker blocked the hidden input must
 * still submit whatever was stored — losing it would silently clear an
 * administrator's image on an unrelated save.
 */
test( 'with the picker script blocked the stored image id still saves', async ( {
	page,
} ) => {
	await page.route( '**/dist/media-picker/**', ( route ) => route.abort() );
	await page.goto( SOCIAL_TAB_URL );

	const idInput = page.locator(
		'input[name="taseo_settings[default_social_image_id]"]'
	);
	const before = await idInput.inputValue();

	await page
		.locator( 'input[name="taseo_settings[facebook_app_id]"]' )
		.fill( '1234567890' );
	await page.locator( '#submit' ).click();

	await page.goto( SOCIAL_TAB_URL );
	await expect( idInput ).toHaveValue( before );
} );
```

If the Social tab's submit button is not `#submit`, read the tab's markup and use the real selector rather than guessing.

- [ ] **Step 2: Run the e2e suite**

Run: `make test-e2e`
Expected: all specs pass, with three more than before.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/
git commit -m "test: cover the image picker, URL override, and its no-JS path"
```

---

## Task 8: Full gate, changelog, and push

- [ ] **Step 1: Add the changelog entries**

In `CHANGELOG.md`, under `## [Unreleased]` → `### Added`:

```markdown
- Image fields (default social image, Organization logo, and the per-post/term OG and Twitter images) are now chosen through WordPress's own media library — with its Upload tab — instead of requiring a hand-typed attachment ID, and each one gains an optional image URL that overrides the chosen attachment so an off-site or CDN image needs no developer.
- Filters for programmatic image overrides: `taseo_og_image_url`, `taseo_twitter_image_url`, and `taseo_logo_url`, each applied after the stored values resolve and each able to suppress its image entirely by returning an empty string.
```

- [ ] **Step 2: Run every gate**

```bash
make lint
make test
make test-js
make test-e2e
make check-plugin
```

Report the actual observed numbers. If any gate fails or a count drops, stop and report — do not adjust a test to make it pass.

- [ ] **Step 3: Commit and push**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for the image picker and override filters"
git push
gh pr checks 2 --watch --interval 30
```

Expected: all five CI jobs pass.

---

## Notes for the implementer

**The URL override must never clear the attachment ID.** They are siblings: clearing the URL restores the attachment. Any code path that writes one while blanking the other is wrong.

**`OVERRIDE_COLUMNS` is an allowlist and fails silently.** A column missing from it is dropped on write with no error, so Task 1's allowlist test is not ceremony.

**Do not widen `maybe_flag_upgrade_backfill()`'s version bound.** It flags a full-catalog resync; these columns need no backfill.

**Adding a `Settings` getter breaks Mockery-based tests that do not stub it.** `SocialOutputTest`, `SchemaGraphTest`, and any other file mocking `Settings` will fail with a Mockery "received unexpected call" error, not a useful assertion, the first time production code calls `get_default_social_image_url()` or `get_site_logo_url()`. Tasks 3 and 5 add `->byDefault()` stubs where they are needed; if a *different* test file starts failing that way after Task 5, add the same `byDefault` stub to its `setUp()` rather than changing production code.

**Two `wp.media` details worth knowing before Step 1 of Task 6:** `wp.media` is undefined without `wp_enqueue_media()`, and `frame.state().get( 'selection' ).first().toJSON()` is how the selected attachment comes back — an attachment with no `sizes.thumbnail` (an SVG, say) falls back to `attachment.url`.
