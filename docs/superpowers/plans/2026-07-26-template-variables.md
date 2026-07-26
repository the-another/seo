# Template Variables Registry, Pills, Autocomplete & Validation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make one registry the single source of truth for which `%%variables%%` exist in which context, surface it per row as core-button pills and a `%%`-triggered autocomplete, and reject templates containing variables that will not resolve.

**Architecture:** A new `Meta/TemplateVariables` replaces two disagreeing sources — the hardcoded "Available variables:" line and the implicit knowledge inside `Meta/CurrentContext`. `Admin/SettingsPage` renders each row's variables as `button.button-small` pills and validates submitted templates against the registry on save, rejecting only the offending row. One small admin script reads the rendered pills for both click-to-insert and jQuery UI autocomplete, so the variable list is never serialised twice.

**Tech Stack:** PHP 8.3, WordPress 6.9, core's bundled `jquery-ui-autocomplete`, PHPUnit 11 + Brain Monkey + Mockery, Playwright.

**Spec:** `docs/superpowers/specs/2026-07-26-template-variables-design.md`

## Global Constraints

- **UI uses established WordPress components only.** No bespoke CSS, no invented widgets, no stylesheet of any kind. Pills are `button.button-small`; help text is `p.description`; errors go through `add_settings_error()` / the `settings_errors` transient / `settings_errors()`; invalid fields use core's `.form-invalid`. If a piece of UI seems to need something core does not provide, STOP and report rather than inventing it.
- **Namespace root** `TheAnother\Plugin\SEO\` → `includes/`. Tests: `TheAnother\Plugin\SEO\Tests\` → `tests/Unit/`.
- **PHP 8.3.** Tabs. `array()` long syntax (WPCS). Yoda conditions. No closing `?>`. File docblock with `@package TheAnotherSEO` and `@since 1.0.0`. Every method has `@param`/`@return`.
- **Translated strings** use the `the-another-seo` text domain. Translated labels cannot live in `const` arrays — they must be built in a method.
- **One regex for variable tokens.** `/%%([a-z0-9_]+)%%/i` lives in `TemplateResolver` and nowhere else. Anything needing to find tokens calls into it.
- **The variable list is serialised once.** It reaches JavaScript through the rendered pills' `data-taseo-template-var` attributes. Do NOT add `wp_localize_script` or an inline JSON copy — a second serialisation is the drift this plan exists to remove.
- **No version bump.**
- **Run tests:** `make test` (Docker, ~30s). Lint: `make lint`. E2E: `make test-e2e` — **FOREGROUND only**, Bash `timeout` `900000` ms, `docker ps` checked first and any running container allowed to exit. Backgrounding it has stalled agents repeatedly on this branch.
- **Baselines:** `make test` → `OK (275 tests, 832 assertions)`. `make test-e2e` → 26 passed.
- Conventional Commits, imperative mood.
- Branch `feature/webmaster-verification` (PR #2 open).

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `includes/Meta/TemplateVariables.php` | The registry: which variables exist for a given context, filterable. |
| `assets/js/settings.js` | Pill click-to-insert and `%%` autocomplete, both sourced from the rendered pills. |
| `tests/Unit/Meta/TemplateVariablesTest.php` | Registry behaviour. |
| `tests/Unit/Meta/CurrentContextVariablesTest.php` | The drift guard binding `CurrentContext` to the registry. |

**Modified:**

| Path | Change |
|---|---|
| `includes/Meta/TemplateResolver.php` | Gains `extract_variables()`, so the token regex exists once. |
| `includes/Admin/SettingsPage.php` | Takes the registry; renders pills; enqueues the script; validates on save; marks invalid fields. |
| `includes/Plugin.php` | Registers `template_variables` and injects it into `settings_page`. |
| `tests/Unit/Meta/TemplateResolverTest.php` | Covers `extract_variables()`. |
| `tests/Unit/Admin/SettingsPageTest.php` | Constructor gains an argument; pills, validation and marking coverage. |
| `tests/e2e/functional/specs/webmaster-admin.spec.ts` | Validation and autocomplete assertions. |

---

### Task 1: The token extractor

**Files:**
- Modify: `includes/Meta/TemplateResolver.php`
- Test: `tests/Unit/Meta/TemplateResolverTest.php`

**Interfaces:**
- Produces: `TemplateResolver::extract_variables( string $template ): array<int, string>` — **static**, returns lowercased variable slugs in order of appearance, duplicates removed.

**Why first:** it makes the token pattern exist in exactly one place before two other tasks need to find tokens.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Meta/TemplateResolverTest.php`, before the closing brace:

```php
	public function test_extract_variables_finds_tokens_in_order(): void {
		$this->assertSame(
			array( 'title', 'sep', 'sitename' ),
			TemplateResolver::extract_variables( '%%title%% %%sep%% %%sitename%%' )
		);
	}

	public function test_extract_variables_lowercases_and_deduplicates(): void {
		$this->assertSame(
			array( 'title' ),
			TemplateResolver::extract_variables( '%%TITLE%% - %%title%%' )
		);
	}

	public function test_extract_variables_returns_empty_for_a_template_without_tokens(): void {
		$this->assertSame( array(), TemplateResolver::extract_variables( 'Just static text' ) );
	}

	public function test_extract_variables_ignores_unclosed_tokens(): void {
		$this->assertSame( array(), TemplateResolver::extract_variables( '%%not closed' ) );
	}

	public function test_extract_variables_ignores_tokens_with_disallowed_characters(): void {
		$this->assertSame( array(), TemplateResolver::extract_variables( '%%bad-slug%%' ) );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `Call to undefined method …TemplateResolver::extract_variables()`

- [ ] **Step 3: Implement**

In `includes/Meta/TemplateResolver.php`, add a class constant above `resolve()` and switch `resolve()` to use it, then add the new method:

```php
	/**
	 * The one pattern that defines what a template variable looks like.
	 * Everything that finds tokens uses this, so the definition cannot drift.
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '/%%([a-z0-9_]+)%%/i';
```

Change `resolve()`'s `preg_replace_callback` first argument from the inline `'/%%([a-z0-9_]+)%%/i'` to `self::TOKEN_PATTERN`. Then append:

```php
	/**
	 * Extract the variable slugs a template references.
	 *
	 * Uses the same pattern resolve() expands with, so validation can never
	 * disagree with what actually gets substituted at render time.
	 *
	 * @param string $template Template with %%tokens%%.
	 * @return array<int, string> Lowercased slugs, in order, without duplicates.
	 */
	public static function extract_variables( string $template ): array {
		preg_match_all( self::TOKEN_PATTERN, $template, $matches );

		return array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS, higher count than 275.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add includes/Meta/TemplateResolver.php tests/Unit/Meta/TemplateResolverTest.php
git commit -m "feat: extract template variable slugs with the resolver's own pattern"
```

---

### Task 2: The registry

**Files:**
- Create: `includes/Meta/TemplateVariables.php`
- Create: `tests/Unit/Meta/TemplateVariablesTest.php`

**Interfaces:**
- Produces:
  - `TemplateVariables::get_for( string $object_type, string $object_subtype ): array<string, string>` — slug ⇒ translated label.
  - `TemplateVariables::is_available( string $variable, string $object_type, string $object_subtype ): bool`
  - Filter `taseo_template_variables` — `( array $variables, string $object_type, string $object_subtype )`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/TemplateVariablesTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;

#[CoversClass( TemplateVariables::class )]
class TemplateVariablesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private TemplateVariables $variables;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->variables = new TemplateVariables();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_every_context_gets_the_base_variables(): void {
		foreach ( array( array( 'post', 'page' ), array( 'term', 'category' ), array( 'system_page', 'home' ) ) as $context ) {
			$slugs = array_keys( $this->variables->get_for( $context[0], $context[1] ) );

			foreach ( array( 'title', 'sitename', 'tagline', 'sep', 'page' ) as $base ) {
				$this->assertContains( $base, $slugs, "$base missing for {$context[0]}:{$context[1]}" );
			}
		}
	}

	public function test_posts_add_excerpt_date_and_primary_category(): void {
		$slugs = array_keys( $this->variables->get_for( 'post', 'page' ) );

		$this->assertContains( 'excerpt', $slugs );
		$this->assertContains( 'date', $slugs );
		$this->assertContains( 'primary_category', $slugs );
	}

	public function test_terms_add_excerpt_but_not_date(): void {
		$slugs = array_keys( $this->variables->get_for( 'term', 'category' ) );

		$this->assertContains( 'excerpt', $slugs );
		$this->assertNotContains( 'date', $slugs );
		$this->assertNotContains( 'primary_category', $slugs );
	}

	public function test_system_pages_get_only_the_base_set(): void {
		$this->assertSame(
			array( 'title', 'sitename', 'tagline', 'sep', 'page' ),
			array_keys( $this->variables->get_for( 'system_page', '404' ) )
		);
	}

	public function test_products_omit_price_and_sku_without_woocommerce(): void {
		$slugs = array_keys( $this->variables->get_for( 'post', 'product' ) );

		$this->assertNotContains( 'price', $slugs );
		$this->assertNotContains( 'sku', $slugs );
	}

	public function test_products_add_price_and_sku_with_woocommerce(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		$slugs = array_keys( $this->variables->get_for( 'post', 'product' ) );

		$this->assertContains( 'price', $slugs );
		$this->assertContains( 'sku', $slugs );
	}

	public function test_non_product_post_types_never_get_price_even_with_woocommerce(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		$this->assertNotContains( 'price', array_keys( $this->variables->get_for( 'post', 'page' ) ) );
	}

	public function test_filter_receives_the_context_and_can_add_a_variable(): void {
		Filters\expectApplied( 'taseo_template_variables' )
			->once()
			->andReturnUsing(
				static function ( array $variables, string $type, string $subtype ): array {
					if ( 'post' === $type && 'product' === $subtype ) {
						$variables['brand'] = 'Brand';
					}

					return $variables;
				}
			);

		$this->assertArrayHasKey( 'brand', $this->variables->get_for( 'post', 'product' ) );
	}

	public function test_filter_can_remove_a_variable(): void {
		Filters\expectApplied( 'taseo_template_variables' )->once()->andReturnUsing(
			static function ( array $variables ): array {
				unset( $variables['tagline'] );

				return $variables;
			}
		);

		$this->assertArrayNotHasKey( 'tagline', $this->variables->get_for( 'post', 'page' ) );
	}

	public function test_a_non_array_filter_return_is_ignored(): void {
		Filters\expectApplied( 'taseo_template_variables' )->once()->andReturn( 'nonsense' );

		$this->assertArrayHasKey( 'title', $this->variables->get_for( 'post', 'page' ) );
	}

	public function test_filter_entries_with_disallowed_slugs_are_dropped(): void {
		Filters\expectApplied( 'taseo_template_variables' )->once()->andReturnUsing(
			static function ( array $variables ): array {
				$variables['bad-slug']  = 'Dash';
				$variables['Bad Slug']  = 'Space';
				$variables['ok_slug']   = 'Fine';

				return $variables;
			}
		);

		$slugs = array_keys( $this->variables->get_for( 'post', 'page' ) );

		$this->assertNotContains( 'bad-slug', $slugs );
		$this->assertNotContains( 'Bad Slug', $slugs );
		$this->assertContains( 'ok_slug', $slugs );
	}

	public function test_is_available_matches_the_registry_case_insensitively(): void {
		$this->assertTrue( $this->variables->is_available( 'TITLE', 'post', 'page' ) );
		$this->assertFalse( $this->variables->is_available( 'discount', 'post', 'page' ) );
		$this->assertFalse( $this->variables->is_available( 'price', 'post', 'page' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `Class "TheAnother\Plugin\SEO\Meta\TemplateVariables" not found`

- [ ] **Step 3: Implement**

Create `includes/Meta/TemplateVariables.php`:

```php
<?php
/**
 * Template Variables Registry
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Class TemplateVariables
 *
 * The single source of truth for which %%variables%% exist in which
 * context. Both the settings UI (pills, autocomplete) and save-time
 * validation read from here, so what the admin is offered, what the admin
 * may save, and what CurrentContext can actually resolve stay in agreement.
 *
 * The availability rules below are a transcription of what
 * CurrentContext::site_vars(), post_vars() and term_vars() produce —
 * tests/Unit/Meta/CurrentContextVariablesTest.php enforces that they stay
 * transcriptions rather than drifting apart again.
 */
class TemplateVariables {

	/**
	 * Variables available in every context.
	 *
	 * A method rather than a constant: the labels are translated, and
	 * __() cannot be called in a constant expression.
	 *
	 * @return array<string, string> Slug => label.
	 */
	private function base_variables(): array {
		return array(
			'title'    => __( 'Title of the post, term, or site', 'the-another-seo' ),
			'sitename' => __( 'Site title', 'the-another-seo' ),
			'tagline'  => __( 'Site tagline', 'the-another-seo' ),
			'sep'      => __( 'Title separator', 'the-another-seo' ),
			'page'     => __( 'Page number on paginated views', 'the-another-seo' ),
		);
	}

	/**
	 * Variables available for a given context.
	 *
	 * @param string $object_type    'post', 'term', or 'system_page'.
	 * @param string $object_subtype Post type, taxonomy, or system page key.
	 * @return array<string, string> Slug => label.
	 */
	public function get_for( string $object_type, string $object_subtype ): array {
		$variables = $this->base_variables();

		if ( 'post' === $object_type ) {
			$variables['excerpt']          = __( 'Excerpt', 'the-another-seo' );
			$variables['date']             = __( 'Publish date', 'the-another-seo' );
			$variables['primary_category'] = __( 'First assigned category', 'the-another-seo' );

			// Matches CurrentContext::post_vars()'s own WooCommerce probe: a
			// site without WooCommerce must not be offered variables that
			// could never resolve.
			if ( 'product' === $object_subtype && function_exists( 'wc_get_product' ) ) {
				$variables['price'] = __( 'Product price', 'the-another-seo' );
				$variables['sku']   = __( 'Product SKU', 'the-another-seo' );
			}
		} elseif ( 'term' === $object_type ) {
			$variables['excerpt'] = __( 'Term description', 'the-another-seo' );
		}

		/**
		 * Filters the template variables available in one context.
		 *
		 * The type and subtype are passed so an extension can scope a
		 * variable to products rather than advertising it on 404 pages.
		 * Entries whose slug does not match the resolver's own character
		 * class are dropped — the registry must never offer a token
		 * TemplateResolver could not expand.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $variables      Slug => label.
		 * @param string                $object_type    'post'|'term'|'system_page'.
		 * @param string                $object_subtype Post type, taxonomy, or system page key.
		 */
		$filtered = apply_filters( 'taseo_template_variables', $variables, $object_type, $object_subtype );

		return is_array( $filtered ) ? $this->clean( $filtered ) : $variables;
	}

	/**
	 * Whether one variable is available in a context.
	 *
	 * @param string $variable       Slug, any case.
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return bool Available.
	 */
	public function is_available( string $variable, string $object_type, string $object_subtype ): bool {
		return array_key_exists(
			strtolower( $variable ),
			$this->get_for( $object_type, $object_subtype )
		);
	}

	/**
	 * Drop entries a filter added that the resolver could never expand.
	 *
	 * @param array<mixed, mixed> $variables Candidate variables.
	 * @return array<string, string> Clean variables.
	 */
	private function clean( array $variables ): array {
		$clean = array();

		foreach ( $variables as $slug => $label ) {
			if ( is_string( $slug ) && is_string( $label ) && 1 === preg_match( '/^[a-z0-9_]+$/', $slug ) ) {
				$clean[ $slug ] = $label;
			}
		}

		return $clean;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add includes/Meta/TemplateVariables.php tests/Unit/Meta/TemplateVariablesTest.php
git commit -m "feat: add the context-aware template variables registry"
```

---

### Task 3: The drift guard

**Files:**
- Create: `tests/Unit/Meta/CurrentContextVariablesTest.php`

**Interfaces:**
- Consumes: `TemplateVariables::get_for()` (Task 2); `CurrentContext::resolve()`, which returns an array whose `vars` key holds the variable values for the current request.

**Why this exists:** the registry and `CurrentContext` describe one thing in two places, which is exactly how the current bug arose — the tab advertises `%%price%%` everywhere while only products resolve it. This test fails if they ever diverge again. There is no existing test for `CurrentContext`, so this file builds its mocking from scratch.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/CurrentContextVariablesTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;
use WP_Term;

/**
 * The registry and CurrentContext describe one thing in two places. This
 * pins them together: every variable CurrentContext can produce must be
 * advertised by the registry, and every variable the registry advertises
 * must be reachable. Before the registry existed the settings screen
 * offered %%price%% on every row while only WooCommerce products resolved
 * it — this is the test that would have caught that.
 */
#[CoversClass( CurrentContext::class )]
class CurrentContextVariablesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private TemplateVariables $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Auctions' );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/x/' );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/t/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->repository->shouldReceive( 'find' )->andReturn( null )->byDefault();
		$this->repository->shouldReceive( 'get' )->andReturn( null )->byDefault();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_separator' )->andReturn( '–' )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'page', 'product' ) )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category' ) )->byDefault();
		$this->settings->shouldReceive( 'get_title_template' )->andReturn( '%%title%%' )->byDefault();
		$this->settings->shouldReceive( 'get_description_template' )->andReturn( '%%excerpt%%' )->byDefault();

		$this->registry = new TemplateVariables();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a WP_Post stub. WP_Post is defined by php-stubs/wordpress-stubs.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Post Post.
	 */
	private function post( string $post_type ): WP_Post {
		$post            = Mockery::mock( WP_Post::class );
		$post->ID        = 7;
		$post->post_type = $post_type;

		return $post;
	}

	/**
	 * Resolve a singular request for one post type and return its vars.
	 *
	 * @param string $post_type Post type.
	 * @param bool   $with_woo  Register a wc_get_product() returning a product.
	 * @return array<string, string> Variables produced.
	 */
	private function post_vars( string $post_type, bool $with_woo = false ): array {
		$post = $this->post( $post_type );

		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $post );
		Functions\when( 'get_the_title' )->justReturn( 'A Title' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'An excerpt.' );
		Functions\when( 'get_the_date' )->justReturn( '1 January 2026' );

		// primary_category only appears when the object actually has terms —
		// give it one, so "producible" means reachable rather than merely
		// mentioned in the registry.
		$term       = Mockery::mock( WP_Term::class );
		$term->name = 'Watches';
		Functions\when( 'get_the_terms' )->justReturn( array( $term ) );

		if ( $with_woo ) {
			$product = Mockery::mock();
			$product->shouldReceive( 'get_price' )->andReturn( '99.00' );
			$product->shouldReceive( 'get_sku' )->andReturn( 'SKU-1' );
			Functions\when( 'wc_get_product' )->justReturn( $product );
		}

		$context = ( new CurrentContext( $this->repository, $this->settings ) )->resolve();

		return $context['vars'];
	}

	public function test_post_variables_are_all_advertised_by_the_registry(): void {
		$produced   = array_keys( $this->post_vars( 'page' ) );
		$advertised = array_keys( $this->registry->get_for( 'post', 'page' ) );

		$this->assertSame( array(), array_diff( $produced, $advertised ), 'CurrentContext produces variables the registry does not advertise' );
		$this->assertSame( array(), array_diff( $advertised, $produced ), 'The registry advertises variables CurrentContext cannot produce' );
	}

	public function test_product_variables_match_the_registry_when_woocommerce_is_active(): void {
		$produced   = array_keys( $this->post_vars( 'product', true ) );
		$advertised = array_keys( $this->registry->get_for( 'post', 'product' ) );

		$this->assertSame( array(), array_diff( $produced, $advertised ) );
		$this->assertSame( array(), array_diff( $advertised, $produced ) );
		$this->assertContains( 'price', $produced );
		$this->assertContains( 'sku', $produced );
	}

	public function test_term_variables_match_the_registry(): void {
		$term              = Mockery::mock( WP_Term::class );
		$term->term_id     = 3;
		$term->taxonomy    = 'category';
		$term->name        = 'Watches';
		$term->description = 'About watches.';

		Functions\when( 'is_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$context    = ( new CurrentContext( $this->repository, $this->settings ) )->resolve();
		$produced   = array_keys( $context['vars'] );
		$advertised = array_keys( $this->registry->get_for( 'term', 'category' ) );

		$this->assertSame( array(), array_diff( $produced, $advertised ) );
		$this->assertSame( array(), array_diff( $advertised, $produced ) );
	}

	public function test_system_page_variables_match_the_registry(): void {
		Functions\when( 'is_404' )->justReturn( true );

		$context    = ( new CurrentContext( $this->repository, $this->settings ) )->resolve();
		$produced   = array_keys( $context['vars'] );
		$advertised = array_keys( $this->registry->get_for( 'system_page', '404' ) );

		$this->assertSame( array(), array_diff( $produced, $advertised ) );
		$this->assertSame( array(), array_diff( $advertised, $produced ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails or passes for the right reason**

Run: `make test`

Expected: the four assertions must run and pass. **If a test errors instead** — a missing mocked function, a `WP_Term`/`WP_Post` stub problem, or `resolve()` returning `null` because a guard was not satisfied — that is a mocking problem in the test, not a product bug. Read `includes/Meta/CurrentContext.php`'s `do_resolve()` and add whatever the branch you are exercising requires. Do NOT change production code to make this test pass; if you believe production code is genuinely wrong, STOP and report it.

**If an assertion fails**, the registry and `CurrentContext` genuinely disagree. Report which variable and in which direction before changing anything — that is a real finding, and which side is wrong is a judgement call, not a mechanical fix.

- [ ] **Step 3: Commit**

```bash
make lint
git add tests/Unit/Meta/CurrentContextVariablesTest.php
git commit -m "test: pin the variables registry to what CurrentContext produces"
```

---

### Task 4: Pills, and wiring the registry into the settings page

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Modify: `includes/Plugin.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `TemplateVariables::get_for()` (Task 2).
- Produces: pills markup carrying `data-taseo-template-var="%%slug%%"`, and inputs carrying `data-taseo-template-input`, both of which Task 5's script depends on.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Admin/SettingsPageTest.php`. Read its `setUp()` first — you must add the new constructor argument there before these compile.

```php
	public function test_templates_tab_renders_a_pill_for_each_available_variable(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		$this->assertStringContainsString( 'data-taseo-template-var="%%title%%"', $html );
		$this->assertStringContainsString( 'class="button button-small"', $html );
	}

	public function test_templates_tab_no_longer_prints_the_hardcoded_variable_line(): void {
		$_GET['tab'] = 'templates';

		$this->assertStringNotContainsString( 'Available variables:', $this->render_page() );
	}

	public function test_system_page_rows_offer_only_the_base_variables(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		// The 404 row is a system page: no excerpt, date, or primary_category.
		$this->assertStringContainsString( 'taseo_settings[title_templates][system_page:404]', $html );
		$this->assertStringNotContainsString( 'data-taseo-template-var="%%price%%"', $html );
	}
```

Add this helper alongside them, and whatever `Functions\when()` stubs the render path needs (`esc_html`, `esc_attr`, `esc_html__`, `esc_attr__`, `esc_url`, `admin_url`, `submit_button`, `wp_nonce_field`, `get_post_types`, `get_taxonomies`, `checked`, and `get_settings_errors` returning `array()`):

```php
	/**
	 * Render the settings page and return its markup.
	 *
	 * @return string Markup.
	 */
	private function render_page(): string {
		ob_start();
		$this->page->render_page();

		return (string) ob_get_clean();
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — the pill attribute is absent and the hardcoded line is present.

- [ ] **Step 3: Inject the registry**

In `includes/Admin/SettingsPage.php`, add the import and a sixth promoted constructor property:

```php
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
```

```php
		private readonly TemplateVariables $template_variables
```

In `includes/Plugin.php`, add the import, register the service, and pass it:

```php
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
```

```php
		$c->register( 'template_variables', fn() => new TemplateVariables() );
```

Add `$c->get( 'template_variables' )` as the last argument of the existing `settings_page` registration. If `tests/Unit/PluginTest.php` asserts on the registered-service list, extend that assertion — do not weaken it.

- [ ] **Step 4: Render the pills**

In `render_templates_tab()`, delete the hardcoded `'Available variables: …'` line entirely, and add this helper to the class:

```php
	/**
	 * Render the variable pills for one template row.
	 *
	 * Core's own button component inside core's help-text element — no
	 * stylesheet is involved. The data attribute is also the only channel
	 * by which the admin script learns this row's variables: it reads the
	 * rendered pills rather than a second, separately-serialised copy of
	 * the registry, so the two cannot drift.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return void
	 */
	private function render_variable_pills( string $object_type, string $object_subtype ): void {
		echo '<p class="description">';

		foreach ( array_keys( $this->template_variables->get_for( $object_type, $object_subtype ) ) as $slug ) {
			$token = '%%' . $slug . '%%';

			printf(
				'<button type="button" class="button button-small" data-taseo-template-var="%1$s">%1$s</button> ',
				esc_attr( $token )
			);
		}

		echo '</p>';
	}
```

Then, in each of the three loops in `render_templates_tab()`, add `data-taseo-template-input` to every template `<input>` and call the helper inside the same `<td>`, after the inputs — `$this->render_variable_pills( 'post', $type )`, `$this->render_variable_pills( 'term', $tax )`, and `$this->render_variable_pills( 'system_page', $system )` respectively.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `make test`
Expected: PASS.

- [ ] **Step 6: Lint and commit**

```bash
make lint
git add includes/Admin/SettingsPage.php includes/Plugin.php tests/Unit/Admin/SettingsPageTest.php tests/Unit/PluginTest.php
git commit -m "feat: render per-row template variable pills from the registry"
```

---

### Task 5: The admin script — click-to-insert and autocomplete

**Files:**
- Create: `assets/js/settings.js`
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: the pills and `data-taseo-template-input` attributes from Task 4.
- Produces: the enqueued handle `taseo-settings`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	public function test_assets_enqueue_only_on_this_settings_page(): void {
		$enqueued = array();

		Functions\when( 'add_options_page' )->justReturn( 'settings_page_taseo' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '', array $deps = array() ) use ( &$enqueued ): void {
				$enqueued[ $handle ] = $deps;
			}
		);

		$this->page->register_menu();

		$this->page->enqueue_assets( 'edit.php' );
		$this->assertSame( array(), $enqueued, 'must not enqueue on unrelated admin screens' );

		$this->page->enqueue_assets( 'settings_page_taseo' );
		$this->assertArrayHasKey( 'taseo-settings', $enqueued );
		$this->assertContains( 'jquery-ui-autocomplete', $enqueued['taseo-settings'] );
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test`
Expected: FAIL — `Call to undefined method …SettingsPage::enqueue_assets()`

- [ ] **Step 3: Enqueue the script**

In `includes/Admin/SettingsPage.php`, add a property, capture the hook suffix, register the hook, and add the callback:

```php
	/**
	 * Hook suffix of this settings page, for gating asset enqueue.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';
```

In `init()`, add:

```php
		$hook_manager->register_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
```

In `register_menu()`, capture the return value:

```php
		$this->hook_suffix = (string) add_options_page(
```

Add the callback:

```php
	/**
	 * Enqueue this page's script, and only on this page.
	 *
	 * Depends on core's bundled jquery-ui-autocomplete — the same component
	 * wp-admin/js/user-suggest.js and the link modal use — so nothing
	 * reaches the page that WordPress does not already ship, and core's
	 * existing .ui-autocomplete styles apply without a stylesheet of ours.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'taseo-settings',
			THE_ANOTHER_SEO_PLUGIN_URL . 'assets/js/settings.js',
			array( 'jquery-ui-autocomplete' ),
			THE_ANOTHER_SEO_VERSION,
			true
		);
	}
```

- [ ] **Step 4: Write the script**

Create `assets/js/settings.js`:

```js
/**
 * Titles & Templates helpers: click a variable pill to insert its token, or
 * type %% for an autocomplete of that row's variables.
 *
 * The variable list is never shipped to this file as data. It is read from
 * the pills the server rendered (their data-taseo-template-var attributes),
 * so there is exactly one serialisation of the registry on the page and the
 * suggestions can never disagree with the pills sitting next to them.
 *
 * Completing a fragment inside a larger value follows core's own
 * wp-admin/js/user-suggest.js, which completes the last entry of a
 * comma-separated field; here the delimiter is %% instead of a comma.
 */
( function ( $ ) {
	'use strict';

	var TOKEN = '%%';

	/**
	 * The template inputs belonging to the same row as an element.
	 *
	 * @param {Element} element Element inside a row.
	 * @return {Array} Inputs.
	 */
	function rowInputs( element ) {
		var row = element.closest( 'tr' );

		return row
			? Array.prototype.slice.call(
					row.querySelectorAll( '[data-taseo-template-input]' )
			  )
			: [];
	}

	/**
	 * The tokens this row's pills offer.
	 *
	 * @param {Element} element Element inside a row.
	 * @return {Array} Tokens, e.g. ['%%title%%'].
	 */
	function rowTokens( element ) {
		var row = element.closest( 'tr' );

		return row
			? Array.prototype.map.call(
					row.querySelectorAll( '[data-taseo-template-var]' ),
					function ( pill ) {
						return pill.getAttribute( 'data-taseo-template-var' );
					}
			  )
			: [];
	}

	/**
	 * Which input a pill click should target: the last one in this row to
	 * have been focused, falling back to the row's first input.
	 *
	 * @param {Element} element Element inside a row.
	 * @return {Element|null} Input.
	 */
	function targetInput( element ) {
		var inputs = rowInputs( element );
		var i;

		for ( i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].dataset.taseoLastFocused === '1' ) {
				return inputs[ i ];
			}
		}

		return inputs.length ? inputs[ 0 ] : null;
	}

	/**
	 * Replace the range [start, end) of an input's value and place the
	 * caret after the inserted text.
	 *
	 * @param {Element} input Input.
	 * @param {number}  start Start offset.
	 * @param {number}  end   End offset.
	 * @param {string}  text  Replacement.
	 * @return {void}
	 */
	function replaceRange( input, start, end, text ) {
		input.value =
			input.value.slice( 0, start ) + text + input.value.slice( end );

		var caret = start + text.length;

		input.focus();
		input.setSelectionRange( caret, caret );
	}

	/**
	 * The open, incomplete token immediately before the caret, if any.
	 *
	 * An even number of %% delimiters before the caret means we sit outside
	 * a token and there is nothing to complete; an odd number means the
	 * last one opened a token still being typed.
	 *
	 * @param {Element} input Input.
	 * @return {Object|null} { start, term } or null.
	 */
	function openToken( input ) {
		var before = input.value.slice( 0, input.selectionStart );
		var count = before.split( TOKEN ).length - 1;

		if ( count % 2 === 0 ) {
			return null;
		}

		var start = before.lastIndexOf( TOKEN );
		var term = before.slice( start + TOKEN.length );

		if ( ! /^[a-z0-9_]*$/i.test( term ) ) {
			return null;
		}

		return { start: start, term: term.toLowerCase() };
	}

	document.addEventListener( 'focusin', function ( event ) {
		var input = event.target.closest( '[data-taseo-template-input]' );

		if ( ! input ) {
			return;
		}

		rowInputs( input ).forEach( function ( other ) {
			delete other.dataset.taseoLastFocused;
		} );

		input.dataset.taseoLastFocused = '1';
	} );

	document.addEventListener( 'click', function ( event ) {
		var pill = event.target.closest( '[data-taseo-template-var]' );

		if ( ! pill ) {
			return;
		}

		var input = targetInput( pill );

		if ( ! input ) {
			return;
		}

		replaceRange(
			input,
			input.selectionStart,
			input.selectionEnd,
			pill.getAttribute( 'data-taseo-template-var' )
		);
	} );

	$( function () {
		$( '[data-taseo-template-input]' ).autocomplete( {
			minLength: 0,
			source: function ( request, response ) {
				var input = this.element[ 0 ];
				var open = openToken( input );

				if ( ! open ) {
					response( [] );
					return;
				}

				response(
					rowTokens( input ).filter( function ( token ) {
						return (
							token
								.slice( TOKEN.length )
								.toLowerCase()
								.indexOf( open.term ) === 0
						);
					} )
				);
			},
			focus: function () {
				// Keep the typed fragment in the field while arrowing the list.
				return false;
			},
			select: function ( event, ui ) {
				var input = this.element[ 0 ];
				var open = openToken( input );

				if ( open ) {
					replaceRange(
						input,
						open.start,
						input.selectionStart,
						ui.item.value
					);
				}

				return false;
			},
		} );
	} );
} )( jQuery );
```

- [ ] **Step 5: Confirm the asset ships in the release zip**

Run: `grep -n "assets" .distignore`

Expected: no line excluding `assets/`. If one exists, the script would be missing from the packaged plugin — remove that exclusion and note it in your report.

- [ ] **Step 6: Run the tests, lint, and commit**

```bash
make test
make lint
git add assets/js/settings.js includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: add pill insertion and %% autocomplete to the templates tab"
```

---

### Task 6: Save-time validation

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `TemplateResolver::extract_variables()` (Task 1), `TemplateVariables::is_available()` (Task 2).

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	public function test_a_template_using_available_variables_saves(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => '%%title%% %%sep%% %%sitename%%' ) ),
			'templates'
		);

		$this->assertSame( '%%title%% %%sep%% %%sitename%%', $clean['title_templates']['post:page'] );
	}

	public function test_an_unknown_variable_rejects_only_its_own_row(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn(
			array( 'post:product' => '%%title%%' )
		);
		Functions\expect( 'add_settings_error' )->once();

		$clean = $this->page->sanitize_settings(
			array(
				'title_templates' => array(
					'post:product' => '%%title%% %%discount%%',
					'post:page'    => '%%title%% %%sep%%',
				),
			),
			'templates'
		);

		$this->assertSame( '%%title%%', $clean['title_templates']['post:product'], 'rejected row keeps its stored value' );
		$this->assertSame( '%%title%% %%sep%%', $clean['title_templates']['post:page'], 'sibling row still saves' );
	}

	public function test_a_variable_from_the_wrong_context_is_rejected(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );
		Functions\expect( 'add_settings_error' )->once();

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => '%%title%% %%price%%' ) ),
			'templates'
		);

		$this->assertArrayNotHasKey( 'post:page', $clean['title_templates'] );
	}

	public function test_a_template_without_variables_is_valid(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => 'Just a static title' ) ),
			'templates'
		);

		$this->assertSame( 'Just a static title', $clean['title_templates']['post:page'] );
	}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `make test`
