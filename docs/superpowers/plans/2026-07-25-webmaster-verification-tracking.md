# Webmaster Verification & Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add site-verification output (meta tags for five services, virtually-served verification files for three) and tracking-snippet output (GA4, Google Tag Manager, Meta Pixel) to The Another SEO, configurable from a new settings tab and extensible through filters.

**Architecture:** Four new hook-bearing service classes registered in the existing `Container` via `Plugin::register_services()`, following the established `SocialOutput`/`SchemaOutput` pattern — constructor injection, an `init( HookManager $hook_manager )` method, no static state. All configuration lives in eleven new keys inside the existing `taseo_settings` option array; there is no new option, no new table, and no migration. Per-page secondary tracking is delivered entirely through filters rather than UI.

**Tech Stack:** PHP 8.3+, WordPress 6.9+, PHPUnit 11 + Brain Monkey + Mockery for unit tests, Playwright + `@wordpress/e2e-test-utils-playwright` for e2e, PHPCS (WordPress + VIP-Go rulesets), all run inside Docker via `make`.

**Spec:** `docs/superpowers/specs/2026-07-25-webmaster-verification-analytics-design.md`

## Global Constraints

- **Namespace root:** `TheAnother\Plugin\SEO\` → `includes/` (PSR-4). Tests: `TheAnother\Plugin\SEO\Tests\` → `tests/Unit/`.
- **PHP 8.3 minimum.** Constructor property promotion with `private readonly` is the established style.
- **Every source file** starts with a docblock carrying `@package TheAnotherSEO` and `@since 1.0.0`, uses tabs for indentation, `array()` long syntax (WPCS), Yoda conditions, and has **no closing `?>`**.
- **All settings keys are prefixed** inside the single `taseo_settings` option. Option name constant: `Settings::OPTION_NAME`.
- **All filters and actions are prefixed `taseo_`.**
- **Text domain:** `the-another-seo` for every user-facing string.
- **Escape on output**, always: `esc_attr()`, `esc_url()`, `esc_html()`. The one deliberate exception is the verification-file body (Task 4), which must be byte-exact and carries an explicit `phpcs:ignore` with its reason.
- **Run tests with:** `make test` (whole suite in Docker). Single file: `make test` then read output — the suite is fast (<10s); there is no per-file make target. To run one file directly inside the container: `docker run --rm -v "$PWD":/app -w /app the-another-seo-tests ./vendor/bin/phpunit tests/Unit/Path/ToTest.php`.
- **Run lint with:** `make lint`. It must pass clean before every commit.
- **Commit style:** Conventional Commits (`feat:`, `test:`, `fix:`, `docs:`), imperative mood.
- **Branch:** `feature/webmaster-verification` (already created; the spec is committed there).

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `includes/Verification/VerificationOutput.php` | Prints verification `<meta>` tags on the front page. Owns the engine→meta-name map and the shared code sanitizer. |
| `includes/Verification/VerificationFileServer.php` | Answers requests for verification files with byte-exact bodies, then exits. |
| `includes/Analytics/AnalyticsOutput.php` | GA4 (`gtag.js`) and Google Tag Manager output. |
| `includes/Analytics/MetaPixelOutput.php` | Meta Pixel base code and `<noscript>` fallback. |
| `tests/Unit/Verification/VerificationOutputTest.php` | |
| `tests/Unit/Verification/VerificationFileServerTest.php` | |
| `tests/Unit/Analytics/AnalyticsOutputTest.php` | |
| `tests/Unit/Analytics/MetaPixelOutputTest.php` | |
| `tests/e2e/functional/specs/webmaster.spec.ts` | End-to-end assertions against real rendered output. |

**Modified:**

| Path | Change |
|---|---|
| `includes/Settings/Settings.php` | Five new getters + two key-map constants. |
| `includes/Admin/SettingsPage.php` | New `webmaster` tab, its renderer, three sanitizer loops, tab-preserving redirect. |
| `includes/Plugin.php` | Register and init the four new services. |
| `tests/Unit/Settings/SettingsTest.php` | Getter coverage. |
| `tests/Unit/Admin/SettingsPageTest.php` | Sanitizer + tab + redirect coverage. |
| `readme.txt` | External services disclosure (required by Plugin Check, which gates CI). |
| `CHANGELOG.md` | `[Unreleased] → Added` entries. |
| `tests/e2e/functional/environment/serve-wp.sh` | Seed verification/tracking options via WP-CLI so the e2e spec has something to assert. |

**Task order rationale:** Settings getters first (everything depends on their shape), then the four output classes (the core deliverable, each independently testable), then the admin UI that writes the values, then docs, then e2e. The feature is not user-configurable until Task 6 — that is intentional, since the output contracts are what the UI must serve, not the other way round.

---

### Task 1: Settings getters

**Files:**
- Modify: `includes/Settings/Settings.php`
- Test: `tests/Unit/Settings/SettingsTest.php`

**Interfaces:**
- Consumes: existing `Settings::get( string $key, mixed $fallback )`.
- Produces:
  - `Settings::get_verification_code( string $engine ): string` — `$engine` ∈ `google|bing|yandex|yahoo|facebook`, `''` for unknown or unset.
  - `Settings::get_verification_file( string $engine ): string` — `$engine` ∈ `google|bing|yandex`, `''` for unknown or unset.
  - `Settings::get_ga4_id(): string`, `Settings::get_gtm_id(): string`, `Settings::get_meta_pixel_id(): string` — all `''` by default.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Settings/SettingsTest.php`, before the closing brace:

