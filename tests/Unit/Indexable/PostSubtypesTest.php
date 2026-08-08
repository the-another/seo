<?php
/**
 * PostSubtypes registry tests.
 *
 * @package TheAnotherSEO
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Indexable;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;

#[CoversClass( PostSubtypes::class )]
final class PostSubtypesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private PostSubtypes $subtypes;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'    => 'post',
				'page'    => 'page',
				'product' => 'product',
			)
		);
		Functions\when( 'get_taxonomies' )->justReturn(
			array(
				'category'    => 'category',
				'product_cat' => 'product_cat',
			)
		);
		// esc_html() is defined by the bootstrap; Patchwork cannot redefine it.
		Functions\when( '_doing_it_wrong' )->justReturn( null );

		$this->subtypes = new PostSubtypes();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function declare_subtypes( array $map ): void {
		Filters\expectApplied( 'taseo_post_subtypes' )->andReturn( $map );
	}

	private function make_post( string $post_type ): object {
		$post            = Mockery::mock( 'WP_Post' );
		$post->post_type = $post_type;

		return $post;
	}

	private function stub_post_type_label( string $label ): void {
		$object                = new \stdClass();
		$object->labels        = new \stdClass();
		$object->labels->name  = $label;

		Functions\when( 'get_post_type_object' )->justReturn( $object );
	}

	public function test_no_declarations_leaves_every_post_type_as_its_own_subtype(): void {
		$this->declare_subtypes( array() );
		$this->stub_post_type_label( 'Products' );

		$this->assertSame( array(), $this->subtypes->all() );
		$this->assertSame( array( 'product' ), $this->subtypes->keys_for_post_type( 'product' ) );
		$this->assertSame( 'product', $this->subtypes->post_type_for( 'product' ) );
		$this->assertSame( 'product', $this->subtypes->resolve( $this->make_post( 'product' ) ) );
	}

	public function test_declared_subtypes_gain_the_post_type_as_a_fallback_bucket(): void {
		$this->declare_subtypes(
			array( 'product' => array( 'aucteeno_auction' => 'Auctions', 'aucteeno_item' => 'Items' ) )
		);
		$this->stub_post_type_label( 'Products' );

		$this->assertSame(
			array( 'aucteeno_auction', 'aucteeno_item', 'product' ),
			$this->subtypes->keys_for_post_type( 'product' )
		);
		$this->assertSame(
			array(
				'aucteeno_auction' => 'Auctions',
				'aucteeno_item'    => 'Items',
				'product'          => 'Products',
			),
			$this->subtypes->for_post_type( 'product' )
		);
	}

	public function test_a_key_colliding_with_a_post_type_or_taxonomy_is_dropped(): void {
		$this->declare_subtypes(
			array(
				'product' => array(
					'page'             => 'Collides with a post type',
					'product_cat'      => 'Collides with a taxonomy',
					'aucteeno_auction' => 'Auctions',
				),
			)
		);

		$this->assertSame(
			array( 'aucteeno_auction' => 'Auctions' ),
			$this->subtypes->all()['product']
		);
	}

	public function test_a_malformed_key_or_label_is_dropped(): void {
		$this->declare_subtypes(
			array(
				'product' => array(
					'Has Capitals'     => 'Rejected',
					'has:colon'        => 'Rejected',
					'aucteeno_auction' => array( 'not', 'scalar' ),
					'aucteeno_item'    => 'Items',
				),
			)
		);

		$this->assertSame( array( 'aucteeno_item' => 'Items' ), $this->subtypes->all()['product'] );
	}

	public function test_declarations_for_an_unregistered_post_type_are_dropped(): void {
		$this->declare_subtypes( array( 'nonexistent' => array( 'whatever' => 'Whatever' ) ) );

		$this->assertSame( array(), $this->subtypes->all() );
	}

	public function test_resolve_returns_a_declared_subtype(): void {
		$this->declare_subtypes( array( 'product' => array( 'aucteeno_auction' => 'Auctions' ) ) );
		Filters\expectApplied( 'taseo_post_subtype' )->andReturn( 'aucteeno_auction' );

		$this->assertSame( 'aucteeno_auction', $this->subtypes->resolve( $this->make_post( 'product' ) ) );
	}

	public function test_resolve_falls_back_when_the_filter_returns_an_undeclared_key(): void {
		$this->declare_subtypes( array( 'product' => array( 'aucteeno_auction' => 'Auctions' ) ) );
		Filters\expectApplied( 'taseo_post_subtype' )->andReturn( 'typo_never_declared' );

		$this->assertSame( 'product', $this->subtypes->resolve( $this->make_post( 'product' ) ) );
	}

	public function test_resolve_falls_back_when_the_filter_returns_another_post_types_subtype(): void {
		$this->declare_subtypes(
			array(
				'product' => array( 'aucteeno_auction' => 'Auctions' ),
				'post'    => array( 'editorial_note' => 'Notes' ),
			)
		);
		Filters\expectApplied( 'taseo_post_subtype' )->andReturn( 'editorial_note' );

		$this->assertSame( 'product', $this->subtypes->resolve( $this->make_post( 'product' ) ) );
	}

	public function test_resolve_falls_back_on_a_non_string_return(): void {
		$this->declare_subtypes( array( 'product' => array( 'aucteeno_auction' => 'Auctions' ) ) );
		Filters\expectApplied( 'taseo_post_subtype' )->andReturn( array( 'nonsense' ) );

		$this->assertSame( 'product', $this->subtypes->resolve( $this->make_post( 'product' ) ) );
	}

	public function test_post_type_for_maps_a_declared_subtype_back_to_its_owner(): void {
		$this->declare_subtypes( array( 'product' => array( 'aucteeno_item' => 'Items' ) ) );

		$this->assertSame( 'product', $this->subtypes->post_type_for( 'aucteeno_item' ) );
		$this->assertTrue( $this->subtypes->has( 'aucteeno_item' ) );
		$this->assertFalse( $this->subtypes->has( 'product' ) );
	}

	public function test_flatten_lists_every_subtype_of_the_given_post_types(): void {
		$this->declare_subtypes( array( 'product' => array( 'aucteeno_item' => 'Items' ) ) );
		$this->stub_post_type_label( 'Label' );

		$this->assertSame(
			array( 'aucteeno_item' => 'Items', 'product' => 'Label', 'post' => 'Label' ),
			$this->subtypes->flatten( array( 'product', 'post' ) )
		);
	}
}