Expected: FAIL — the current sanitizer stores every template unchanged, so the rejection assertions fail.

- [ ] **Step 3: Implement**

In `includes/Admin/SettingsPage.php`, add the import:

```php
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
```

Replace the existing template loop in `sanitize_settings()` —

```php
		foreach ( array( 'title_templates', 'description_templates' ) as $tpl_key ) {
			if ( isset( $raw[ $tpl_key ] ) && is_array( $raw[ $tpl_key ] ) ) {
				$clean[ $tpl_key ] = array_map( 'sanitize_text_field', $raw[ $tpl_key ] );
			}
		}
```

— with:

```php
		foreach ( array( 'title_templates', 'description_templates' ) as $tpl_key ) {
			if ( ! isset( $raw[ $tpl_key ] ) || ! is_array( $raw[ $tpl_key ] ) ) {
				continue;
			}

			// Start from what is stored: a row whose template is rejected
			// keeps its previous value while its siblings save normally.
			// These keys hold every row, so replacing the array wholesale
			// would let one bad row discard unrelated edits.
			$stored = $this->settings->get( $tpl_key, array() );
			$rows   = is_array( $stored ) ? $stored : array();

			foreach ( $raw[ $tpl_key ] as $row_key => $template ) {
				$row_key  = (string) $row_key;
				$template = sanitize_text_field( (string) $template );
				$parts    = explode( ':', $row_key, 2 );
				$type     = $parts[0] ?? '';
				$subtype  = $parts[1] ?? '';
				$invalid  = array();

				foreach ( TemplateResolver::extract_variables( $template ) as $variable ) {
					if ( ! $this->template_variables->is_available( $variable, $type, $subtype ) ) {
						$invalid[] = '%%' . $variable . '%%';
					}
				}

				if ( array() !== $invalid ) {
					add_settings_error(
						'taseo_messages',
						self::INVALID_TEMPLATE_CODE . $tpl_key . '__' . $row_key,
						sprintf(
							/* translators: 1: row label such as post:product, 2: comma-separated variable tokens. */
							esc_html__( '%1$s: %2$s is not available for this content type. That field was not saved; the others were.', 'the-another-seo' ),
							esc_html( $row_key ),
							esc_html( implode( ', ', $invalid ) )
						),
						'error'
					);

					continue;
				}

				$rows[ $row_key ] = $template;
			}

			$clean[ $tpl_key ] = $rows;
		}
```