```php
	public function test_verification_code_returns_stored_value_per_engine(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google'   => 'googletoken',
				'verify_bing'     => 'bingtoken',
				'verify_yandex'   => 'yandextoken',
				'verify_yahoo'    => 'yahootoken',
				'verify_facebook' => 'metatoken',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'googletoken', $settings->get_verification_code( 'google' ) );
		$this->assertSame( 'bingtoken', $settings->get_verification_code( 'bing' ) );
		$this->assertSame( 'yandextoken', $settings->get_verification_code( 'yandex' ) );
		$this->assertSame( 'yahootoken', $settings->get_verification_code( 'yahoo' ) );
		$this->assertSame( 'metatoken', $settings->get_verification_code( 'facebook' ) );
	}

	public function test_verification_code_defaults_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', ( new Settings() )->get_verification_code( 'google' ) );
	}

	public function test_verification_code_returns_empty_string_for_unknown_engine(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verify_google' => 'googletoken' ) );

		$this->assertSame( '', ( new Settings() )->get_verification_code( 'duckduckgo' ) );
	}

	public function test_verification_file_returns_stored_value_per_engine(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google_file' => 'google1a2b3c.html',
				'verify_bing_file'   => 'BINGTOKEN123',
				'verify_yandex_file' => 'yandex_9f8e7d.html',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'google1a2b3c.html', $settings->get_verification_file( 'google' ) );
		$this->assertSame( 'BINGTOKEN123', $settings->get_verification_file( 'bing' ) );
		$this->assertSame( 'yandex_9f8e7d.html', $settings->get_verification_file( 'yandex' ) );
	}

	public function test_verification_file_returns_empty_string_for_engine_without_file_method(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verify_facebook' => 'metatoken' ) );

		$this->assertSame( '', ( new Settings() )->get_verification_file( 'facebook' ) );
	}

	public function test_tracking_ids_return_stored_values(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'analytics_ga4_id' => 'G-ABCD1234',
				'analytics_gtm_id' => 'GTM-XYZ789',
				'meta_pixel_id'    => '0123456789012345',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'G-ABCD1234', $settings->get_ga4_id() );
		$this->assertSame( 'GTM-XYZ789', $settings->get_gtm_id() );
		$this->assertSame( '0123456789012345', $settings->get_meta_pixel_id() );
	}

	public function test_tracking_ids_default_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$settings = new Settings();

		$this->assertSame( '', $settings->get_ga4_id() );
		$this->assertSame( '', $settings->get_gtm_id() );
		$this->assertSame( '', $settings->get_meta_pixel_id() );
	}

	public function test_meta_pixel_id_preserves_leading_zero(): void {
		Functions\when( 'get_option' )->justReturn( array( 'meta_pixel_id' => '0987654321098' ) );

		$this->assertSame( '0987654321098', ( new Settings() )->get_meta_pixel_id() );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — `Error: Call to undefined method TheAnother\Plugin\SEO\Settings\Settings::get_verification_code()`

- [ ] **Step 3: Implement the getters**

In `includes/Settings/Settings.php`, add these two constants immediately after the existing `SCHEMA_TYPE_DEFAULTS` constant:

```php
	/**
	 * Engine slug => settings key for verification meta-tag codes.
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_KEYS = array(
		'google'   => 'verify_google',
		'bing'     => 'verify_bing',
		'yandex'   => 'verify_yandex',
		'yahoo'    => 'verify_yahoo',
		'facebook' => 'verify_facebook',
	);

	/**
	 * Engine slug => settings key for verification files. Yahoo retired its
	 * own webmaster tools; Meta does not publish its file body format.
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_FILE_KEYS = array(
		'google' => 'verify_google_file',
		'bing'   => 'verify_bing_file',
		'yandex' => 'verify_yandex_file',
	);
```

Then append these methods before the closing brace of the class:

```php
	/**
	 * Verification meta-tag code for one service.
	 *
	 * @param string $engine Engine slug.
	 * @return string Code, '' when unset or unknown.
	 */
	public function get_verification_code( string $engine ): string {
		$key = self::VERIFICATION_KEYS[ $engine ] ?? '';

		return '' === $key ? '' : (string) $this->get( $key, '' );
	}

	/**
	 * Verification file value for one service. Google and Yandex store the
	 * full filename; Bing stores only the token (its filename is fixed).
	 *
	 * @param string $engine Engine slug.
	 * @return string Value, '' when unset or unknown.
	 */
	public function get_verification_file( string $engine ): string {
		$key = self::VERIFICATION_FILE_KEYS[ $engine ] ?? '';

		return '' === $key ? '' : (string) $this->get( $key, '' );
	}

	/**
	 * GA4 measurement ID.
	 *
	 * @return string ID or ''.
	 */
	public function get_ga4_id(): string {
		return (string) $this->get( 'analytics_ga4_id', '' );
	}

	/**
	 * Google Tag Manager container ID.
	 *
	 * @return string ID or ''.
	 */
	public function get_gtm_id(): string {
		return (string) $this->get( 'analytics_gtm_id', '' );
	}

	/**
	 * Meta Pixel ID. Returned as a string, never cast to int: a leading
	 * zero is significant and casting would silently change the pixel.
	 *
	 * @return string ID or ''.
	 */
	public function get_meta_pixel_id(): string {
		return (string) $this->get( 'meta_pixel_id', '' );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test`
Expected: PASS — all Settings tests green.

- [ ] **Step 5: Lint**

Run: `make lint`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add includes/Settings/Settings.php tests/Unit/Settings/SettingsTest.php
git commit -m "feat: add verification and tracking settings getters"
```

---

### Task 2: VerificationOutput — meta tags

**Files:**
- Create: `includes/Verification/VerificationOutput.php`
- Modify: `includes/Plugin.php`
- Test: `tests/Unit/Verification/VerificationOutputTest.php`

**Interfaces:**
- Consumes: `Settings::get_verification_code( string $engine ): string` (Task 1).
- Produces:
  - `VerificationOutput::__construct( Settings $settings )`
  - `VerificationOutput::init( HookManager $hook_manager ): void`
  - `VerificationOutput::print_tags(): void`
  - `VerificationOutput::sanitize_code( string $raw ): string` — **static**, reused by `SettingsPage` in Task 6.
  - Filters: `taseo_verification_should_print` (bool), `taseo_verification_tags` (`array<string,string>`).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Verification/VerificationOutputTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Verification\VerificationOutput;

#[CoversClass( VerificationOutput::class )]
class VerificationOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private VerificationOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->output   = new VerificationOutput( $this->settings );

		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_paged' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function codes( array $codes = array() ): void {
		$defaults = array(
			'google'   => '',
			'bing'     => '',
			'yandex'   => '',
			'yahoo'    => '',
			'facebook' => '',
		);

		foreach ( array_merge( $defaults, $codes ) as $engine => $code ) {
			$this->settings->shouldReceive( 'get_verification_code' )
				->with( $engine )
				->andReturn( $code );
		}
	}

	private function render(): string {
		ob_start();
		$this->output->print_tags();

		return (string) ob_get_clean();
	}

	public function test_prints_all_five_verification_tags_on_the_front_page(): void {
		$this->codes(
			array(
				'google'   => 'googletoken',
				'bing'     => 'BINGTOKEN',
				'yandex'   => 'yandextoken',
				'yahoo'    => 'yahootoken',
				'facebook' => 'metatoken',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '<meta name="google-site-verification" content="googletoken" />', $html );
		$this->assertStringContainsString( '<meta name="msvalidate.01" content="BINGTOKEN" />', $html );
		$this->assertStringContainsString( '<meta name="yandex-verification" content="yandextoken" />', $html );
		$this->assertStringContainsString( '<meta name="y_key" content="yahootoken" />', $html );
		$this->assertStringContainsString( '<meta name="facebook-domain-verification" content="metatoken" />', $html );
	}

	public function test_bing_meta_name_keeps_its_dot(): void {
		$this->codes( array( 'bing' => 'BINGTOKEN' ) );

		// Regression guard: sanitize_key() would rewrite this to msvalidate01.
		$this->assertStringContainsString( 'name="msvalidate.01"', $this->render() );
	}

	public function test_prints_nothing_when_not_the_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		$this->codes( array( 'google' => 'googletoken' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_on_a_paged_front_page(): void {
		Functions\when( 'is_paged' )->justReturn( true );
		$this->codes( array( 'google' => 'googletoken' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_when_all_codes_are_empty(): void {
		$this->codes();

		$this->assertSame( '', $this->render() );
	}

	public function test_omits_engines_with_empty_codes(): void {
		$this->codes( array( 'google' => 'googletoken' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'google-site-verification', $html );
		$this->assertStringNotContainsString( 'msvalidate.01', $html );
		$this->assertStringNotContainsString( 'y_key', $html );
	}

	public function test_should_print_filter_can_suppress_output(): void {
		$this->codes( array( 'google' => 'googletoken' ) );
		Filters\expectApplied( 'taseo_verification_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->render() );
	}

	public function test_tags_filter_can_add_a_service(): void {
		$this->codes( array( 'google' => 'googletoken' ) );
		Filters\expectApplied( 'taseo_verification_tags' )->once()->andReturnUsing(
			static function ( array $tags ): array {
				$tags['baidu-site-verification'] = 'baidutoken';

				return $tags;
			}
		);

		$this->assertStringContainsString(
			'<meta name="baidu-site-verification" content="baidutoken" />',
			$this->render()
		);
	}

	public function test_drops_filter_injected_values_containing_markup(): void {
		$this->codes();
		Filters\expectApplied( 'taseo_verification_tags' )->once()->andReturn(
			array( 'evil-verification' => '"><script>alert(1)</script>' )
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '"><', $html );
	}

	public function test_sanitize_code_extracts_content_from_a_pasted_meta_tag(): void {
		$this->assertSame(
			'AbC123_-xyz',
			VerificationOutput::sanitize_code( '<meta name="google-site-verification" content="AbC123_-xyz" />' )
		);
	}

	public function test_sanitize_code_strips_disallowed_characters(): void {
		$this->assertSame( 'abc123', VerificationOutput::sanitize_code( ' ab"c<1>2 3 ' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `Error: Class "TheAnother\Plugin\SEO\Verification\VerificationOutput" not found`

- [ ] **Step 3: Implement the class**

Create `includes/Verification/VerificationOutput.php`:

```php
<?php
/**
 * Site Verification Meta Tags
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Verification;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class VerificationOutput
 *
 * Prints webmaster-tools verification meta tags. Front page only: search
 * engines read the tag at the property root, so emitting it on every URL of
 * a catalog-scale site is payload with no benefit.
 */
class VerificationOutput {

	/**
	 * Engine slug => meta name.
	 *
	 * @var array<string, string>
	 */
	private const META_NAMES = array(
		'google'   => 'google-site-verification',
		'bing'     => 'msvalidate.01',
		'yandex'   => 'yandex-verification',
		'yahoo'    => 'y_key',
		'facebook' => 'facebook-domain-verification',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_tags' ), 1 );
	}

	/**
	 * Print the verification tags.
	 *
	 * @return void
	 */
	public function print_tags(): void {
		$should_print = is_front_page() && ! is_paged();

		/**
		 * Filters whether verification tags print on this request.
		 *
		 * Widen this for a Search Console URL-prefix property registered
		 * below the site root.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $should_print Whether to print.
		 */
		if ( ! (bool) apply_filters( 'taseo_verification_should_print', $should_print ) ) {
			return;
		}

		$tags = array();

		foreach ( self::META_NAMES as $engine => $meta_name ) {
			$code = $this->settings->get_verification_code( $engine );

			if ( '' !== $code ) {
				$tags[ $meta_name ] = $code;
			}
		}

		/**
		 * Filters the verification tags, keyed by meta name.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $tags Meta name => verification code.
		 */
		$tags = apply_filters( 'taseo_verification_tags', $tags );

		if ( ! is_array( $tags ) ) {
			return;
		}

		foreach ( $tags as $meta_name => $code ) {
			// NOT sanitize_key(): it strips dots and colons, which would
			// rewrite Bing's own msvalidate.01 and Pinterest's
			// p:domain_verify into names no service recognises.
			$meta_name = (string) preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $meta_name );
			$code      = self::sanitize_code( (string) $code );

			if ( '' === $meta_name || '' === $code ) {
				continue;
			}

			echo '<meta name="' . esc_attr( $meta_name ) . '" content="' . esc_attr( $code ) . '" />' . "\n";
		}
	}

	/**
	 * Normalize a verification code.
	 *
	 * Accepts a bare token or a whole pasted <meta> tag, and strips
	 * everything outside the token character class. That strip is the
	 * security guarantee: a stored code cannot contain a quote or angle
	 * bracket, so it cannot break out of the content="" attribute.
	 *
	 * @param string $raw Raw submitted or filtered value.
	 * @return string Clean code, '' when nothing survives.
	 */
	public static function sanitize_code( string $raw ): string {
		$value = trim( $raw );

		if ( false !== stripos( $value, '<meta' ) && 1 === preg_match( '/content=["\']([^"\']*)["\']/i', $value, $matches ) ) {
			$value = $matches[1];
		}

		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Wire it into the plugin**

In `includes/Plugin.php`, add the import alongside the existing `use` statements (alphabetical order — after `use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;` and `use TheAnother\Plugin\SEO\Social\SocialOutput;`):

```php
use TheAnother\Plugin\SEO\Verification\VerificationOutput;
```

In `register_services()`, after the `blocks` registration:

```php
		$c->register(
			'verification_output',
			fn( Container $c ) => new VerificationOutput( $c->get( 'settings' ) )
		);
```

In `init_services()`, after the `sitemap_server` line:

```php
		$this->container->get( 'verification_output' )->init( $hook_manager );
```

- [ ] **Step 6: Run the full suite and lint**

Run: `make test && make lint`
Expected: PASS, no lint errors. `PluginTest` should still pass — if it asserts a service count, update that assertion to include the new service.

- [ ] **Step 7: Commit**

```bash
git add includes/Verification/VerificationOutput.php includes/Plugin.php tests/Unit/Verification/VerificationOutputTest.php
git commit -m "feat: print webmaster verification meta tags on the front page"
```

---

### Task 3: VerificationFileServer — virtual verification files

**Files:**
- Create: `includes/Verification/VerificationFileServer.php`
- Modify: `includes/Plugin.php`
- Test: `tests/Unit/Verification/VerificationFileServerTest.php`

**Interfaces:**
- Consumes: `Settings::get_verification_file( string $engine ): string` (Task 1).
- Produces:
  - `VerificationFileServer::__construct( Settings $settings )`
  - `VerificationFileServer::init( HookManager $hook_manager ): void`
  - `VerificationFileServer::maybe_serve( bool $do_exit = true ): void`
  - `VerificationFileServer::BING_FILENAME` = `'BingSiteAuth.xml'`
  - Filter: `taseo_verification_files` (`array<string, array{content_type: string, body: string}>` keyed by filename).

**Why `$do_exit`:** the method terminates the request. The existing `SitemapServer::maybe_serve()` and the `admin_post` handlers use exactly this parameter so tests can drive them; it is registered with **0 accepted args** so WordPress's legacy empty-string argument cannot falsify the default.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Verification/VerificationFileServerTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;

#[CoversClass( VerificationFileServer::class )]
class VerificationFileServerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private VerificationFileServer $server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->server   = new VerificationFileServer( $this->settings );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'status_header' )->justReturn( null );

		// header() is a PHP internal function: Brain Monkey cannot redefine
		// it, and under CLI it is a harmless no-op. Content types are
		// therefore asserted in the e2e suite against real HTTP responses,
		// which is where they can actually be observed. This mirrors
		// SitemapServerTest, which asserts status_header and body only.

		$this->files();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function files( array $files = array() ): void {
		$defaults = array(
			'google' => '',
			'bing'   => '',
			'yandex' => '',
		);

		foreach ( array_merge( $defaults, $files ) as $engine => $value ) {
			$this->settings->shouldReceive( 'get_verification_file' )
				->with( $engine )
				->andReturn( $value )
				->byDefault();
		}
	}

	private function serve( string $uri ): string {
		$_SERVER['REQUEST_URI'] = $uri;

		ob_start();
		$this->server->maybe_serve( false );

		return (string) ob_get_clean();
	}

	public function test_serves_the_google_file_with_an_exact_body(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );
		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/google1a2b3c.html' )
		);
	}

	public function test_serves_the_bing_file_with_an_exact_body(): void {
		$this->files( array( 'bing' => 'BINGTOKEN123' ) );

		$body = $this->serve( '/BingSiteAuth.xml' );

		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<users>\n  <user>BINGTOKEN123</user>\n</users>",
			$body
		);
	}

	public function test_serves_the_yandex_file_with_an_exact_body(): void {
		$this->files( array( 'yandex' => 'yandex_9f8e7d.html' ) );

		$body = $this->serve( '/yandex_9f8e7d.html' );

		$this->assertSame(
			"<html>\n<head>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n</head>\n<body>Verification: 9f8e7d</body>\n</html>",
			$body
		);
	}

	public function test_ignores_a_non_matching_path(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/some-post/' ) );
	}

	public function test_ignores_a_wrong_token(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

		$this->assertSame( '', $this->serve( '/google-wrong-token.html' ) );
	}

	public function test_does_nothing_when_no_files_are_configured(): void {
		$this->assertSame( '', $this->serve( '/google1a2b3c.html' ) );
	}

	public function test_matching_is_case_sensitive(): void {
		$this->files( array( 'bing' => 'BINGTOKEN123' ) );

		$this->assertSame( '', $this->serve( '/bingsiteauth.xml' ) );
	}

	public function test_strips_the_home_url_path_prefix_for_subdirectory_installs(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/blog' );
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/blog/google1a2b3c.html' )
		);
	}

	public function test_ignores_a_query_string(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/google1a2b3c.html?utm_source=x' )
		);
	}

	public function test_files_filter_can_add_a_file(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'ahrefs_1234.html' => array(
					'content_type' => 'text/plain',
					'body'         => 'ahrefs-site-verification: 1234',
				),
			)
		);

		$this->assertSame( 'ahrefs-site-verification: 1234', $this->serve( '/ahrefs_1234.html' ) );
	}

	public function test_files_filter_rejects_a_disallowed_content_type(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'evil.html' => array(
					'content_type' => 'application/javascript',
					'body'         => 'alert(1)',
				),
			)
		);

		$this->assertSame( '', $this->serve( '/evil.html' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `Error: Class "TheAnother\Plugin\SEO\Verification\VerificationFileServer" not found`

- [ ] **Step 3: Implement the class**

Create `includes/Verification/VerificationFileServer.php`:

```php
<?php
/**
 * Verification File Server
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Verification;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class VerificationFileServer
 *
 * Answers requests for webmaster-tools verification files without writing
 * anything to disk. No rewrite rules: there are at most three of these, at
 * fixed paths, and registering rules would force a rewrite flush (and an
 * .htaccess rebuild) on every site that updates. The request arrives here
 * anyway — no such file exists, so the webserver hands it to WordPress,
 * which 404s and fires template_redirect.
 *
 * Bodies are byte-exact. Google, Bing, and Yandex all fail verification if
 * the CMS injects extra whitespace, a BOM, or markup, so nothing is printed
 * before or after the payload.
 */
class VerificationFileServer {

	/**
	 * Bing's filename is fixed; only its token is configurable.
	 *
	 * @var string
	 */
	public const BING_FILENAME = 'BingSiteAuth.xml';

	/**
	 * Content types a verification file may be served as.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_CONTENT_TYPES = array( 'text/plain', 'text/html', 'application/xml' );

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 *
	 * Priority 0 puts this ahead of the theme and of MetaOutput, so nothing
	 * has printed when the body is emitted. 0 accepted args for the same
	 * reason documented in SettingsPage::init().
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'template_redirect', array( $this, 'maybe_serve' ), 0, 0 );
	}

	/**
	 * Serve a verification file when the request path matches one.
	 *
	 * @param bool $do_exit Exit after output (false in tests).
	 * @return void
	 */
	public function maybe_serve( bool $do_exit = true ): void {
		$path = $this->request_path();

		if ( '' === $path ) {
			return;
		}

		$files = $this->build_files();

		if ( array() === $files && ! has_filter( 'taseo_verification_files' ) ) {
			return;
		}

		/**
		 * Filters the verification files this site serves, keyed by filename.
		 *
		 * Each value is an array with 'content_type' and 'body'. The body is
		 * emitted verbatim — byte-exactness is the whole point of the method.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{content_type: string, body: string}> $files Files.
		 */
		$files = apply_filters( 'taseo_verification_files', $files );

		if ( ! is_array( $files ) || ! isset( $files[ $path ] ) || ! is_array( $files[ $path ] ) ) {
			return;
		}

		$file = $files[ $path ];

		if ( ! isset( $file['content_type'], $file['body'] ) ) {
			return;
		}

		$content_type = (string) $file['content_type'];

		if ( ! in_array( $content_type, self::ALLOWED_CONTENT_TYPES, true ) ) {
			return;
		}

		status_header( 200 );
		$this->send_headers( $content_type );

		// phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- byte-exact verification payload; any escaping fails verification.
		echo $file['body'];

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Send the response headers. Mirrors SitemapServer::send_xml_headers().
	 *
	 * @param string $content_type Content type without charset.
	 * @return void
	 */
	private function send_headers( string $content_type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * Request path relative to the site root, without query string.
	 *
	 * @return string Path, '' for the site root itself.
	 */
	private function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $uri ) {
			return '';
		}

		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( (string) home_url(), PHP_URL_PATH ), '/' );

		if ( '' === $home ) {
			return $path;
		}

		if ( $path === $home ) {
			return '';
		}

		return str_starts_with( $path, $home . '/' )
			? substr( $path, strlen( $home ) + 1 )
			: $path;
	}

	/**
	 * Build the configured files, keyed by filename.
	 *
	 * @return array<string, array{content_type: string, body: string}> Files.
	 */
	private function build_files(): array {
		$files = array();

		$google = $this->settings->get_verification_file( 'google' );

		if ( '' !== $google ) {
			$files[ $google ] = array(
				'content_type' => 'text/html',
				'body'         => 'google-site-verification: ' . $google,
			);
		}

		$bing = $this->settings->get_verification_file( 'bing' );

		if ( '' !== $bing ) {
			$files[ self::BING_FILENAME ] = array(
				'content_type' => 'application/xml',
				'body'         => "<?xml version=\"1.0\"?>\n<users>\n  <user>" . $bing . "</user>\n</users>",
			);
		}

		$yandex = $this->settings->get_verification_file( 'yandex' );

		if ( '' !== $yandex ) {
			$token = substr( $yandex, strlen( 'yandex_' ), -strlen( '.html' ) );

			$files[ $yandex ] = array(
				'content_type' => 'text/html',
				'body'         => "<html>\n<head>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n</head>\n<body>Verification: " . $token . "</body>\n</html>",
			);
		}

		return $files;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Wire it into the plugin**

In `includes/Plugin.php`, add the import:

```php
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;
```

In `register_services()`, after the `verification_output` registration:

```php
		$c->register(
			'verification_file_server',
			fn( Container $c ) => new VerificationFileServer( $c->get( 'settings' ) )
		);
```

In `init_services()`, after the `verification_output` line:

```php
		$this->container->get( 'verification_file_server' )->init( $hook_manager );
```

- [ ] **Step 6: Run the full suite and lint**

Run: `make test && make lint`
Expected: PASS, no lint errors.

- [ ] **Step 7: Commit**

```bash
git add includes/Verification/VerificationFileServer.php includes/Plugin.php tests/Unit/Verification/VerificationFileServerTest.php
git commit -m "feat: serve webmaster verification files without writing to disk"
```

---

### Task 4: AnalyticsOutput — GA4 and Google Tag Manager

**Files:**
- Create: `includes/Analytics/AnalyticsOutput.php`
- Modify: `includes/Plugin.php`
- Test: `tests/Unit/Analytics/AnalyticsOutputTest.php`

**Interfaces:**
- Consumes: `Settings::get_ga4_id(): string`, `Settings::get_gtm_id(): string` (Task 1).
- Produces:
  - `AnalyticsOutput::__construct( Settings $settings )`
  - `AnalyticsOutput::init( HookManager $hook_manager ): void`
  - `AnalyticsOutput::enqueue_gtag(): void`, `AnalyticsOutput::print_gtm_head(): void`, `AnalyticsOutput::print_gtm_body(): void`
  - Filters: `taseo_tracking_should_print` (bool), `taseo_analytics_should_print` (bool), `taseo_analytics_ga4_ids` (`string[]`), `taseo_analytics_gtm_ids` (`string[]`), `taseo_analytics_gtag_config` (`array<string, array<string, mixed>>`).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Analytics/AnalyticsOutputTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\AnalyticsOutput;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( AnalyticsOutput::class )]
class AnalyticsOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private AnalyticsOutput $output;

	/**
	 * Enqueued scripts: handle => src.
	 *
	 * @var array<string, string>
	 */
	private array $enqueued = array();

	/**
	 * Inline scripts: handle => concatenated JS.
	 *
	 * @var array<string, string>
	 */
	private array $inline = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->enqueued = array();
		$this->inline   = array();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( '' )->byDefault();
		$this->settings->shouldReceive( 'get_gtm_id' )->andReturn( '' )->byDefault();

		$this->output = new AnalyticsOutput( $this->settings );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $js ): void {
				echo '<script>' . $js . '</script>';
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '' ): void {
				$this->enqueued[ $handle ] = $src;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( string $handle, string $js ): void {
				$this->inline[ $handle ] = ( $this->inline[ $handle ] ?? '' ) . $js;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueues_gtag_with_the_measurement_id(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );

		$this->output->enqueue_gtag();

		$this->assertArrayHasKey( 'taseo-gtag', $this->enqueued );
		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag']
		);
		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( 'window.dataLayer', $this->inline['taseo-gtag'] );
	}

	public function test_enqueues_nothing_without_a_measurement_id(): void {
		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_ga4_ids_filter_adds_a_secondary_property(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-PRIMARY1' );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->once()->andReturnUsing(
			static function ( array $ids ): array {
				$ids[] = 'G-SECOND22';

				return $ids;
			}
		);

		$this->output->enqueue_gtag();

		$this->assertStringContainsString( "gtag('config', 'G-PRIMARY1')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( "gtag('config', 'G-SECOND22')", $this->inline['taseo-gtag'] );
		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-PRIMARY1',
			$this->enqueued['taseo-gtag'],
			'The loader uses the first ID; the rest are config calls.'
		);
	}

	public function test_ga4_ids_filter_values_are_revalidated_and_deduplicated(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-PRIMARY1' );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->once()->andReturn(
			array( 'G-PRIMARY1', 'G-PRIMARY1', 'not-an-id', '"><script>' )
		);

		$this->output->enqueue_gtag();

		$this->assertSame( 1, substr_count( $this->inline['taseo-gtag'], "gtag('config'" ) );
		$this->assertStringNotContainsString( 'not-an-id', $this->inline['taseo-gtag'] );
		$this->assertStringNotContainsString( '<script>', $this->inline['taseo-gtag'] );
	}

	public function test_gtag_config_filter_adds_per_property_parameters(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_analytics_gtag_config' )->once()->andReturn(
			array( 'G-ABCD1234' => array( 'send_page_view' => false ) )
		);

		$this->output->enqueue_gtag();

		$this->assertStringContainsString(
			'gtag(\'config\', \'G-ABCD1234\', {"send_page_view":false})',
			$this->inline['taseo-gtag']
		);
	}

	public function test_prints_the_gtm_head_loader_and_body_noscript(): void {
		$this->settings->shouldReceive( 'get_gtm_id' )->andReturn( 'GTM-XYZ789' );

		ob_start();
		$this->output->print_gtm_head();
		$head = (string) ob_get_clean();

		ob_start();
		$this->output->print_gtm_body();
		$body = (string) ob_get_clean();

		$this->assertStringContainsString( 'GTM-XYZ789', $head );
		$this->assertStringContainsString( 'googletagmanager.com/gtm.js', $head );
		$this->assertStringContainsString( 'googletagmanager.com/ns.html?id=GTM-XYZ789', $body );
		$this->assertStringContainsString( '<noscript>', $body );
	}

	public function test_prints_no_gtm_output_without_a_container_id(): void {
		ob_start();
		$this->output->print_gtm_head();
		$this->output->print_gtm_body();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_prints_nothing_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );

		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_analytics_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_analytics_should_print' )->once()->andReturn( false );

		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_tracking_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_tracking_should_print' )->once()->andReturn( false );

		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `Error: Class "TheAnother\Plugin\SEO\Analytics\AnalyticsOutput" not found`

- [ ] **Step 3: Implement the class**

Create `includes/Analytics/AnalyticsOutput.php`:

```php
<?php
/**
 * Google Analytics and Tag Manager Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class AnalyticsOutput
 *
 * GA4 and Google Tag Manager. One class for both because they are one
 * vendor with one lineage — the same dataLayer, the same origin, and a real
 * interaction (a container that already fires a GA4 tag double-counts).
 * Meta Pixel lives in its own class for the opposite reasons.
 */
class AnalyticsOutput {

	/**
	 * Valid GA4 measurement ID.
	 *
	 * @var string
	 */
	private const GA4_PATTERN = '/^G-[A-Z0-9]{4,}$/';

	/**
	 * Valid GTM container ID.
	 *
	 * @var string
	 */
	private const GTM_PATTERN = '/^GTM-[A-Z0-9]{4,}$/';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_enqueue_scripts', array( $this, 'enqueue_gtag' ) );
		$hook_manager->register_action( 'wp_head', array( $this, 'print_gtm_head' ), 1 );
		$hook_manager->register_action( 'wp_body_open', array( $this, 'print_gtm_body' ) );
	}

	/**
	 * Enqueue gtag.js and its configuration.
	 *
	 * @return void
	 */
	public function enqueue_gtag(): void {
		$ids = $this->ga4_ids();

		if ( array() === $ids ) {
			return;
		}

		wp_enqueue_script(
			'taseo-gtag',
			'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ids[0] ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's endpoint is versionless; a ?ver= query would be sent upstream verbatim.
			false
		);

		/**
		 * Filters per-property gtag configuration parameters.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $config Measurement ID => parameters.
		 */
		$config = apply_filters( 'taseo_analytics_gtag_config', array() );
		$config = is_array( $config ) ? $config : array();

		$inline = "window.dataLayer = window.dataLayer || [];\n"
			. "function gtag(){dataLayer.push(arguments);}\n"
			. "gtag('js', new Date());\n";

		foreach ( $ids as $id ) {
			$params = isset( $config[ $id ] ) && is_array( $config[ $id ] ) ? $config[ $id ] : array();

			$inline .= array() === $params
				? "gtag('config', '" . $id . "');\n"
				: "gtag('config', '" . $id . "', " . wp_json_encode( $params ) . ");\n";
		}

		wp_add_inline_script( 'taseo-gtag', $inline, 'after' );
	}

	/**
	 * Print the Tag Manager container loader.
	 *
	 * @return void
	 */
	public function print_gtm_head(): void {
		foreach ( $this->gtm_ids() as $id ) {
			wp_print_inline_script_tag(
				"(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n"
				. "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n"
				. "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n"
				. "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n"
				. "})(window,document,'script','dataLayer','" . $id . "');"
			);
		}
	}

	/**
	 * Print the Tag Manager no-JS fallback.
	 *
	 * @return void
	 */
	public function print_gtm_body(): void {
		foreach ( $this->gtm_ids() as $id ) {
			printf(
				'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
				esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $id ) )
			);
		}
	}

	/**
	 * GA4 measurement IDs for this request.
	 *
	 * @return array<int, string> Validated, de-duplicated IDs.
	 */
	private function ga4_ids(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$stored = $this->settings->get_ga4_id();
		$ids    = '' === $stored ? array() : array( $stored );

		/**
		 * Filters the GA4 measurement IDs emitted on this request.
		 *
		 * Append an ID here to send a specific page to a secondary property.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $ids Measurement IDs.
		 */
		return $this->clean_ids( apply_filters( 'taseo_analytics_ga4_ids', $ids ), self::GA4_PATTERN );
	}

	/**
	 * GTM container IDs for this request.
	 *
	 * @return array<int, string> Validated, de-duplicated IDs.
	 */
	private function gtm_ids(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$stored = $this->settings->get_gtm_id();
		$ids    = '' === $stored ? array() : array( $stored );

		/**
		 * Filters the GTM container IDs emitted on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $ids Container IDs.
		 */
		return $this->clean_ids( apply_filters( 'taseo_analytics_gtm_ids', $ids ), self::GTM_PATTERN );
	}

	/**
	 * Whether Google tracking output is allowed on this request.
	 *
	 * @return bool Allowed.
	 */
	private function should_print(): bool {
		$default = ! is_admin() && ! is_customize_preview();

		/**
		 * Filters whether ANY tracking output is emitted on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $default Whether to emit.
		 */
		if ( ! (bool) apply_filters( 'taseo_tracking_should_print', $default ) ) {
			return false;
		}

		/**
		 * Filters whether Google tracking output is emitted on this request.
		 *
		 * The analytics-category consent gate.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether to emit.
		 */
		return (bool) apply_filters( 'taseo_analytics_should_print', true );
	}

	/**
	 * Validate, de-duplicate, and re-index a list of IDs.
	 *
	 * Filter return values get the same validation as stored ones: a filter
	 * is third-party code, and trusting it would hand any plugin on the site
	 * a script-injection path into <head>.
	 *
	 * @param mixed  $ids     Candidate IDs.
	 * @param string $pattern Validation pattern.
	 * @return array<int, string> Clean IDs.
	 */
	private function clean_ids( mixed $ids, string $pattern ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = strtoupper( trim( $id ) );

			if ( 1 === preg_match( $pattern, $id ) && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Wire it into the plugin**

In `includes/Plugin.php`, add the import (alphabetically first among the new ones — `Analytics` sorts before `Admin`? No: `Admin` < `Analytics` < `Blocks`, so place it after the `Admin\SettingsPage` import):

```php
use TheAnother\Plugin\SEO\Analytics\AnalyticsOutput;
```

In `register_services()`, after the `verification_file_server` registration:

```php
		$c->register(
			'analytics_output',
			fn( Container $c ) => new AnalyticsOutput( $c->get( 'settings' ) )
		);
```

In `init_services()`, after the `verification_file_server` line:

```php
		$this->container->get( 'analytics_output' )->init( $hook_manager );
```

- [ ] **Step 6: Run the full suite and lint**

Run: `make test && make lint`
Expected: PASS, no lint errors.

- [ ] **Step 7: Commit**

```bash
git add includes/Analytics/AnalyticsOutput.php includes/Plugin.php tests/Unit/Analytics/AnalyticsOutputTest.php
git commit -m "feat: emit GA4 and Tag Manager snippets"
```

---

### Task 5: MetaPixelOutput

**Files:**
- Create: `includes/Analytics/MetaPixelOutput.php`
- Modify: `includes/Plugin.php`
- Test: `tests/Unit/Analytics/MetaPixelOutputTest.php`

**Interfaces:**
- Consumes: `Settings::get_meta_pixel_id(): string` (Task 1).
- Produces:
  - `MetaPixelOutput::__construct( Settings $settings )`
  - `MetaPixelOutput::init( HookManager $hook_manager ): void`
  - `MetaPixelOutput::print_head(): void`, `MetaPixelOutput::print_body(): void`
  - Filters: `taseo_meta_pixel_ids` (`string[]`), `taseo_meta_pixel_should_print` (bool). Also honours `taseo_tracking_should_print` from Task 4.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Analytics/MetaPixelOutputTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\MetaPixelOutput;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( MetaPixelOutput::class )]
class MetaPixelOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private MetaPixelOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '' )->byDefault();

		$this->output = new MetaPixelOutput( $this->settings );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $js ): void {
				echo '<script>' . $js . '</script>';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function head(): string {
		ob_start();
		$this->output->print_head();

		return (string) ob_get_clean();
	}

	private function body(): string {
		ob_start();
		$this->output->print_body();

		return (string) ob_get_clean();
	}

	public function test_prints_the_base_code_with_init_and_pageview(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );

		$head = $this->head();

		$this->assertStringContainsString( 'connect.facebook.net/en_US/fbevents.js', $head );
		$this->assertStringContainsString( "fbq('init', '123456789012345')", $head );
		$this->assertStringContainsString( "fbq('track', 'PageView')", $head );
	}

	public function test_prints_the_noscript_fallback_image(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );

		$body = $this->body();

		$this->assertStringContainsString( '<noscript>', $body );
		$this->assertStringContainsString(
			'https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1',
			$body
		);
	}

	public function test_preserves_a_leading_zero_in_the_pixel_id(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '0123456789012' );

		$this->assertStringContainsString( "fbq('init', '0123456789012')", $this->head() );
	}

	public function test_prints_nothing_without_a_pixel_id(): void {
		$this->assertSame( '', $this->head() );
		$this->assertSame( '', $this->body() );
	}

	public function test_emits_one_init_per_id_and_a_single_pageview(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '111111111111111' );
		Filters\expectApplied( 'taseo_meta_pixel_ids' )->once()->andReturnUsing(
			static function ( array $ids ): array {
				$ids[] = '222222222222222';

				return $ids;
			}
		);

		$head = $this->head();

		$this->assertSame( 2, substr_count( $head, "fbq('init'" ) );
		$this->assertSame( 1, substr_count( $head, "fbq('track', 'PageView')" ) );
		$this->assertSame( 1, substr_count( $head, 'fbevents.js' ) );
	}

	public function test_filter_ids_are_revalidated_and_deduplicated(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '111111111111111' );
		Filters\expectApplied( 'taseo_meta_pixel_ids' )->once()->andReturn(
			array( '111111111111111', '111111111111111', 'not-numeric', '"><script>alert(1)</script>' )
		);

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, "fbq('init'" ) );
		$this->assertStringNotContainsString( 'not-numeric', $head );
		$this->assertStringNotContainsString( 'alert(1)', $head );
	}

	public function test_prints_nothing_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );

		$this->assertSame( '', $this->head() );
	}

	public function test_meta_pixel_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );
		Filters\expectApplied( 'taseo_meta_pixel_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->head() );
	}

	public function test_tracking_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );
		Filters\expectApplied( 'taseo_tracking_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->head() );
	}

	public function test_marketing_consent_gate_does_not_touch_the_analytics_gate(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );
		Filters\expectApplied( 'taseo_analytics_should_print' )->never();
		Filters\expectApplied( 'taseo_meta_pixel_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->head() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `Error: Class "TheAnother\Plugin\SEO\Analytics\MetaPixelOutput" not found`

- [ ] **Step 3: Implement the class**

Create `includes/Analytics/MetaPixelOutput.php`:

```php
<?php
/**
 * Meta Pixel Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class MetaPixelOutput
 *
 * Meta Pixel base code and its no-JS fallback. Separate from
 * AnalyticsOutput: different vendor, different origin, different consent
 * category, and no interaction with the Google tags.
 *
 * The vendor snippet is emitted intact rather than split into an enqueued
 * fbevents.js plus an inline fbq('init'): the stub must exist before
 * fbevents.js drains its queue, and hand-splitting it fails silently.
 */
class MetaPixelOutput {

	/**
	 * Valid pixel ID — a bare numeric string.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[0-9]{10,20}$/';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_head' ), 2 );
		$hook_manager->register_action( 'wp_body_open', array( $this, 'print_body' ) );
	}

	/**
	 * Print the pixel base code.
	 *
	 * @return void
	 */
	public function print_head(): void {
		$ids = $this->pixel_ids();

		if ( array() === $ids ) {
			return;
		}

		$js = "!function(f,b,e,v,n,t,s)\n"
			. "{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n"
			. "n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n"
			. "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n"
			. "n.queue=[];t=b.createElement(e);t.async=!0;\n"
			. "t.src=v;s=b.getElementsByTagName(e)[0];\n"
			. "s.parentNode.insertBefore(t,s)}(window, document,'script',\n"
			. "'https://connect.facebook.net/en_US/fbevents.js');\n";

		foreach ( $ids as $id ) {
			$js .= "fbq('init', '" . $id . "');\n";
		}

		// One track call fires against every initialised pixel — Meta's
		// documented multi-pixel pattern.
		$js .= "fbq('track', 'PageView');\n";

		wp_print_inline_script_tag( $js );
	}

	/**
	 * Print the no-JS fallback image.
	 *
	 * Meta's copy-paste snippet puts this in <head>; an <img> there forces
	 * the parser out of head exactly when scripting is disabled, so it goes
	 * in the body instead. The browser requests the same URL either way.
	 *
	 * @return void
	 */
	public function print_body(): void {
		foreach ( $this->pixel_ids() as $id ) {
			printf(
				'<noscript><img height="1" width="1" style="display:none" alt="" src="%s" /></noscript>' . "\n",
				esc_url( 'https://www.facebook.com/tr?id=' . $id . '&ev=PageView&noscript=1' )
			);
		}
	}

	/**
	 * Pixel IDs for this request.
	 *
	 * @return array<int, string> Validated, de-duplicated IDs.
	 */
	private function pixel_ids(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$stored = $this->settings->get_meta_pixel_id();
		$ids    = '' === $stored ? array() : array( $stored );

		/**
		 * Filters the Meta Pixel IDs emitted on this request.
		 *
		 * Append an ID here to fire a secondary pixel on specific pages.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $ids Pixel IDs.
		 */
		$ids = apply_filters( 'taseo_meta_pixel_ids', $ids );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = trim( $id );

			if ( 1 === preg_match( self::ID_PATTERN, $id ) && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Whether pixel output is allowed on this request.
	 *
	 * @return bool Allowed.
	 */
	private function should_print(): bool {
		$default = ! is_admin() && ! is_customize_preview();

		/** This filter is documented in includes/Analytics/AnalyticsOutput.php */
		if ( ! (bool) apply_filters( 'taseo_tracking_should_print', $default ) ) {
			return false;
		}

		/**
		 * Filters whether Meta Pixel output is emitted on this request.
		 *
		 * The marketing-category consent gate, separate from the analytics
		 * gate so a site with analytics consent but not marketing consent
		 * can suppress one without losing the other.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether to emit.
		 */
		return (bool) apply_filters( 'taseo_meta_pixel_should_print', true );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Wire it into the plugin**

In `includes/Plugin.php`, add the import after the `AnalyticsOutput` one:

```php
use TheAnother\Plugin\SEO\Analytics\MetaPixelOutput;
```

In `register_services()`, after the `analytics_output` registration:

```php
		$c->register(
			'meta_pixel_output',
			fn( Container $c ) => new MetaPixelOutput( $c->get( 'settings' ) )
		);
```

In `init_services()`, after the `analytics_output` line:

```php
		$this->container->get( 'meta_pixel_output' )->init( $hook_manager );
```

- [ ] **Step 6: Run the full suite and lint**

Run: `make test && make lint`
Expected: PASS, no lint errors.

- [ ] **Step 7: Commit**

```bash
git add includes/Analytics/MetaPixelOutput.php includes/Plugin.php tests/Unit/Analytics/MetaPixelOutputTest.php
git commit -m "feat: emit Meta Pixel base code and noscript fallback"
```

---

### Task 6: Webmaster Tools settings tab

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Settings` getters (Task 1), `VerificationOutput::sanitize_code()` (Task 2).
- Produces: a `webmaster` tab; `sanitize_settings()` handling for all eleven keys; a tab-preserving save redirect.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Admin/SettingsPageTest.php`, before the closing brace. If the file's existing helper for building a `SettingsPage` differs from `$this->page`, adapt these to match it — read the file's `setUp()` first.

```php
	public function test_sanitizes_verification_codes_from_pasted_meta_tags(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'verify_google'   => '<meta name="google-site-verification" content="AbC123_-xyz" />',
				'verify_bing'     => '  BINGTOKEN  ',
				'verify_yandex'   => 'yan"dex<token>',
				'verify_yahoo'    => 'yahootoken',
				'verify_facebook' => 'metatoken',
			),
			'webmaster'
		);

		$this->assertSame( 'AbC123_-xyz', $clean['verify_google'] );
		$this->assertSame( 'BINGTOKEN', $clean['verify_bing'] );
		$this->assertSame( 'yandextoken', $clean['verify_yandex'] );
		$this->assertSame( 'yahootoken', $clean['verify_yahoo'] );
		$this->assertSame( 'metatoken', $clean['verify_facebook'] );
	}

	public function test_accepts_valid_verification_filenames(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'verify_google_file' => 'google1a2b3c.html',
				'verify_bing_file'   => 'BINGTOKEN123',
				'verify_yandex_file' => 'yandex_9f8e7d.html',
			),
			'webmaster'
		);

		$this->assertSame( 'google1a2b3c.html', $clean['verify_google_file'] );
		$this->assertSame( 'BINGTOKEN123', $clean['verify_bing_file'] );
		$this->assertSame( 'yandex_9f8e7d.html', $clean['verify_yandex_file'] );
	}

	public function test_rejects_verification_filenames_containing_paths(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'verify_google_file' => '../wp-config.php',
				'verify_yandex_file' => 'yandex_x.html/../../etc/passwd',
			),
			'webmaster'
		);

		$this->assertSame( '', $clean['verify_google_file'] );
		$this->assertSame( '', $clean['verify_yandex_file'] );
	}

	public function test_rejects_a_verification_filename_with_the_wrong_prefix(): void {
		$clean = $this->page->sanitize_settings(
			array( 'verify_google_file' => 'notgoogle123.html' ),
			'webmaster'
		);

		$this->assertSame( '', $clean['verify_google_file'] );
	}

	public function test_normalizes_and_validates_tracking_ids(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'analytics_ga4_id' => ' g-abcd1234 ',
				'analytics_gtm_id' => 'gtm-xyz789',
				'meta_pixel_id'    => ' 0123456789012 ',
			),
			'webmaster'
		);

		$this->assertSame( 'G-ABCD1234', $clean['analytics_ga4_id'] );
		$this->assertSame( 'GTM-XYZ789', $clean['analytics_gtm_id'] );
		$this->assertSame( '0123456789012', $clean['meta_pixel_id'] );
	}

	public function test_rejects_malformed_tracking_ids(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'analytics_ga4_id' => 'UA-12345-1',
				'analytics_gtm_id' => 'GTM',
				'meta_pixel_id'    => '12345',
			),
			'webmaster'
		);

		$this->assertSame( '', $clean['analytics_ga4_id'] );
		$this->assertSame( '', $clean['analytics_gtm_id'] );
		$this->assertSame( '', $clean['meta_pixel_id'] );
	}

	public function test_clearing_a_verification_field_clears_the_stored_key(): void {
		$clean = $this->page->sanitize_settings( array( 'verify_google' => '' ), 'webmaster' );

		$this->assertSame( '', $clean['verify_google'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — assertions fail because `sanitize_settings()` returns no `verify_*` keys yet.

- [ ] **Step 3: Add the tab, its renderer, and the sanitizer loops**

In `includes/Admin/SettingsPage.php`:

**3a.** Add the import next to the existing ones:

```php
use TheAnother\Plugin\SEO\Verification\VerificationOutput;
```

**3b.** Add `'webmaster' => 'Webmaster Tools',` as the last entry of the `TABS` constant.

**3c.** Add these three constants after `SCHEMA_TYPE_CHOICES`:

```php
	/**
	 * Verification meta-tag settings keys.
	 *
	 * @var array<int, string>
	 */
	private const VERIFICATION_CODE_KEYS = array(
		'verify_google',
		'verify_bing',
		'verify_yandex',
		'verify_yahoo',
		'verify_facebook',
	);

	/**
	 * Verification file settings keys => validation pattern.
	 *
	 * Anchored, and allowing no slash or dot beyond the single extension:
	 * these values are compared against an incoming request path.
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_FILE_PATTERNS = array(
		'verify_google_file' => '/^google[a-z0-9]+\.html$/',
		'verify_bing_file'   => '/^[A-Za-z0-9]+$/',
		'verify_yandex_file' => '/^yandex_[a-z0-9]+\.html$/',
	);

	/**
	 * Tracking ID settings keys => validation pattern.
	 *
	 * @var array<string, string>
	 */
	private const TRACKING_ID_PATTERNS = array(
		'analytics_ga4_id' => '/^G-[A-Z0-9]{4,}$/',
		'analytics_gtm_id' => '/^GTM-[A-Z0-9]{4,}$/',
		'meta_pixel_id'    => '/^[0-9]{10,20}$/',
	);
```

**3d.** Add `'webmaster' => $this->render_webmaster_tab(),` to the `match` in `render_page()`, before `default`.

**3e.** Add the renderer after `render_sitemap_tab()`:

```php
	/**
	 * Webmaster Tools tab: site verification and tracking snippets.
	 *
	 * @return void
	 */
	private function render_webmaster_tab(): void {
		$services = array(
			'google'   => array( __( 'Google Search Console', 'the-another-seo' ), 'verify_google', 'verify_google_file', __( 'File name, e.g. google1a2b3c.html', 'the-another-seo' ) ),
			'bing'     => array( __( 'Bing Webmaster Tools', 'the-another-seo' ), 'verify_bing', 'verify_bing_file', __( 'Token from BingSiteAuth.xml', 'the-another-seo' ) ),
			'yandex'   => array( __( 'Yandex Webmaster', 'the-another-seo' ), 'verify_yandex', 'verify_yandex_file', __( 'File name, e.g. yandex_9f8e7d.html', 'the-another-seo' ) ),
			'yahoo'    => array( __( 'Yahoo', 'the-another-seo' ), 'verify_yahoo', '', '' ),
			'facebook' => array( __( 'Meta Business Manager', 'the-another-seo' ), 'verify_facebook', '', '' ),
		);

		echo '<h2>' . esc_html__( 'Site verification', 'the-another-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste the verification code or the whole meta tag — either works. Verification tags are printed on the front page only.', 'the-another-seo' ) . '</p>';
		echo '<table class="form-table">';

		foreach ( $services as $engine => $service ) {
			list( $label, $code_key, $file_key, $file_hint ) = $service;

			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" name="taseo_settings[%2$s]" value="%3$s" class="regular-text" placeholder="%4$s" />',
				esc_html( $label ),
				esc_attr( $code_key ),
				esc_attr( $this->settings->get_verification_code( $engine ) ),
				esc_attr__( 'Verification code', 'the-another-seo' )
			);

			if ( '' !== $file_key ) {
				$file_value = $this->settings->get_verification_file( $engine );

				printf(
					'<br /><input type="text" name="taseo_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
					esc_attr( $file_key ),
					esc_attr( $file_value ),
					esc_attr( $file_hint )
				);

				if ( '' !== $file_value ) {
					$filename = 'bing' === $engine ? VerificationFileServer::BING_FILENAME : $file_value;

					printf(
						' <a href="%1$s" target="_blank" rel="noreferrer noopener">%1$s</a>',
						esc_url( home_url( '/' . $filename ) )
					);
				}
			}

			echo '</td></tr>';
		}

		echo '</table>';

		echo '<h2>' . esc_html__( 'Tracking', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[analytics_ga4_id]" value="%s" placeholder="G-XXXXXXXXXX" /></td></tr>',
			esc_html__( 'GA4 Measurement ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_ga4_id() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[analytics_gtm_id]" value="%s" placeholder="GTM-XXXXXXX" /></td></tr>',
			esc_html__( 'Tag Manager Container ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_gtm_id() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[meta_pixel_id]" value="%s" placeholder="123456789012345" /></td></tr>',
			esc_html__( 'Meta Pixel ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_meta_pixel_id() )
		);
		echo '</table>';

		if ( '' !== $this->settings->get_ga4_id() && '' !== $this->settings->get_gtm_id() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Both a GA4 Measurement ID and a Tag Manager Container ID are set. If your Tag Manager container already fires a GA4 tag, pageviews will be counted twice.', 'the-another-seo' )
			);
		}
	}
```

Add the matching import for the filename constant:

```php
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;
```

**3f.** Add the three sanitizer loops in `sanitize_settings()`, immediately before the `if ( 'social' === $tab )` block:

```php
		foreach ( self::VERIFICATION_CODE_KEYS as $code_key ) {
			if ( isset( $raw[ $code_key ] ) ) {
				$clean[ $code_key ] = VerificationOutput::sanitize_code( (string) $raw[ $code_key ] );
			}
		}

		foreach ( self::VERIFICATION_FILE_PATTERNS as $file_key => $pattern ) {
			if ( ! isset( $raw[ $file_key ] ) ) {
				continue;
			}

			$value = trim( (string) $raw[ $file_key ] );
			$value = 'verify_bing_file' === $file_key ? $value : strtolower( $value );

			$clean[ $file_key ] = 1 === preg_match( $pattern, $value ) ? $value : '';
		}

		foreach ( self::TRACKING_ID_PATTERNS as $id_key => $pattern ) {
			if ( ! isset( $raw[ $id_key ] ) ) {
				continue;
			}

			$value = trim( (string) $raw[ $id_key ] );
			$value = 'meta_pixel_id' === $id_key ? $value : strtoupper( $value );

			$clean[ $id_key ] = 1 === preg_match( $pattern, $value ) ? $value : '';
		}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Write the failing test for the tab-preserving redirect**

Append to `tests/Unit/Admin/SettingsPageTest.php`. Read the file's existing `handle_save` test first and mirror its mocking of `wp_safe_redirect`, `admin_url`, and the nonce/capability helpers — this test only changes the expected URL.

```php
	public function test_save_redirect_preserves_the_active_tab(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['tab']                  = 'webmaster';
		$_POST['taseo_settings']       = array( 'verify_google' => 'googletoken' );

		$redirected = '';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ) => $url . '&' . $key . '=' . $value
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ) use ( &$redirected ): void {
				$redirected = $location;
			}
		);

		$this->settings->shouldReceive( 'update' )->once();

		$this->page->handle_save( false );

		$this->assertStringContainsString( 'tab=webmaster', $redirected );
	}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `make test`
Expected: FAIL — the redirect URL has no `tab=` parameter.

- [ ] **Step 7: Preserve the tab on redirect**

In `handle_save()`, replace the `wp_safe_redirect(...)` call with:

```php
		$redirect = admin_url( 'options-general.php?page=taseo&updated=1' );

		if ( array_key_exists( $tab, self::TABS ) ) {
			$redirect = add_query_arg( 'tab', $tab, $redirect );
		}

		// phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- conditional exit based on testability flag.
		wp_safe_redirect( $redirect );
```

- [ ] **Step 8: Run the full suite and lint**

Run: `make test && make lint`
Expected: PASS, no lint errors.

- [ ] **Step 9: Commit**

```bash
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: add Webmaster Tools settings tab"
```

---

### Task 7: Documentation and .org disclosure

**Files:**
- Modify: `readme.txt`, `CHANGELOG.md`, `README.md`

**Why this is its own task:** Plugin Check is one of the four CI jobs, and it flags a plugin that contacts a third-party service without an External services disclosure. Without this task, CI fails on the branch.

- [ ] **Step 1: Add the External services section to `readme.txt`**

Insert immediately before the `== Frequently Asked Questions ==` section (or before `== Changelog ==` if there is no FAQ section):

```
== External services ==

This plugin can load third-party scripts, but only when you configure them. With no IDs entered, the plugin contacts no external service.

**Google Analytics (GA4) and Google Tag Manager**

Loaded only when you enter a GA4 Measurement ID or a Tag Manager Container ID on the Webmaster Tools settings tab. These scripts are served from `googletagmanager.com` (`/gtag/js`, `/gtm.js`, `/ns.html`) and send your visitors' IP address, user agent, and the URL being viewed to Google, which uses them for analytics measurement.

Terms of service: https://policies.google.com/terms — Privacy policy: https://policies.google.com/privacy

**Meta Pixel**

Loaded only when you enter a Meta Pixel ID on the Webmaster Tools settings tab. The script is served from `connect.facebook.net` and sends your visitors' IP address, user agent, the URL being viewed, and any Meta cookies already present in the browser to Meta, which uses them for advertising measurement and ad targeting. A tracking image is also requested from `facebook.com/tr`.

Terms of service: https://www.facebook.com/legal/terms/businesstools — Privacy policy: https://www.facebook.com/privacy/policy/

Site verification meta tags and verification files contact no external service; a search engine fetches them from your site.
```

- [ ] **Step 2: Add the feature bullets to `readme.txt`'s Description list**

Append to the existing bullet list under `== Description ==`:

```
* **Site verification** — Google Search Console, Bing, Yandex, Yahoo, and Meta domain verification by meta tag, plus virtually-served verification files for Google, Bing, and Yandex (no FTP, nothing written to disk).
* **Tracking snippets** — GA4, Google Tag Manager, and Meta Pixel, with filters for per-page secondary properties and consent gating.
```

- [ ] **Step 3: Add the CHANGELOG entries**

Under `## [Unreleased]` → `### Added` in `CHANGELOG.md`:

```markdown
- Site verification: `google-site-verification`, `msvalidate.01`, `yandex-verification`, `y_key`, and `facebook-domain-verification` meta tags on the front page, plus virtually-served verification files (`google<token>.html`, `BingSiteAuth.xml`, `yandex_<token>.html`) with byte-exact bodies and no file written to disk.
- Tracking snippets: GA4 (`gtag.js`), Google Tag Manager, and Meta Pixel, configured on a new **Webmaster Tools** settings tab.
- Filters for programmatic extension: `taseo_verification_tags`, `taseo_verification_files`, `taseo_verification_should_print`, `taseo_analytics_ga4_ids`, `taseo_analytics_gtm_ids`, `taseo_analytics_gtag_config`, `taseo_meta_pixel_ids`, and three consent gates — `taseo_tracking_should_print`, `taseo_analytics_should_print`, `taseo_meta_pixel_should_print`.
```

Under `## [Unreleased]`, add a `### Fixed` section (create it if absent):

```markdown
- Saving settings now returns you to the tab you were on instead of bouncing to General.
```

- [ ] **Step 4: Add the feature bullets to `README.md`**

Append to the Features list:

```markdown
- **Site verification** — meta tags for Google, Bing, Yandex, Yahoo, and Meta,
  plus virtually-served verification files for Google, Bing, and Yandex.
- **Tracking snippets** — GA4, Google Tag Manager, and Meta Pixel, with filters
  for per-page secondary properties and consent gating.
```

- [ ] **Step 5: Verify Plugin Check passes**

Run: `make check-plugin`
Expected: PASS. If it reports a missing or malformed external-services disclosure, adjust the `readme.txt` wording it names and re-run.

- [ ] **Step 6: Commit**

```bash
git add readme.txt CHANGELOG.md README.md
git commit -m "docs: disclose external services and document verification/tracking"
```

---

### Task 8: End-to-end coverage

**Files:**
- Create: `tests/e2e/functional/specs/webmaster.spec.ts`
- Modify: `tests/e2e/functional/environment/serve-wp.sh`

**Why e2e matters here specifically:** the unit tests cannot see whether a theme or another plugin leaks output into the verification-file response. Byte-exactness is the one thing every service's documentation warns about, so it needs an assertion against a real HTTP response.

- [ ] **Step 1: Seed the settings in the e2e environment**

In `tests/e2e/functional/environment/serve-wp.sh`, after the `wp rewrite flush` line and before the Action Scheduler drain block, add:

```sh
# Seed verification and tracking settings so the webmaster spec has
# deterministic values to assert against. `option patch insert` writes one
# key inside the serialized taseo_settings array without clobbering the rest.
wp option patch insert taseo_settings verify_google 'googlee2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_bing 'BINGE2ETOKEN' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_yandex 'yandexe2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_yahoo 'yahooe2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_facebook 'metae2etoken' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_google_file 'googlee2efile.html' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings verify_bing_file 'BINGFILETOKEN' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings analytics_ga4_id 'G-E2E12345' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings analytics_gtm_id 'GTM-E2E1234' --path="$WP_DIR" --allow-root
wp option patch insert taseo_settings meta_pixel_id '123456789012345' --path="$WP_DIR" --allow-root
```

- [ ] **Step 2: Write the spec**

Create `tests/e2e/functional/specs/webmaster.spec.ts`:

```typescript
/**
 * Site verification tags and files, and tracking snippets.
 *
 * The verification-file assertions compare the FULL response body, not a
 * substring: Google, Bing, and Yandex all fail verification when the CMS
 * injects extra whitespace or markup, and a substring match would not see
 * that. This is the assertion unit tests cannot make.
 *
 * Values are seeded by tests/e2e/functional/environment/serve-wp.sh.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'webmaster verification and tracking', () => {
	test( 'verification meta tags on the front page', async ( { page } ) => {
		await page.goto( '/' );

		await expect(
			page.locator( 'meta[name="google-site-verification"]' )
		).toHaveAttribute( 'content', 'googlee2etoken' );
		await expect(
			page.locator( 'meta[name="msvalidate.01"]' )
		).toHaveAttribute( 'content', 'BINGE2ETOKEN' );
		await expect(
			page.locator( 'meta[name="yandex-verification"]' )
		).toHaveAttribute( 'content', 'yandexe2etoken' );
		await expect( page.locator( 'meta[name="y_key"]' ) ).toHaveAttribute(
			'content',
			'yahooe2etoken'
		);
		await expect(
			page.locator( 'meta[name="facebook-domain-verification"]' )
		).toHaveAttribute( 'content', 'metae2etoken' );
	} );

	test( 'verification tags are absent on a single post', async ( {
		page,
	} ) => {
		await page.goto( '/seo-target-post/' );

		await expect(
			page.locator( 'meta[name="google-site-verification"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'meta[name="msvalidate.01"]' )
		).toHaveCount( 0 );
	} );

	test( 'Google verification file is served byte-exact', async ( {
		request,
	} ) => {
		const response = await request.get( '/googlee2efile.html' );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toContain( 'text/html' );
		expect( response.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( await response.text() ).toBe(
			'google-site-verification: googlee2efile.html'
		);
	} );

	test( 'Bing verification file is served byte-exact', async ( {
		request,
	} ) => {
		const response = await request.get( '/BingSiteAuth.xml' );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toContain(
			'application/xml'
		);
		expect( await response.text() ).toBe(
			'<?xml version="1.0"?>\n<users>\n  <user>BINGFILETOKEN</user>\n</users>'
		);
	} );

	test( 'an unconfigured verification filename still 404s', async ( {
		request,
	} ) => {
		const response = await request.get( '/googlewrongtoken.html' );

		expect( response.status() ).toBe( 404 );
	} );

	test( 'GA4 and Tag Manager snippets', async ( { page } ) => {
		await page.goto( '/' );

		await expect(
			page.locator( 'script[src*="googletagmanager.com/gtag/js"]' )
		).toHaveCount( 1 );
		await expect(
			page.locator( 'script[src*="id=G-E2E12345"]' )
		).toHaveCount( 1 );

		const html = await page.content();
		expect( html ).toContain( "gtag('config', 'G-E2E12345')" );
		expect( html ).toContain( 'GTM-E2E1234' );
	} );

	test( 'Meta Pixel base code and noscript fallback', async ( { page } ) => {
		await page.goto( '/' );

		const html = await page.content();
		expect( html ).toContain( 'connect.facebook.net/en_US/fbevents.js' );
		expect( html ).toContain( "fbq('init', '123456789012345')" );

		await expect(
			page.locator(
				'noscript img[src*="facebook.com/tr?id=123456789012345"]'
			)
		).toHaveCount( 1 );
	} );
} );
```

- [ ] **Step 3: Run the e2e suite**

Run: `make test-e2e`
Expected: PASS, including the four pre-existing spec files.

If the Meta Pixel `noscript img` locator finds 0 elements, the active theme is not firing `wp_body_open`. Confirm by searching the page HTML for `facebook.com/tr` — if it is absent entirely, the hook is the cause; note it in the spec file and assert on `page.content()` instead of the locator. Do **not** work around it in the plugin: the spec accepts this limitation deliberately.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/functional/specs/webmaster.spec.ts tests/e2e/functional/environment/serve-wp.sh
git commit -m "test: add e2e coverage for verification tags, files, and tracking"
```

---

### Task 9: Full gate and pull request

**Files:** none — this task verifies and ships.

- [ ] **Step 1: Run the complete local gate**

Run each, in order, and confirm each passes before moving on:

```bash
make lint
make test
make test-e2e
make check-plugin
```

These are the same four jobs `.github/workflows/ci.yml` runs on the pull request. A failure here is a failure there.

- [ ] **Step 2: Confirm the additive claim holds**

The spec claims that with empty settings the rendered output is byte-identical to before. Verify it: temporarily clear the seeded options in a scratch WordPress instance (or comment out the `wp option patch` block from Task 8 and re-run `make test-e2e`), and confirm the pre-existing four specs still pass with no verification or tracking markup present. Restore the block afterwards.

- [ ] **Step 3: Push the branch**

```bash
git push -u origin feature/webmaster-verification
```

- [ ] **Step 4: Open the pull request**

```bash
gh pr create --title "Webmaster verification and tracking" --body "$(cat <<'EOF'
## Summary

Adds site verification and tracking-snippet output, per
`docs/superpowers/specs/2026-07-25-webmaster-verification-analytics-design.md`.

- **Verification meta tags** — Google, Bing, Yandex, Yahoo, Meta domain
  verification. Front page only.
- **Verification files** — `google<token>.html`, `BingSiteAuth.xml`,
  `yandex_<token>.html`, served virtually with byte-exact bodies. No rewrite
  rules, so no rewrite flush is forced on upgrading sites.
- **Tracking** — GA4, Google Tag Manager, Meta Pixel.
- **Filters** — per-page secondary tracking IDs, extra verification services
  and files, and three consent gates (global, analytics, marketing).
- **Fixed** — saving settings no longer bounces you off your current tab.

## Test plan

- [x] `make lint`
- [x] `make test`
- [x] `make test-e2e`
- [x] `make check-plugin`

`readme.txt` gains the External services disclosure that Plugin Check
requires for `googletagmanager.com`, `connect.facebook.net`, and
`facebook.com/tr`.

**Not included:** DNS TXT verification (needs a DNS-provider API
integration; it is the only method that verifies a Search Console *Domain*
property) and Meta's verification *file* method (Meta does not publish the
file's body format, so it cannot be generated from a filename).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 5: Confirm CI is green**

Run: `gh pr checks --watch`
Expected: all four jobs pass. Note that this PR carries no version bump — the version bump belongs in the release PR, not here.

---

## Notes for the implementer

**Do not add a version bump in this branch.** The release pipeline tags `v<version>` from `package.json` on every push to `master`, and the project's release flow puts the bump in a dedicated release PR.

**If `PluginTest` fails after Tasks 2–5**, it is asserting on the registered service list. Update the expectation to include `verification_output`, `verification_file_server`, `analytics_output`, and `meta_pixel_output` — do not remove the assertion.

**Brain Monkey gotcha:** `Filters\expectApplied( 'x' )->once()` fails the test if the filter is applied zero times *or* more than once. Where a method calls a filter conditionally, either assert with `->andReturn()` only after arranging the conditions that reach it, or use `Filters\expectApplied( 'x' )->never()` to prove it is not reached — the pattern used in `test_marketing_consent_gate_does_not_touch_the_analytics_gate`.

**`wp_body_open` in unit tests** is never fired by Brain Monkey; the tests call `print_body()` / `print_gtm_body()` directly. That is deliberate — the hook wiring is asserted by the e2e suite, which runs a real theme.
