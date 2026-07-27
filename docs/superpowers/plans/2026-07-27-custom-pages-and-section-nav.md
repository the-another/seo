# Custom Pages and Section Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let another plugin register a custom page that gets its own title and meta description templates on the Titles & Templates tab, and actually renders them; and give that tab an anchor nav across its four sections.

**Architecture:** A small `Meta\CustomPages` registry owns the `taseo_custom_pages` filter and its key sanitization, so the settings screen and `CurrentContext` cannot drift. `CurrentContext::do_resolve()` gains a `taseo_custom_page_context` filter at its top, letting a registering plugin claim a request that would otherwise resolve as something else. The tab renders a fourth section from the registry, with an empty state that documents both filters.

**Tech Stack:** PHP 8.2+, WordPress, PHPUnit 11 + Brain Monkey + Mockery, Playwright.

## Global Constraints

- **Established WordPress components only. No stylesheet, no CSS class of our own.** Core classes (`subsubsub`, `clear`, `form-table`, `button`, `large-text`, `description`) only.
- **WPCS:** tabs for indentation, `array()` long syntax, Yoda conditions, no closing `?>`, `@param`/`@return` on every docblock, text domain `the-another-seo`.
- **Custom page keys are constrained to `[a-z0-9_-]`, and an invalid key is SKIPPED, never rewritten.** Rewriting would leave the registering plugin's `taseo_custom_page_context` filter pointing at a key that no longer exists — a silent mismatch is worse than a visibly missing row.
- **`taseo_custom_page_context` runs FIRST**, before `is_singular()`, not at the fallthrough. See Task 2 for why; getting this wrong makes the feature useless for page-backed custom pages.
- **Only a registered subtype resolves.** The two filters are a matched pair.
- Do not bump any version number, and do not edit `CHANGELOG.md` except in Task 6.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `includes/Meta/CustomPages.php` | Owns `taseo_custom_pages`, key sanitization, and `has()`. Single source of truth for both consumers. |
| `tests/Unit/Meta/CustomPagesTest.php` | Registry unit tests. |
| `tests/Unit/Meta/CurrentContextCustomPageTest.php` | Resolution-filter unit tests, kept out of the existing `CurrentContextVariablesTest.php`, which pins a different invariant. |

**Modified:**

| File | Change |
|---|---|
| `includes/Meta/CurrentContext.php` | Constructor gains `CustomPages`; `do_resolve()` gains the filter at its top. |
| `includes/Admin/SettingsPage.php` | Constructor gains `CustomPages`; Custom pages section, empty state, `template_row_label()` branch, section nav and heading ids. |
| `includes/Plugin.php:123,157-164` | Both factories gain the new dependency. |
| `tests/Unit/Meta/CurrentContextVariablesTest.php:141,211,222` | Three `new CurrentContext( … )` call sites gain the third argument. |
| `tests/Unit/Admin/SettingsPageTest.php:68` | The one `new SettingsPage( … )` gains a seventh argument. |
| `tests/e2e/functional/specs/` | New spec plus an mu-plugin fixture. |

---

## Task 1: The `CustomPages` registry

**Files:**
- Create: `includes/Meta/CustomPages.php`
- Create: `tests/Unit/Meta/CustomPagesTest.php`
- Modify: `includes/Plugin.php` (register the service)

**Interfaces:**
- Produces: `CustomPages::all(): array<string,string>` (sanitized key => label) and `CustomPages::has( string $key ): bool`. Container key: `custom_pages`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/CustomPagesTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\CustomPages;

#[CoversClass( CustomPages::class )]
class CustomPagesTest extends TestCase {

	private CustomPages $pages;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pages = new CustomPages();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_returns_the_registered_pages(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn(
			array(
				'checkout' => 'Checkout',
				'account'  => 'My account',
			)
		);

		$this->assertSame(
			array(
				'checkout' => 'Checkout',
				'account'  => 'My account',
			),
			$this->pages->all()
		);
	}

	public function test_all_is_empty_when_nothing_is_registered(): void {
		$this->assertSame( array(), $this->pages->all() );
	}