**Note the error code format — it is load-bearing.** The page that renders `.form-invalid` is a *different request* from the one that validated, because the save redirects. An instance property cannot survive that, so the failed rows must be recoverable from the errors themselves. `system_page` contains a single underscore and row keys contain colons, so a double underscore is used as the separator:

```php
	/**
	 * Settings-error code prefix. The settings key and row key are appended
	 * so render_page() can recover which fields failed after the redirect —
	 * validation and rendering happen in different requests, so nothing held
	 * in object state survives between them. Double underscore separates,
	 * because row keys contain colons and "system_page" contains a single
	 * underscore.
	 *
	 * @var string
	 */
	private const INVALID_TEMPLATE_CODE = 'taseo_invalid_template__';
```

- [ ] **Step 4: Carry the errors across the redirect**

In `handle_save()`, after `$this->settings->update( … )` and before building the redirect:

```php
		$errors = get_settings_errors();

		if ( array() !== $errors ) {
			// Exactly how core's options.php hands validation failures to
			// the page it redirects to.
			set_transient( 'settings_errors', $errors, 30 );
		}
```

The redirect URL must also gain `settings-updated=true` alongside its existing parameters, keeping the tab-preserving behaviour intact — `get_settings_errors()` only reads that transient back when the query argument is present:

```php
		$redirect = add_query_arg( 'settings-updated', 'true', $redirect );
```

