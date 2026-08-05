<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Indexable;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\IndexableSync;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( IndexableSync::class )]
class IndexableSyncTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private array $rebuild_calls = array();
	private IndexableSync $sync;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository    = Mockery::mock( IndexableRepository::class );
		$this->settings      = Mockery::mock( Settings::class );
		$this->rebuild_calls = array();

		$this->sync = new IndexableSync(
			$this->repository,
			$this->settings,
			function (): void {
				$this->rebuild_calls[] = true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_post( int $id, string $type = 'product', string $status = 'publish' ): object {
		$post                    = Mockery::mock( 'WP_Post' );
		$post->ID                = $id;
		$post->post_type         = $type;
		$post->post_status       = $status;
		$post->post_modified_gmt = '2026-07-02 10:00:00';
		return $post;
	}

	public function test_sync_post_upserts_published_enabled_post_as_indexable(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/widget/' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'post',
				'product',
				88123,
				array(
					'permalink'     => 'https://example.com/product/widget/',
					'is_indexable'  => true,
					'last_modified' => '2026-07-02 10:00:00',
					'images'        => array(),
				)
			);

		$this->sync->sync_post( 88123 );
	}

	public function test_sync_post_marks_non_published_as_not_indexable(): void {
		$post = $this->make_post( 88123, 'product', 'draft' );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/?p=88123' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with( 'post', 'product', 88123, Mockery::on( fn( array $f ): bool => false === $f['is_indexable'] && isset( $f['images'] ) ) );

		$this->sync->sync_post( 88123 );
	}

	public function test_sync_post_bails_for_revision(): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 5, 'revision' ) );
		Functions\when( 'wp_is_post_revision' )->justReturn( 4 );

		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->sync->sync_post( 5 );
	}

	public function test_sync_post_bails_for_disabled_post_type(): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 5, 'shop_order' ) );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) );

		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->sync->sync_post( 5 );
	}

	public function test_handle_trash_post_keeps_row_not_indexable(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with( 'post', 'product', 88123, Mockery::on( fn( array $f ): bool => false === $f['is_indexable'] ) );

		$this->sync->handle_trash_post( 88123 );
	}

	public function test_handle_before_delete_post_deletes_row(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );

		$this->repository->shouldReceive( 'delete' )->once()->with( 'post', 'product', 88123 );

		$this->sync->handle_before_delete_post( 88123 );
	}

	public function test_sync_term_upserts_enabled_taxonomy_term(): void {
		$term                   = Mockery::mock( 'WP_Term' );
		$term->term_id          = 44;
		$term->taxonomy         = 'product_cat';

		Functions\when( 'get_term' )->justReturn( $term );
		Functions\when( 'is_taxonomy_viewable' )->justReturn( true );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/product-category/tools/' );
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category', 'product_cat' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'term',
				'product_cat',
				44,
				Mockery::on(
					fn( array $f ): bool => 'https://example.com/product-category/tools/' === $f['permalink']
						&& true === $f['is_indexable']
				)
			);

		$this->sync->sync_term( 44, 'product_cat' );
	}

	public function test_sync_term_bails_for_disabled_taxonomy(): void {
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category' ) );

		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->sync->sync_term( 44, 'nav_menu' );
	}

	public function test_handle_delete_term_deletes_row(): void {
		$this->repository->shouldReceive( 'delete' )->once()->with( 'term', 'product_cat', 44 );

		$this->sync->handle_delete_term( 44, 99, 'product_cat' );
	}

	public function test_permalink_structure_change_invokes_rebuild_dispatcher(): void {
		$this->sync->handle_permalink_structure_changed();

		$this->assertCount( 1, $this->rebuild_calls );
	}

	public function test_sync_post_passes_featured_image_as_base(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/widget/' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://example.com/thumb.jpg' );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'post',
				'product',
				88123,
				Mockery::on(
					fn( array $fields ): bool => array( 'https://example.com/thumb.jpg' ) === $fields['images']
				)
			);

		$this->sync->sync_post( 88123 );
	}

	public function test_sync_post_passes_empty_images_without_thumbnail(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/widget/' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'post',
				'product',
				88123,
				Mockery::on(
					fn( array $fields ): bool => array() === $fields['images']
				)
			);

		$this->sync->sync_post( 88123 );
	}
}