	/**
	 * A key becomes a settings array key and part of an HTML id, so it is
	 * restricted. It is SKIPPED rather than rewritten: a rewritten key would
	 * leave the registering plugin's taseo_custom_page_context filter naming
	 * a key that no longer exists, which fails silently instead of visibly.
	 */
	public function test_a_key_with_invalid_characters_is_skipped_not_rewritten(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn(
			array(
				'checkout'   => 'Checkout',
				'Check Out'  => 'Spaces and capitals',
				'has:colon'  => 'Colon collides with the row key separator',
				'has/slash'  => 'Slash',
			)
		);

		$all = $this->pages->all();

		$this->assertSame( array( 'checkout' => 'Checkout' ), $all );
		$this->assertArrayNotHasKey( 'check-out', $all );
		$this->assertArrayNotHasKey( 'checkout2', $all );
	}

	public function test_a_non_array_return_yields_no_pages(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn( 'nonsense' );

		$this->assertSame( array(), $this->pages->all() );
	}

	public function test_an_empty_label_falls_back_to_the_key(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn( array( 'checkout' => '' ) );

		$this->assertSame( array( 'checkout' => 'checkout' ), $this->pages->all() );
	}

	public function test_has_is_true_only_for_a_registered_key(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$this->assertTrue( $this->pages->has( 'checkout' ) );
		$this->assertFalse( $this->pages->has( 'account' ) );
	}
}
```

**Do not add `->once()` to these `expectApplied()` calls.** `has()` calls `all()`, so the filter is applied more than once in that test, and `expectApplied()`'s default zero-or-more cardinality is what makes that fine. These tests discriminate on the returned *value*, which differs per case, so they do not need a cardinality constraint to have teeth.

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL with `Class "TheAnother\Plugin\SEO\Meta\CustomPages" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Meta/CustomPages.php`:

```php
<?php
/**
 * Custom Pages Registry
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Class CustomPages
 *
 * Pages another plugin registers for templating — a checkout screen, an
 * account area, any virtual page this plugin has no way to know about.
 *
 * Both the settings screen and CurrentContext read the list through this
 * class rather than each calling apply_filters() themselves. Two call sites
 * would be two places for the key sanitization to drift, and a page that
 * registers under one key while resolving under another produces a row that
 * silently never renders.
 */
class CustomPages {

	/**
	 * Characters allowed in a custom page key.
	 *
	 * A key becomes both a settings array key (custom_page:checkout) and part
	 * of an HTML id, so it is restricted to what is safe in both. The colon
	 * in particular is excluded because it separates object type from subtype
	 * in every stored row key.
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z0-9_-]+$/';

	/**
	 * Registered custom pages.
	 *
	 * @return array<string, string> Key => label.
	 */
	public function all(): array {
		/**
		 * Filters the custom pages offered on the Titles & Templates tab.
		 *
		 * Registering a page here gives it template fields on the settings
		 * screen. It does NOT make those templates render — the plugin must
		 * also claim the request through `taseo_custom_page_context`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $pages Key => human-readable label.
		 */
		$pages = apply_filters( 'taseo_custom_pages', array() );

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$clean = array();

		foreach ( $pages as $key => $label ) {
			$key = (string) $key;

			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
				continue;
			}

			$label = (string) $label;

			$clean[ $key ] = '' !== $label ? $label : $key;
		}

