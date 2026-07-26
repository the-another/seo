<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
		// is_wp_error() is a real function (tests/Unit/bootstrap.php), defined
		// directly in the bootstrap script rather than through a Patchwork-
		// wrapped include, so Brain\Monkey cannot redefine it — stubbing it
		// throws Patchwork\Exceptions\DefinedTooEarly. Its real
		// implementation (`$thing instanceof WP_Error`) already returns
		// false for the plain string links these tests use, so no stub is
		// needed here.
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

	/**
	 * Runs in its own process: Functions\when( 'wc_get_product' ) makes
	 * Patchwork define a real global function for the rest of the PHP
	 * process — it can never be undefined. Left in-process, that would
	 * permanently flip function_exists( 'wc_get_product' ) to true for
	 * every test that runs afterwards, including
	 * TemplateVariablesTest::test_products_omit_price_and_sku_without_woocommerce,
	 * which asserts the opposite. Isolating this one test confines the
	 * definition to a process that exits immediately after it.
	 */
	#[RunInSeparateProcess]
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