- [ ] **Step 5: Render the notices and mark the invalid fields**

Add a property and a resolver to `SettingsPage`. Both notices and field marking come from a **single** read, because retrieving the errors clears the transient — reading twice would silently lose them:

```php
	/**
	 * Row keys rejected by the save that redirected here, as
	 * "<settings key>__<row key>". Populated once per render from the
	 * settings errors, since the validating request and this one are
	 * different requests.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $invalid_rows = null;

	/**
	 * Read the settings errors once, print them, and remember which rows
	 * they name so the matching inputs can be marked.
	 *
	 * @return void
	 */
	private function print_settings_errors(): void {
		$errors             = get_settings_errors( 'taseo_messages' );
		$this->invalid_rows = array();

		foreach ( $errors as $error ) {
			$code = (string) ( $error['code'] ?? '' );

			if ( str_starts_with( $code, self::INVALID_TEMPLATE_CODE ) ) {
				$this->invalid_rows[] = substr( $code, strlen( self::INVALID_TEMPLATE_CODE ) );
			}

			printf(
				'<div class="notice notice-%1$s settings-error is-dismissible"><p><strong>%2$s</strong></p></div>',
				esc_attr( (string) ( $error['type'] ?? 'error' ) ),
				esc_html( (string) ( $error['message'] ?? '' ) )
			);
		}
	}

	/**
	 * The CSS classes for a template input, adding core's .form-invalid
	 * when the last save rejected this row.
	 *
	 * @param string $settings_key 'title_templates' or 'description_templates'.
	 * @param string $row_key      Row key such as 'post:product'.
	 * @return string Class attribute value.
	 */
	private function template_input_class( string $settings_key, string $row_key ): string {
		$rows = $this->invalid_rows ?? array();

		return in_array( $settings_key . '__' . $row_key, $rows, true )
			? 'large-text form-invalid'
			: 'large-text';
	}
```