		return $clean;
	}

	/**
	 * Whether a key is registered.
	 *
	 * @param string $key Custom page key.
	 * @return bool True when registered.
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->all() );
	}
}
```

- [ ] **Step 4: Register the service**

In `includes/Plugin.php`, add the import beside the other `Meta\` imports:

```php
use TheAnother\Plugin\SEO\Meta\CustomPages;
```

and register it beside the other `Meta` services:

```php
		$c->register( 'custom_pages', fn() => new CustomPages() );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `make test && make lint`
Expected: PASS, with six more tests than before.

- [ ] **Step 6: Commit**

```bash
git add includes/Meta/CustomPages.php includes/Plugin.php tests/Unit/Meta/CustomPagesTest.php
git commit -m "feat: add a registry for plugin-registered custom pages"
```

---

## Task 2: Front-end resolution

**Files:**
- Modify: `includes/Meta/CurrentContext.php:40-44` (constructor), `includes/Meta/CurrentContext.php:66` (top of `do_resolve()`)
- Modify: `includes/Plugin.php:123` (factory)
- Modify: `tests/Unit/Meta/CurrentContextVariablesTest.php:141,211,222` (three construction sites)
- Create: `tests/Unit/Meta/CurrentContextCustomPageTest.php`

**Interfaces:**
- Consumes: `CustomPages::has( string $key ): bool` from Task 1.
- Produces: the `taseo_custom_page_context` filter. Returns `null`, or `array( 'subtype' => string, 'vars' => array, 'permalink' => string )` where only `subtype` is required.

**Why the filter runs first — read this before writing code.** The intuitive placement is the fallthrough at the end of `do_resolve()`, where it currently returns `null`. That is wrong and would make the feature useless for its main use case. A virtual page is usually a real WordPress page: WooCommerce's checkout satisfies `is_checkout()` *and* `is_singular()`. At the fallthrough the filter never runs, because the `is_singular()` branch resolves it as `post:page` first — and when `page` is not an enabled post type, that branch `return null`s early, so the fallthrough is not reached either. The filter therefore runs at the **top**, and the registration requirement is what keeps it safe.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/CurrentContextCustomPageTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\CustomPages;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( CurrentContext::class )]
class CurrentContextCustomPageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private $custom_pages;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		// Every built-in branch says "not me" by default. Individual tests
		// flip the one they need.
		foreach ( array( 'is_singular', 'is_tax', 'is_category', 'is_tag', 'is_front_page', 'is_home', 'is_search', 'is_404', 'is_post_type_archive' ) as $conditional ) {
			Functions\when( $conditional )->justReturn( false );
		}

		$this->repository   = Mockery::mock( IndexableRepository::class );
		$this->settings     = Mockery::mock( Settings::class );
		$this->custom_pages = Mockery::mock( CustomPages::class );

		$this->repository->shouldReceive( 'find' )->andReturn( null )->byDefault();
		$this->settings->shouldReceive( 'get_separator' )->andReturn( '-' )->byDefault();
		$this->settings->shouldReceive( 'get_title_template' )->andReturn( '%%title%%' )->byDefault();
		$this->settings->shouldReceive( 'get_description_template' )->andReturn( '%%excerpt%%' )->byDefault();
		$this->custom_pages->shouldReceive( 'has' )->andReturn( false )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolve(): ?array {
		return ( new CurrentContext( $this->repository, $this->settings, $this->custom_pages ) )->resolve();
	}

	public function test_a_registered_declaration_produces_a_custom_page_context(): void {
		$this->custom_pages->shouldReceive( 'has' )->with( 'checkout' )->andReturn( true );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn(
			array(
				'subtype'   => 'checkout',
				'vars'      => array( 'title' => 'Checkout' ),
				'permalink' => 'https://example.com/checkout/',
			)
		);

		$context = $this->resolve();

		$this->assertSame( 'custom_page', $context['object_type'] );
		$this->assertSame( 'checkout', $context['object_subtype'] );
		$this->assertSame( 'https://example.com/checkout/', $context['permalink'] );
	}

	/**
	 * The plugin's vars merge OVER site_vars(), so a declaration can replace
	 * the site title with its own page title while keeping sep, tagline and
	 * the rest.
	 */
	public function test_declaration_vars_win_over_the_site_variables(): void {
		$this->custom_pages->shouldReceive( 'has' )->with( 'checkout' )->andReturn( true );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn(
			array(
				'subtype' => 'checkout',
				'vars'    => array( 'title' => 'Checkout' ),
			)
		);

		$context = $this->resolve();

		$this->assertSame( 'Checkout', $context['vars']['title'] );
		$this->assertSame( 'Test Site', $context['vars']['sitename'] );
		$this->assertSame( '-', $context['vars']['sep'] );
	}

	/**
	 * THE ordering test. A virtual page is usually a real WordPress page —
	 * WooCommerce's checkout is both is_checkout() and is_singular() — so a
	 * declaration must be able to claim a request the is_singular() branch
	 * would otherwise resolve as a post. This fails if the filter is moved
	 * to the fallthrough at the end of do_resolve().
	 */
	public function test_a_declaration_claims_a_request_that_is_singular_would_have_taken(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		$this->custom_pages->shouldReceive( 'has' )->with( 'checkout' )->andReturn( true );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn(
			array( 'subtype' => 'checkout' )
		);

		$context = $this->resolve();

		$this->assertSame( 'custom_page', $context['object_type'] );
		$this->assertSame( 'checkout', $context['object_subtype'] );
	}

	public function test_an_unregistered_subtype_is_ignored(): void {
		$this->custom_pages->shouldReceive( 'has' )->with( 'checkout' )->andReturn( false );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn(
			array( 'subtype' => 'checkout' )
		);

		$this->assertNull( $this->resolve() );
	}

	public function test_a_malformed_declaration_is_ignored(): void {
		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn( array( 'no_subtype' => true ) );

		$this->assertNull( $this->resolve() );
	}

	public function test_a_non_array_declaration_is_ignored(): void {
		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn( 'nonsense' );

		$this->assertNull( $this->resolve() );
	}

	/**
	 * With no filter registered, an unhandled request still resolves to null
	 * exactly as before — the check that adding the extension point changed
	 * nothing for a site that does not use it.
	 */
	public function test_no_filter_leaves_resolution_unchanged(): void {
		$this->assertNull( $this->resolve() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test`
Expected: FAIL — `CurrentContext::__construct()` takes 2 arguments, 3 given.

- [ ] **Step 3: Add the constructor dependency**

In `includes/Meta/CurrentContext.php`, add the import:

```php
use TheAnother\Plugin\SEO\Meta\CustomPages;
```

(If `CurrentContext` is already in the `Meta` namespace, no import is needed — check before adding one.)

Then extend the constructor:

```php
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings,
		private readonly CustomPages $custom_pages
	) {
	}
```

- [ ] **Step 4: Add the filter at the top of `do_resolve()`**

In `includes/Meta/CurrentContext.php`, as the **first statement** of `do_resolve()`, before `if ( is_singular() )`:

```php
		$custom_page = $this->resolve_custom_page();

		if ( null !== $custom_page ) {
			return $custom_page;
		}
```

and add the method:

```php
	/**
	 * Context for a plugin-registered custom page claiming this request.
	 *
	 * Applied before every built-in branch, not at the fallthrough. A virtual
	 * page is usually a real WordPress page — WooCommerce's checkout is both
	 * is_checkout() and is_singular() — so a fallthrough filter would never
	 * see it: is_singular() resolves it as post:page first, and when `page`
	 * is not an enabled post type that branch returns null early, so the
	 * fallthrough is never reached either.
	 *
	 * What keeps that override power safe is that claiming a request takes
	 * two deliberate acts in two filters: the subtype must be registered
	 * through taseo_custom_pages as well as declared here. Anything
	 * malformed or unregistered is ignored and resolution continues into the
	 * built-in branches unchanged.
	 *
	 * The filter returns a declaration rather than a context array, so the
	 * shape build() produces stays ours to change.
	 *
	 * @return array<string, mixed>|null Context, or null to continue resolving.
	 */
	private function resolve_custom_page(): ?array {
		/**
		 * Filters in a context for a plugin-registered custom page.
		 *
		 * Return null to leave the request alone, or an array of:
		 *   'subtype'   string, required, must be registered via taseo_custom_pages.
		 *   'vars'      array,  optional, merged over the site-level variables.
		 *   'permalink' string, optional.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed>|null $declaration Declaration, or null.
		 */
		$declaration = apply_filters( 'taseo_custom_page_context', null );

		if ( ! is_array( $declaration ) || ! isset( $declaration['subtype'] ) ) {
			return null;
		}

		$subtype = (string) $declaration['subtype'];

		if ( ! $this->custom_pages->has( $subtype ) ) {
			return null;
		}

		$vars = isset( $declaration['vars'] ) && is_array( $declaration['vars'] )
			? $declaration['vars']
			: array();

		$permalink = isset( $declaration['permalink'] ) ? (string) $declaration['permalink'] : '';

		return $this->build(
			'custom_page',
			$subtype,
			0,
			array_merge( $this->site_vars(), $vars ),
			$permalink
		);
	}
```

- [ ] **Step 5: Update the factory and the existing construction sites**

In `includes/Plugin.php:123`:

```php
			fn( Container $c ) => new CurrentContext( $c->get( 'indexable_repository' ), $c->get( 'settings' ), $c->get( 'custom_pages' ) )
```

In `tests/Unit/Meta/CurrentContextVariablesTest.php`, all three `new CurrentContext( $this->repository, $this->settings )` calls (lines 141, 211, 222) become:

```php
new CurrentContext( $this->repository, $this->settings, new CustomPages() )
```

with `use TheAnother\Plugin\SEO\Meta\CustomPages;` added to that file's imports. A real instance is correct there: with no `taseo_custom_pages` filter registered its `all()` returns `array()`, so `has()` is always false and that file's assertions are untouched.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `make test && make lint`
Expected: PASS. `CurrentContextVariablesTest` must still pass **unmodified apart from the constructor argument** — if any of its assertions need changing, the filter is firing when it should not, and that is a finding rather than a fixup.

- [ ] **Step 7: Commit**

```bash
git add includes/Meta/CurrentContext.php includes/Plugin.php tests/Unit/Meta/
git commit -m "feat: let a plugin claim a request as a custom page"
```

---

## Task 3: The Custom pages section and its empty state

**Files:**
- Modify: `includes/Admin/SettingsPage.php:131-139` (constructor), `includes/Admin/SettingsPage.php:445-556` (`render_templates_tab()`), `includes/Admin/SettingsPage.php:627-647` (`template_row_label()`)
- Modify: `includes/Plugin.php:157-164` (factory)
- Modify: `tests/Unit/Admin/SettingsPageTest.php:68` (construction)

**Interfaces:**
- Consumes: `CustomPages::all(): array<string,string>` from Task 1.
- Produces: rows named `taseo_settings[title_templates][custom_page:<key>]` and `taseo_settings[description_templates][custom_page:<key>]`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Admin/SettingsPageTest.php`. Note this file constructs `SettingsPage` once in `setUp()`; add a `$this->custom_pages` mock there and pass it as the seventh argument (Step 4 covers the wiring).

```php
	public function test_a_registered_custom_page_renders_a_row(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$html = $this->render_page();

		$this->assertStringContainsString(
			'name="taseo_settings[title_templates][custom_page:checkout]"',
			$html
		);
		$this->assertStringContainsString(
			'name="taseo_settings[description_templates][custom_page:checkout]"',
			$html
		);
	}

	public function test_a_custom_page_row_shows_its_label_and_its_key(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$html = $this->render_page();

		$this->assertStringContainsString( '>Checkout<', $html );
		$this->assertStringContainsString( '<code>custom_page:checkout</code>', $html );
	}

	/**
	 * Registering a row without claiming a request produces a template that
	 * never renders, so the empty state has to name both filters — that is
	 * the mistake it exists to prevent.
	 */
	public function test_the_empty_state_documents_both_filters(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array() );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Custom pages', $html );
		$this->assertStringContainsString( 'taseo_custom_pages', $html );
		$this->assertStringContainsString( 'taseo_custom_page_context', $html );
	}

	public function test_the_empty_state_is_absent_once_a_page_is_registered(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$this->assertStringNotContainsString( 'taseo_custom_page_context', $this->render_page() );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — `SettingsPage::__construct()` takes 6 arguments, 7 given, then missing-markup failures once the constructor is fixed.

- [ ] **Step 3: Add the constructor dependency and wire it up**

In `includes/Admin/SettingsPage.php`, add to the constructor's promoted properties, after `TemplateVariables $template_variables`:

```php
		private readonly CustomPages $custom_pages
```

with `use TheAnother\Plugin\SEO\Meta\CustomPages;` in the imports, and its `@param` line in the constructor docblock:

```php
	 * @param CustomPages           $custom_pages       Plugin-registered custom pages.
```

In `includes/Plugin.php:157-164`, add `$c->get( 'custom_pages' )` as the seventh argument.

In `tests/Unit/Admin/SettingsPageTest.php`, add the property, the mock in `setUp()`, and the argument:

```php
	private $custom_pages;
```

```php
		$this->custom_pages = Mockery::mock( CustomPages::class );
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array() )->byDefault();
```

```php
		$this->page = new SettingsPage(
			$this->settings,
			$this->backfill,
			$this->sitemap_files,
			$this->sitemap_writer,
			$this->sitemap_sweeper,
			$this->template_variables,
			$this->custom_pages
		);
