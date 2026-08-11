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
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;
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
		return ( new CurrentContext( $this->repository, $this->settings, $this->custom_pages, new PostSubtypes() ) )->resolve();
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

	/**
	 * has() is stubbed to return true for any argument, so the only thing
	 * standing between this declaration and build() is the isset( 'subtype' )
	 * guard itself — a weakened guard (e.g. `$declaration['subtype'] ?? ''`)
	 * would let this resolve and turn assertNull() into a real failure.
	 */
	public function test_a_malformed_declaration_is_ignored(): void {
		$this->custom_pages->shouldReceive( 'has' )->andReturn( true );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn( array( 'no_subtype' => true ) );

		$this->assertNull( $this->resolve() );
	}

	/**
	 * has() is stubbed to return true for any argument, so the only thing
	 * standing between this declaration and build() is the is_array() guard
	 * itself.
	 */
	public function test_a_non_array_declaration_is_ignored(): void {
		$this->custom_pages->shouldReceive( 'has' )->andReturn( true );

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

	/**
	 * has() is stubbed to return true unconditionally, so the only thing
	 * standing between this declaration and the (string) cast in
	 * resolve_custom_page() is the is_scalar() guard itself. Without that
	 * guard, casting an object to string throws an uncaught Error on every
	 * front-end request instead of being ignored like every other malformed
	 * declaration.
	 */
	public function test_an_object_subtype_is_ignored(): void {
		$this->custom_pages->shouldReceive( 'has' )->andReturn( true );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn(
			array( 'subtype' => new \stdClass() )
		);

		$this->assertNull( $this->resolve() );
	}

	/**
	 * Same guard, an array subtype this time — the shape a plugin might
	 * plausibly hand back given the filter is named ..._context, which
	 * invites returning richer data than a plain string.
	 */
	public function test_an_array_subtype_is_ignored(): void {
		$this->custom_pages->shouldReceive( 'has' )->andReturn( true );

		Filters\expectApplied( 'taseo_custom_page_context' )->andReturn(
			array( 'subtype' => array( 'nested' => true ) )
		);

		$this->assertNull( $this->resolve() );
	}
}