Call `$this->print_settings_errors();` in `render_page()` immediately after the `<h1>`, before the tab nav — so it runs before `render_templates_tab()` needs `$this->invalid_rows`.

Then in `render_templates_tab()`, replace each template input's hardcoded `class="large-text"` with the helper's output, passing that input's settings key and row key. For the post loop that means `esc_attr( $this->template_input_class( 'title_templates', 'post:' . $type ) )` for the title input and `'description_templates'` for the description one, with the equivalent in the term and system-page loops.

- [ ] **Step 6: Run the tests, lint, and commit**

```bash
make test
make lint
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: reject template rows using unavailable variables"
```

---

### Task 7: End-to-end coverage

**Files:**
- Modify: `tests/e2e/functional/specs/webmaster-admin.spec.ts`

- [ ] **Step 1: Add the specs**

Add to the existing `test.describe` block. Read the file first and follow its conventions — in particular its `saveWebmasterSettings()` helper and the `afterEach` restore, and note that its saves click `#submit` for real.

```typescript
	test( 'a template using an unavailable variable is rejected, siblings save', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/options-general.php?page=taseo&tab=templates'
		);

		const productTitle = page.locator(
			'input[name="taseo_settings[title_templates][post:post]"]'
		);
		const original = await productTitle.inputValue();

		await productTitle.fill( '%%title%% %%discount%%' );
		await page.locator( '#submit' ).click( { force: true } );

		// Both assertions run on the page the save redirected to. The error
		// lives in the settings_errors transient, which is consumed by the
		// first render — so .form-invalid exists here and is deliberately
		// gone after a reload, which the next assertion relies on.
		await expect( page.locator( '.notice-error' ) ).toContainText(
			'%%discount%%'
		);
		await expect( productTitle ).toHaveClass( /form-invalid/ );

		await page.reload();
		await expect( productTitle ).toHaveValue( original );
		await expect( productTitle ).not.toHaveClass( /form-invalid/ );
	} );

	test( 'clicking a variable pill inserts its token', async ( { page } ) => {
		await page.goto(
			'/wp-admin/options-general.php?page=taseo&tab=templates'
		);

		const input = page.locator(
			'input[name="taseo_settings[title_templates][post:post]"]'
		);
		await input.fill( '' );
		await input.focus();

		await page
			.locator(
				'tr:has(input[name="taseo_settings[title_templates][post:post]"]) [data-taseo-template-var="%%title%%"]'
			)
			.click( { force: true } );

		await expect( input ).toHaveValue( '%%title%%' );
	} );
```

