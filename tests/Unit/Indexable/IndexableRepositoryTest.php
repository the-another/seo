<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Indexable;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;

#[CoversClass( IndexableRepository::class )]
class IndexableRepositoryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private IndexableRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->repository = new IndexableRepository();

		Monkey\Functions\when( 'esc_url_raw' )->returnArg();
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Every post upsert now also looks for rows left under a previous
		// subtype. Default to "none", so tests that are not about the purge
		// are unaffected by it.
		$this->wpdb->shouldReceive( 'prepare' )
			->with(
				Mockery::on( static fn( string $sql ): bool => str_contains( $sql, 'SELECT object_subtype FROM' ) ),
				Mockery::any(),
				Mockery::any(),
				Mockery::any()
			)
			->andReturn( 'PURGE_SQL' )
			->byDefault();
		// Permissive on the argument: several tests stub prepare() with a
		// catch-all of their own, so the purge query does not always come
		// back as PURGE_SQL. Tests that assert on the purge override this.
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_upsert_synced_fields_issues_insert_on_duplicate_key_update(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( string $sql ): bool {
						return str_contains( $sql, 'INSERT INTO wp_taseo_indexables' )
							&& str_contains( $sql, 'ON DUPLICATE KEY UPDATE' )
							&& str_contains( $sql, 'permalink = VALUES(permalink)' )
							&& str_contains( $sql, 'is_indexable = VALUES(is_indexable)' )
							&& str_contains( $sql, 'last_modified = VALUES(last_modified)' )
							&& ! str_contains( $sql, 'title = VALUES' ); // overrides never synced.
					}
				),
				'post',
				'product',
				88123,
				'https://example.com/product/widget/',
				1,
				'2026-07-02 10:00:00'
			)
			->andReturn( 'PREPARED_SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_SQL' );

		$this->repository->upsert_synced_fields(
			'post',
			'product',
			88123,
			array(
				'permalink'     => 'https://example.com/product/widget/',
				'is_indexable'  => true,
				'last_modified' => '2026-07-02 10:00:00',
			)
		);
	}

	public function test_upsert_stores_filtered_images_as_json(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_images' )
			->once()
			->with( array( 'https://example.com/a.jpg' ), 'post', 'product', 7 )
			->andReturn( array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ) );

		$captured = array();
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured ): string {
					if ( ! str_contains( $sql, 'INSERT INTO' ) ) {
						return 'PURGE_SQL'; // The stale-subtype probe.
					}

					$captured = array( $sql, $args );
					return 'SQL';
				}
			);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' );
		Monkey\Actions\expectDone( 'taseo_indexable_synced' )->once()->with( 'post', 'product', 7 );

		$this->repository->upsert_synced_fields(
			'post',
			'product',
			7,
			array(
				'permalink' => 'https://example.com/p/7/',
				'images'    => array( 'https://example.com/a.jpg' ),
			)
		);

		$this->assertStringContainsString( 'sitemap_images', $captured[0] );
		$this->assertContains(
			wp_json_encode( array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ) ),
			$captured[1]
		);
	}

	public function test_upsert_stores_sql_null_when_no_images(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_images' )->once()->andReturn( array() );

		$captured_sql = '';
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_sql ): string {
					if ( ! str_contains( $sql, 'INSERT INTO' ) ) {
						return 'PURGE_SQL'; // The stale-subtype probe.
					}

					$captured_sql = $sql;
					return 'SQL';
				}
			);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' );
		Monkey\Actions\expectDone( 'taseo_indexable_synced' )->once();

		$this->repository->upsert_synced_fields( 'post', 'post', 1, array( 'permalink' => 'https://example.com/x/' ) );

		$this->assertStringContainsString( 'NULL', $captured_sql );
		$this->assertStringNotContainsString( "VALUES(sitemap_images)\n\t\t\tWHERE", $captured_sql );
	}

	public function test_images_are_capped_and_non_absolute_urls_dropped(): void {
		$many = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$many[] = 'https://example.com/img-' . $i . '.jpg';
		}
		$many[] = '/relative.jpg';
		$many[] = 42;

		Monkey\Filters\expectApplied( 'taseo_sitemap_images' )->once()->andReturn( $many );

		$captured = array();
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured ): string {
					if ( ! str_contains( $sql, 'INSERT INTO' ) ) {
						return 'PURGE_SQL'; // The stale-subtype probe.
					}

					$captured = $args;
					return 'SQL';
				}
			);
		$this->wpdb->shouldReceive( 'query' )->once();
		Monkey\Actions\expectDone( 'taseo_indexable_synced' )->once();

		$this->repository->upsert_synced_fields( 'post', 'post', 1, array( 'permalink' => 'https://example.com/x/' ) );

		$json    = end( $captured );
		$decoded = json_decode( (string) $json, true );
		$this->assertCount( 50, $decoded );
		$this->assertNotContains( '/relative.jpg', $decoded );
	}

	public function test_find_returns_row_as_assoc_array(): void {
		$row = array( 'id' => '9', 'object_type' => 'post', 'object_id' => '5' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'SQL', ARRAY_A )->andReturn( $row );

		$this->assertSame( $row, $this->repository->find( 'post', 'page', 5 ) );
	}

	public function test_find_returns_null_when_absent(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( $this->repository->find( 'post', 'page', 5 ) );
	}

	public function test_find_for_post_looks_up_without_subtype(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, "object_type = 'post'" )
						&& str_contains( $sql, 'object_id = %d' )
						&& ! str_contains( $sql, 'object_subtype' )
				),
				123
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( array( 'id' => '1' ) );

		$this->assertSame( array( 'id' => '1' ), $this->repository->find_for_post( 123 ) );
	}

	public function test_save_overrides_stores_empty_string_as_null_and_ignores_unknown_keys(): void {
		// Row exists already.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'FIND_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'FIND_SQL', ARRAY_A )->andReturn( array( 'id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_taseo_indexables',
				array(
					'title'           => 'Custom Title',
					'description'     => null,
					'schema_disabled' => 1,
				),
				array( 'id' => 7 )
			);

		$this->repository->save_overrides(
			'post',
			'product',
			88123,
			array(
				'title'           => 'Custom Title',
				'description'     => '',
				'schema_disabled' => 1,
				'hack_column'     => 'ignored', // not in whitelist.
			)
		);
	}

	public function test_save_overrides_never_writes_null_for_schema_disabled(): void {
		// Row exists already.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'FIND_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'FIND_SQL', ARRAY_A )->andReturn( array( 'id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_taseo_indexables',
				array(
					'schema_disabled' => 0,
					'description'     => null,
				),
				array( 'id' => 7 )
			);

		$this->repository->save_overrides(
			'post',
			'product',
			88123,
			array(
				'schema_disabled' => '', // must coerce to 0, never NULL: column is NOT NULL.
				'description'     => '', // still clears to NULL for nullable columns.
			)
		);
	}

	public function test_save_overrides_creates_row_when_absent(): void {
		$this->wpdb->shouldReceive( 'prepare' )->times( 3 )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn( null, array( 'id' => '11' ) ); // absent, then present after upsert.
		$this->wpdb->shouldReceive( 'query' )->once(); // the upsert.
		$this->wpdb->shouldReceive( 'update' )->once();

		$this->repository->save_overrides( 'term', 'product_cat', 44, array( 'title' => 'Cat title' ) );
	}

	public function test_delete_removes_row(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with(
				'wp_taseo_indexables',
				array(
					'object_type'    => 'post',
					'object_subtype' => 'product',
					'object_id'      => 88123,
				)
			);

		$this->repository->delete( 'post', 'product', 88123 );
	}

	public function test_upsert_synced_fields_fires_synced_action(): void {
		// Twice: the upsert itself, then the stale-subtype probe.
		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once();

		Actions\expectDone( 'taseo_indexable_synced' )->once()->with( 'post', 'product', 88123 );

		$this->repository->upsert_synced_fields( 'post', 'product', 88123, array() );
	}

	public function test_delete_fires_deleting_action(): void {
		Actions\expectDone( 'taseo_indexable_deleting' )->once()->with( 'post', 'product', 88123 );

		$this->wpdb->shouldReceive( 'delete' )->once();

		$this->repository->delete( 'post', 'product', 88123 );
	}

	/**
	 * OVERRIDE_COLUMNS is an allowlist: a column missing from it is dropped
	 * on write with no error at all, so the column existing in the schema is
	 * not enough on its own.
	 */
	public function test_override_columns_allows_the_image_url_overrides(): void {
		$this->assertContains( 'og_image_url', IndexableRepository::OVERRIDE_COLUMNS );
		$this->assertContains( 'twitter_image_url', IndexableRepository::OVERRIDE_COLUMNS );
	}

	/**
	 * The unique key is (object_type, object_subtype, object_id), so a post
	 * that changes subtype does not update its old row — it inserts a second
	 * one. Without a purge the stale row keeps is_indexable = 1 and keeps its
	 * chunk slot, and the URL is published from two sitemaps at once.
	 *
	 * This is exactly what installing a subtype-declaring plugin over an
	 * existing catalogue does, on every product, at once.
	 */
	public function test_a_post_changing_subtype_purges_its_rows_under_other_subtypes(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'PURGE_SQL' );
		$this->wpdb->shouldReceive( 'query' );

		// Stale subtypes are discovered, then deleted through the normal
		// delete path so the chunk slot is released.
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( 'PURGE_SQL' )
			->andReturn( array( 'product' ) );

		Actions\expectDone( 'taseo_indexable_deleting' )->once()->with( 'post', 'product', 88123 );
		$this->wpdb->shouldReceive( 'delete' )->once();

		$this->repository->upsert_synced_fields(
			'post',
			'aucteeno_auction',
			88123,
			array(
				'permalink'     => 'https://example.com/auction/spring/',
				'is_indexable'  => true,
				'last_modified' => '2026-08-11 10:00:00',
			)
		);
	}

	public function test_a_post_that_kept_its_subtype_deletes_nothing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'PURGE_SQL' );
		$this->wpdb->shouldReceive( 'query' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->with( 'PURGE_SQL' )->andReturn( array() );

		$this->wpdb->shouldNotReceive( 'delete' );

		$this->repository->upsert_synced_fields(
			'post',
			'product',
			88123,
			array( 'permalink' => 'https://example.com/p/', 'is_indexable' => true )
		);
	}

	/**
	 * custom_page ids are provider-chosen and collide across families by
	 * design — vendor_store:42 and vendor_items:42 are the same vendor's two
	 * URLs. Purging by (object_type, object_id) there would have each push
	 * delete the other.
	 */
	public function test_custom_page_families_never_purge_each_other(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' );

		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->wpdb->shouldNotReceive( 'delete' );

		$this->repository->upsert_synced_fields(
			'custom_page',
			'vendor_items',
			42,
			array( 'permalink' => 'https://example.com/store/acme/items/', 'is_indexable' => true )
		);
	}
}
