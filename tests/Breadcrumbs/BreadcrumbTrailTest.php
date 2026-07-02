<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( BreadcrumbTrail::class )]
class BreadcrumbTrailTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private BreadcrumbTrail $trail;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->settings   = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'get_breadcrumb_home_label' )->andReturn( 'Home' )->byDefault();
		$this->settings->shouldReceive( 'breadcrumb_include_taxonomy_ancestors' )->andReturn( true )->byDefault();
		$this->repository->shouldReceive( 'find_for_post' )->andReturn( null )->byDefault();
		$this->repository->shouldReceive( 'find_for_term' )->andReturn( null )->byDefault();

		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );

		$this->trail = new BreadcrumbTrail( $this->repository, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mock_singular_post( int $id, string $type, string $title ): object {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_title  = $title;
		$post->post_parent = 0;

		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $post );
		Functions\when( 'get_the_title' )->alias( fn( $p ) => is_object( $p ) ? $p->post_title : 'Parent Page' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/current/' );

		return $post;
	}

	public function test_simple_page_trail_is_home_then_current(): void {
		$this->mock_singular_post( 512, 'page', 'About Us' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$this->assertSame(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'About Us', 'url' => 'https://example.com/current/' ),
			),
			$this->trail->build()
		);
	}

	public function test_product_trail_includes_archive_and_term_ancestors(): void {
		$this->mock_singular_post( 88123, 'product', 'Vintage Watch' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/shop/' );

		$parent_term            = Mockery::mock( 'WP_Term' );
		$parent_term->term_id   = 9;
		$parent_term->name      = 'Watches';
		$parent_term->parent    = 0;
		$leaf_term              = Mockery::mock( 'WP_Term' );
		$leaf_term->term_id     = 10;
		$leaf_term->name        = 'Vintage';
		$leaf_term->parent      = 9;

		Functions\when( 'get_the_terms' )->justReturn( array( $leaf_term ) );
		Functions\when( 'get_term' )->justReturn( $parent_term );
		Functions\when( 'get_term_link' )->alias( fn( $t ) => 9 === $t->term_id ? 'https://example.com/watches/' : 'https://example.com/watches/vintage/' );
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'labels' => (object) array( 'name' => 'Shop' ) ) );

		$trail = $this->trail->build();

		$this->assertSame( 'Home', $trail[0]['title'] );
		$this->assertSame( 'Shop', $trail[1]['title'] );
		$this->assertSame( 'Watches', $trail[2]['title'] );
		$this->assertSame( 'Vintage', $trail[3]['title'] );
		$this->assertSame( 'Vintage Watch', $trail[4]['title'] );
	}

	public function test_term_lineage_breaks_circular_parent_chain(): void {
		$this->mock_singular_post( 40001, 'product', 'Corrupted Data Product' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );

		// Circular taxonomy data: term 9's parent is term 10, and term 10's parent is term 9.
		$term_nine            = Mockery::mock( 'WP_Term' );
		$term_nine->term_id   = 9;
		$term_nine->name      = 'Cyclic Nine';
		$term_nine->parent    = 10;
		$term_ten             = Mockery::mock( 'WP_Term' );
		$term_ten->term_id    = 10;
		$term_ten->name       = 'Cyclic Ten';
		$term_ten->parent     = 9;

		Functions\when( 'get_the_terms' )->justReturn( array( $term_ten ) );
		Functions\when( 'get_term' )->alias( fn( $term_id ) => 9 === $term_id ? $term_nine : $term_ten );
		Functions\when( 'get_term_link' )->alias( fn( $t ) => "https://example.com/term-{$t->term_id}/" );

		$trail = $this->trail->build();

		// Home, the two cyclic terms (each visited once), and the current product — bounded, not infinite.
		$this->assertCount( 4, $trail );
		$this->assertSame( 'Home', $trail[0]['title'] );
		$this->assertSame( 'Cyclic Nine', $trail[1]['title'] );
		$this->assertSame( 'Cyclic Ten', $trail[2]['title'] );
		$this->assertSame( 'Corrupted Data Product', $trail[3]['title'] );
	}

	public function test_term_lineage_uses_breadcrumb_title_override(): void {
		$this->mock_singular_post( 88123, 'product', 'Vintage Watch' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );

		$leaf_term          = Mockery::mock( 'WP_Term' );
		$leaf_term->term_id = 10;
		$leaf_term->name    = 'Vintage';
		$leaf_term->parent  = 0;

		Functions\when( 'get_the_terms' )->justReturn( array( $leaf_term ) );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/vintage/' );

		$this->repository->shouldReceive( 'find_for_term' )
			->with( 10 )
			->andReturn( array( 'breadcrumb_title' => 'Short Cat' ) );

		$trail = $this->trail->build();

		$this->assertSame( 'Home', $trail[0]['title'] );
		$this->assertSame( 'Short Cat', $trail[1]['title'] );
		$this->assertSame( 'Vintage Watch', $trail[2]['title'] );
	}

	public function test_breadcrumb_title_override_replaces_current_title(): void {
		$this->mock_singular_post( 88123, 'page', 'A Very Long Original Product Title' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$this->repository->shouldReceive( 'find_for_post' )
			->with( 88123 )
			->andReturn( array( 'breadcrumb_title' => 'Short Title' ) );

		$trail = $this->trail->build();

		$this->assertSame( 'Short Title', end( $trail )['title'] );
	}

	public function test_hierarchical_page_ancestors_appear_in_order(): void {
		$post = $this->mock_singular_post( 30, 'page', 'Grandchild' );
		Functions\when( 'get_ancestors' )->justReturn( array( 20, 10 ) ); // closest first (WP order).
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_permalink' )->alias( fn( $p = null ) => is_numeric( $p ) ? "https://example.com/page-{$p}/" : 'https://example.com/current/' );

		$trail  = $this->trail->build();
		$titles = array_column( $trail, 'title' );

		// Home, root ancestor (10), then 20, then current.
		$this->assertSame( 'Home', $titles[0] );
		$this->assertSame( 'https://example.com/page-10/', $trail[1]['url'] );
		$this->assertSame( 'https://example.com/page-20/', $trail[2]['url'] );
		$this->assertSame( 'Grandchild', $titles[3] );
	}

	public function test_non_singular_returns_home_only(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		$this->assertSame(
			array( array( 'title' => 'Home', 'url' => 'https://example.com/' ) ),
			$this->trail->build()
		);
	}
}