- [ ] **Step 2: Run the e2e suite**

Run `make test-e2e` in the FOREGROUND, Bash `timeout` `900000` ms, `docker ps` checked first.

Expected: **28 passed** — 26 before, plus these two.

The e2e environment has no WooCommerce, so `%%price%%` is unavailable on every row there. That is why the rejection spec uses `%%discount%%`, which is unavailable everywhere regardless.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/functional/specs/webmaster-admin.spec.ts
git commit -m "test: cover template variable rejection and pill insertion end to end"
```

---

### Task 8: Full gate

- [ ] **Step 1: Run every gate**

```bash
make lint
make test
make test-e2e
make check-plugin
```

All four must pass. `make test-e2e` in the FOREGROUND with `timeout` `900000`.

- [ ] **Step 2: Confirm the tour still passes**

The admin tour (`specs/zz-admin-tour.spec.ts`) writes `%%title%% %%sep%% Tour` to the `post:post` title template. Both variables are available for posts, so validation should leave it green — the e2e run above proves it. If the tour fails, do not weaken validation to accommodate it; report the interaction.

- [ ] **Step 3: Push**

```bash
git push
gh pr checks 2 --watch --interval 30
```

Expected: all four CI jobs pass.

---

## Notes for the implementer

**Never serialise the variable list twice.** The pills are the only channel to JavaScript. If you find yourself reaching for `wp_localize_script`, stop — that second copy is precisely the drift this plan removes.

**`SettingsPage`'s constructor gains a sixth argument in Task 4.** `tests/Unit/Admin/SettingsPageTest.php` and `includes/Plugin.php` both construct it; update both. If `PluginTest` asserts on the service list, extend the assertion rather than deleting it.

**If the drift test in Task 3 fails on an assertion rather than erroring**, that is a genuine finding about the product, not a test bug. Report which variable and which direction before changing anything.

**Do not add a stylesheet.** If something looks unstyled, check whether core already styles it — `.ui-autocomplete`, `.button-small`, `.description`, and `.form-invalid` all have core styles in `wp-admin/css/common.css` and `forms.css`.
