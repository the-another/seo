<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
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
use WP_Post;

/**
 * The second ordering test (CurrentContextCustomPageTest has the first): a
 * static front page is a real WordPress page, so the home request satisfies
 * is_singular() as well as is_front_page(). If the singular branch resolves
 * first, every site with a static front page serves its home page as
 * post:page — the system_page:home templates become unreachable and the
 * home title degrades to the page's own post title through the post:page
 * fallback template.
 */
#[CoversClass( CurrentContext::class )]
class CurrentContextFrontPageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		// Every built-in branch says "not me" by default. Individual tests
		// flip the ones they need.
		foreach ( array( 'is_singular', 'is_tax', 'is_category', 'is_tag', 'is_front_page', 'is_home', 'is_search', 'is_404', 'is_post_type_archive' ) as $conditional ) {
			Functions\when( $conditional )->justReturn( false );
		}

		// Everything the singular branch needs to run to completion: when
		// resolution takes the wrong branch, the front-page test must fail
		// on its assertions, not error out halfway down post_vars().
		$page            = Mockery::mock( WP_Post::class );
		$page->ID        = 7;
		$page->post_type = 'page';
		Functions\when( 'get_queried_object' )->justReturn( $page );
		Functions\when( 'get_the_title' )->justReturn( 'Items for Sale by Auction' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_the_date' )->justReturn( '1 January 2026' );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/frontpage/' );

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->repository->shouldReceive( 'find' )->andReturn( null )->byDefault();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_separator' )->andReturn( '–' )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'page' ) )->byDefault();
		$this->settings->shouldReceive( 'get_title_template' )->andReturn( '%%title%%' )->byDefault();
		$this->settings->shouldReceive( 'get_description_template' )->andReturn( '%%excerpt%%' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolve(): ?array {
		return ( new CurrentContext( $this->repository, $this->settings, new CustomPages(), new PostSubtypes() ) )->resolve();
	}

	public function test_a_static_front_page_resolves_as_the_home_system_page(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( true );

		$context = $this->resolve();

		$this->assertSame( 'system_page', $context['object_type'] );
		$this->assertSame( 'home', $context['object_subtype'] );
		$this->assertSame( 'https://example.com/', $context['permalink'] );
	}

	public function test_a_regular_page_still_resolves_as_a_post(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		$context = $this->resolve();

		$this->assertSame( 'post', $context['object_type'] );
		$this->assertSame( 'page', $context['object_subtype'] );
	}

	/**
	 * Static-front-page + posts-page setup: the blog page is is_home() but
	 * not is_front_page(), and must keep canonicalizing to its own
	 * permalink rather than the site root — the branch carries this logic
	 * with it wherever it moves in do_resolve().
	 */
	public function test_the_posts_page_keeps_its_own_permalink(): void {
		Functions\when( 'is_home' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/blog/' );

		$context = $this->resolve();

		$this->assertSame( 'system_page', $context['object_type'] );
		$this->assertSame( 'home', $context['object_subtype'] );
		$this->assertSame( 'https://example.com/blog/', $context['permalink'] );
	}
}