```

with `use TheAnother\Plugin\SEO\Meta\CustomPages;` added. The `byDefault()` empty return matters: without it, every pre-existing test in the file dies with a Mockery "received unexpected call" the moment the templates tab renders.

- [ ] **Step 4: Add the `template_row_label()` branch**

In `includes/Admin/SettingsPage.php`, inside `template_row_label()`, **before** the `$system_labels` lookup — that lookup's `?? $object_subtype` fallthrough would otherwise swallow every custom page and return the raw key:

```php
		if ( 'custom_page' === $object_type ) {
			return $this->custom_pages->all()[ $object_subtype ] ?? $object_subtype;
		}
```

- [ ] **Step 5: Render the section**

In `includes/Admin/SettingsPage.php`, at the end of `render_templates_tab()` — after the System pages `echo '</table>';` — add:

```php
		echo '<hr />';

		echo '<h2>' . esc_html__( 'Custom pages', 'the-another-seo' ) . '</h2>';

		$custom_pages = $this->custom_pages->all();

		if ( array() === $custom_pages ) {
			$this->render_custom_pages_empty_state();

			return;
		}

		echo '<table class="form-table">';

		foreach ( $custom_pages as $key => $page_label ) {
			$row_key = 'custom_page:' . $key;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<fieldset>
						<legend class="screen-reader-text"><span>%1$s</span></legend>
						<label for="taseo-title-%3$s">%4$s</label><br />
						<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input /><br />
						<label for="taseo-desc-%3$s">%7$s</label><br />
						<input type="text" id="taseo-desc-%3$s" name="taseo_settings[description_templates][%2$s]" value="%8$s" class="%9$s" data-taseo-template-input />
					</fieldset>',
				esc_html( $this->template_row_label( 'custom_page', $key ) ),
				esc_attr( $row_key ),
				esc_attr( 'custom-page-' . $key ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'custom_page', $key ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) ),
				esc_html__( 'Meta description template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'custom_page', $key ) ),
				esc_attr( $this->template_input_class( 'description_templates', $row_key ) )
			);
			$this->render_variable_pills( 'custom_page', $key );
			echo '</td></tr>';
		}

		echo '</table>';
```

`render_variable_pills()`'s third parameter defaults to `true`, which is what custom pages want — both fields.

- [ ] **Step 6: Add the empty state**

Add this private method to `includes/Admin/SettingsPage.php`:

```php
	/**
	 * Explain how to register a custom page, when none are.
	 *
	 * Both filters are shown deliberately. Registering a row without also
	 * claiming a request produces template fields that save, redisplay, and
	 * never render — the exact mistake this text exists to prevent.
	 *
	 * @return void
	 */
	private function render_custom_pages_empty_state(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No custom pages are registered. Another plugin can add one — a checkout screen, an account area, any page this plugin cannot know about — by registering it and then claiming the request it appears on. Both steps are needed: a registered page with no claimed request gets template fields that never render.', 'the-another-seo' )
		);

		$snippet = "add_filter( 'taseo_custom_pages', function ( \$pages ) {\n"
			. "    \$pages['checkout'] = __( 'Checkout', 'my-plugin' );\n"
			. "    return \$pages;\n"
			. "} );\n\n"
			. "add_filter( 'taseo_custom_page_context', function ( \$context ) {\n"
			. "    if ( function_exists( 'is_checkout' ) && is_checkout() ) {\n"
			. "        return array(\n"
			. "            'subtype' => 'checkout',\n"
			. "            'vars'    => array( 'title' => __( 'Checkout', 'my-plugin' ) ),\n"
			. "        );\n"
			. "    }\n\n"
			. "    return \$context;\n"
			. "} );";

		printf( '<pre><code>%s</code></pre>', esc_html( $snippet ) );
	}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `make test && make lint`
Expected: PASS, with four more tests than before.

- [ ] **Step 8: Commit**

```bash
git add includes/Admin/SettingsPage.php includes/Plugin.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: render a Custom pages section from the registry"
```

---

## Task 4: Section navigation

**Files:**
- Modify: `includes/Admin/SettingsPage.php` (`render_templates_tab()` — the nav, and an `id` on each of the four `<h2>`s)
- Modify: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: the four sections rendered by Task 3 and the existing code.
- Produces: anchors `taseo-post-types`, `taseo-taxonomies`, `taseo-system-pages`, `taseo-custom-pages`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Admin/SettingsPageTest.php`:

```php
	/**
	 * Asserted as a pair rather than as two lists: a renamed heading id with
	 * no matching nav change would leave both halves individually plausible
	 * and the link broken.
	 */
	public function test_the_section_nav_links_match_the_section_headings(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		foreach ( array( 'taseo-post-types', 'taseo-taxonomies', 'taseo-system-pages', 'taseo-custom-pages' ) as $anchor ) {
			$this->assertStringContainsString( 'href="#' . $anchor . '"', $html, "nav link missing for {$anchor}" );
			$this->assertStringContainsString( 'id="' . $anchor . '"', $html, "heading id missing for {$anchor}" );
		}
	}

	/**
	 * .subsubsub is float: left (wp-admin/css/common.css:428). Without the
	 * clear, the first heading wraps alongside the nav instead of below it.
	 */
	public function test_the_section_nav_uses_core_classes_and_clears_its_float(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		$this->assertStringContainsString( 'class="subsubsub"', $html );
		$this->assertStringContainsString( 'class="clear"', $html );
		$this->assertStringNotContainsString( 'class="taseo', $html );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `make test`
Expected: FAIL — no `href="#taseo-post-types"`, no `class="subsubsub"`.

- [ ] **Step 3: Render the nav**

In `includes/Admin/SettingsPage.php`, in `render_templates_tab()`, after the two intro `<p class="description">` paragraphs and before the Post types heading:

```php
		$sections = array(
			'taseo-post-types'   => __( 'Post types', 'the-another-seo' ),
			'taseo-taxonomies'   => __( 'Taxonomies', 'the-another-seo' ),
			'taseo-system-pages' => __( 'System pages', 'the-another-seo' ),
			'taseo-custom-pages' => __( 'Custom pages', 'the-another-seo' ),
		);
		$last     = array_key_last( $sections );

		echo '<ul class="subsubsub">';

		foreach ( $sections as $anchor => $section_label ) {
			printf(
				'<li><a href="#%1$s">%2$s</a>%3$s</li>',
				esc_attr( $anchor ),
				esc_html( $section_label ),
				$anchor === $last ? '' : ' |'
			);
		}

		echo '</ul>';
		// .subsubsub is float: left (wp-admin/css/common.css:428), so without
		// this the first heading wraps beside the nav instead of below it.
		echo '<div class="clear"></div>';
```

- [ ] **Step 4: Add the heading ids**

Change the four headings in `render_templates_tab()` to carry matching ids. Post types:

```php
		echo '<h2 id="taseo-post-types">' . esc_html__( 'Post types', 'the-another-seo' ) . '</h2>';
```

Taxonomies:

```php
		echo '<h2 id="taseo-taxonomies">' . esc_html__( 'Taxonomies', 'the-another-seo' ) . '</h2>';
```

System pages:

```php
		echo '<h2 id="taseo-system-pages">' . esc_html__( 'System pages', 'the-another-seo' ) . '</h2>';
```

Custom pages (added in Task 3):

```php
		echo '<h2 id="taseo-custom-pages">' . esc_html__( 'Custom pages', 'the-another-seo' ) . '</h2>';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `make test && make lint`
Expected: PASS, with two more tests than before.

- [ ] **Step 6: Commit**

```bash
git add includes/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: add anchor navigation across the template sections"
```

---

## Task 5: E2E coverage

**Files:**
- Modify: `tests/e2e/functional/environment/serve-wp.sh` (write the fixture mu-plugin)
- Modify: `tests/e2e/functional/specs/webmaster-admin.spec.ts`

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Write the fixture as an mu-plugin from the environment script**

There is no existing mu-plugin mechanism — the environment installs the plugin from the packaged zip and then seeds options with wp-cli. Add the fixture the same way, in `tests/e2e/functional/environment/serve-wp.sh`, immediately **after** the `wp plugin install "$ZIP" --activate …` line and before the `wp rewrite structure` line:

```bash
# A fixture plugin registering one custom page, so the Titles & Templates
# spec has something to assert against. It registers ONLY the page and not a
# context claim: taseo_custom_page_context runs before the built-in branches
# and would otherwise take over real requests, disturbing every other spec.
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cat > "$WP_DIR/wp-content/mu-plugins/taseo-custom-page-fixture.php" <<'PHP'
<?php
/**
 * Plugin Name: TASEO custom page fixture
 * Description: Registers a custom page so the e2e suite can exercise the registry.
 */

add_filter(
	'taseo_custom_pages',
	static function ( $pages ) {
		$pages['e2e_checkout'] = 'E2E Checkout';

		return $pages;
	}
);
PHP
```

`$WP_DIR` is already in scope — every wp-cli call in that script passes `--path="$WP_DIR"`.

- [ ] **Step 2: Confirm the fixture loads**

Run: `make test-e2e` and confirm the suite still provisions. If the mu-plugin has a syntax error the whole site white-screens and every spec fails at once, so a broad failure here means the heredoc, not your specs.

- [ ] **Step 3: Write the e2e specs**

Add to `tests/e2e/functional/specs/webmaster-admin.spec.ts`:

```ts
test( 'a registered custom page gets its own template row', async ( {
	page,
} ) => {
	await page.goto( TEMPLATES_TAB_URL );

	await expect(
		page.locator( 'h2#taseo-custom-pages' )
	).toBeVisible();
	await expect(
		page.locator(
			'input[name="taseo_settings[title_templates][custom_page:e2e_checkout]"]'
		)
	).toHaveCount( 1 );
} );

test( 'a custom page template saves and survives a reload', async ( {
	page,
} ) => {
	await page.goto( TEMPLATES_TAB_URL );

	await fillTemplate(
		page,
		'taseo_settings[title_templates][custom_page:e2e_checkout]',
		'%%title%% %%sep%% %%sitename%%'
	);
	await page.locator( '#submit' ).click( { force: true } );

	await page.goto( TEMPLATES_TAB_URL );
	await expect(
		page.locator(
			'input[name="taseo_settings[title_templates][custom_page:e2e_checkout]"]'
		)
	).toHaveValue( '%%title%% %%sep%% %%sitename%%' );
} );

test( 'the section nav jumps to a section', async ( { page } ) => {
	await page.goto( TEMPLATES_TAB_URL );

	await page.locator( 'a[href="#taseo-custom-pages"]' ).click();

	await expect( page.locator( 'h2#taseo-custom-pages' ) ).toBeInViewport();
} );
```

`fillTemplate` and `TEMPLATES_TAB_URL` already exist in that file. The template inputs are driven by the chip surface, so use `fillTemplate` rather than `fill()` — a bare `fill()` on a hidden input throws.

- [ ] **Step 4: Run the e2e suite**

Run: `make test-e2e`
Expected: all specs pass, three more than the baseline. Record the baseline count before your change.

If registering the fixture changes any existing spec's expectations, **do not weaken that spec** — report it. A new section appearing on the tab should not disturb anything, and if it does that is worth knowing.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/
git commit -m "test: cover custom page registration and section navigation"
```

---

## Task 6: Full gate, changelog, and push

- [ ] **Step 1: Add the changelog entries**

In `CHANGELOG.md`, under `## [Unreleased]` → `### Added`:

```markdown
- Custom pages on the Titles & Templates tab: another plugin can register a page of its own — a checkout screen, an account area, any virtual page — with `add_filter( 'taseo_custom_pages', … )`, giving it title and meta description template rows, and claim the request it appears on with `add_filter( 'taseo_custom_page_context', … )` so those templates actually render. The context filter runs before the built-in checks, so a custom page backed by a real WordPress page (as WooCommerce's checkout is) can still claim it; only a subtype registered through the first filter resolves, so the two are a matched pair. With none registered, the section explains both steps.
- Titles & Templates tab: a section navigation across Post types, Taxonomies, System pages and Custom pages, using core's own `subsubsub` styling.
```

- [ ] **Step 2: Run every gate**

```bash
make lint
make test
make test-js
make test-e2e
make check-plugin
```

Report actual observed numbers. If any gate fails or a count drops, stop and report — do not adjust a test to make it pass.

- [ ] **Step 3: Commit and push**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for custom pages and section navigation"
git push
gh pr checks 2 --watch --interval 30
```

Expected: all five CI jobs pass.

---

## Notes for the implementer

**The context filter runs first, and that is not negotiable.** If you find yourself moving it to the fallthrough because it reads more safely there, re-read Task 2's preamble: the feature's motivating example stops working. `test_a_declaration_claims_a_request_that_is_singular_would_have_taken` is the test that pins it.

**Two constructors gain a parameter, and their test files fatal if you miss one.** `CurrentContext` (3 sites in `CurrentContextVariablesTest.php`) and `SettingsPage` (1 site in `SettingsPageTest.php`). An argument-count error is a fatal, not a readable failure.

**Mock `CustomPages::all()` with `->byDefault()` in `SettingsPageTest::setUp()`.** Every pre-existing test in that file renders the templates tab at some point; without a default the first one dies on an unexpected Mockery call.

**An invalid key is skipped, never rewritten.** Rewriting is the tempting fix and the wrong one — the registering plugin's context filter would then name a key that no longer exists, and the row would silently never render.
