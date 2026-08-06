<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;

#[CoversClass( SitemapFileRepository::class )]
class SitemapFileRepositoryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private SitemapFileRepository $files;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->files = new SitemapFileRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_find_lowest_open_chunk_orders_by_chunk_number(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'FROM wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'link_count < %d' )
						&& str_contains( $sql, 'ORDER BY chunk_number ASC' )
						&& str_contains( $sql, 'LIMIT 1' )
				),
				'product',
				1000
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'SQL', ARRAY_A )->andReturn( array( 'id' => '3' ) );

		$this->assertSame( array( 'id' => '3' ), $this->files->find_lowest_open_chunk( 'product', 1000 ) );
	}

	public function test_find_lowest_open_chunk_returns_null_when_all_full(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( $this->files->find_lowest_open_chunk( 'product', 1000 ) );
	}

	public function test_claim_slot_is_a_single_conditional_update(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'link_count = link_count + 1' )
						&& str_contains( $sql, 'is_dirty = 1' )
						&& str_contains( $sql, 'AND link_count < %d' )
				),
				3,
				1000
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( 1 );

		$this->assertTrue( $this->files->claim_slot( 3, 1000 ) );
	}

	public function test_claim_slot_reports_lost_race(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$this->assertFalse( $this->files->claim_slot( 3, 1000 ) );
	}

	public function test_create_chunk_appends_next_number_with_first_slot_claimed(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'COALESCE(MAX(chunk_number), 0) + 1' ) ),
				'product'
			)
			->andReturn( 'MAX_SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'MAX_SQL' )->andReturn( '8' );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'INSERT INTO wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'VALUES (%s, %d, 1, 1)' )
				),
				'product',
				8
			)
			->andReturn( 'INSERT_SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'INSERT_SQL' )->andReturn( 1 );
		$this->wpdb->insert_id = 41;

		$this->wpdb->shouldReceive( 'prepare' )->once()->with( Mockery::type( 'string' ), 41 )->andReturn( 'GET_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->with( 'GET_SQL', ARRAY_A )
			->andReturn( array( 'id' => '41', 'chunk_number' => '8' ) );

		$this->assertSame( array( 'id' => '41', 'chunk_number' => '8' ), $this->files->create_chunk( 'product' ) );
	}

	public function test_create_chunk_returns_null_when_duplicate_key_race_lost(): void {
		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '8' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false ); // unique key violation.

		$this->assertNull( $this->files->create_chunk( 'product' ) );
	}

	public function test_release_slot_decrements_and_returns_remaining(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'link_count = link_count - 1' )
						&& str_contains( $sql, 'is_dirty = 1' )
						&& str_contains( $sql, 'AND link_count > 0' )
				),
				7
			)
			->andReturn( 'DEC_SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'DEC_SQL' )->andReturn( 1 );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'SELECT link_count' ) ), 7 )
			->andReturn( 'COUNT_SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'COUNT_SQL' )->andReturn( '0' );

		$this->assertSame( 0, $this->files->release_slot( 7 ) );
	}

	public function test_get_by_subtype_and_number_finds_the_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'FROM wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'object_subtype = %s' )
						&& str_contains( $sql, 'chunk_number = %d' )
				),
				'product',
				3
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'SQL', ARRAY_A )->andReturn( array( 'id' => '7', 'link_count' => '0' ) );

		$this->assertSame( array( 'id' => '7', 'link_count' => '0' ), $this->files->get_by_subtype_and_number( 'product', 3 ) );
	}

	public function test_get_by_subtype_and_number_returns_null_when_no_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'SQL', ARRAY_A )->andReturn( null );

		$this->assertNull( $this->files->get_by_subtype_and_number( 'product', 999 ) );
	}

	public function test_tombstone_chunk_clears_generated_at_and_dirty_when_still_zero_links(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'UPDATE wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'SET generated_at = NULL, is_dirty = 0' )
						&& str_contains( $sql, 'WHERE id = %d' )
						&& str_contains( $sql, 'AND link_count = 0' )
				),
				7
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( 1 );

		$this->assertTrue( $this->files->tombstone_chunk( 7 ) );
	}

	public function test_tombstone_chunk_loses_race_when_chunk_was_reclaimed(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( 0 );

		$this->assertFalse( $this->files->tombstone_chunk( 7 ) );
	}

	public function test_tombstone_subtype_chunks_zeroes_every_row_of_a_subtype(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'UPDATE wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'SET link_count = 0, generated_at = NULL, is_dirty = 0' )
						&& str_contains( $sql, 'WHERE object_subtype = %s' )
				),
				'vendor_store'
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' );

		$this->files->tombstone_subtype_chunks( 'vendor_store' );
	}

	public function test_mark_dirty_flags_one_chunk(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_sitemap_files', array( 'is_dirty' => 1 ), array( 'id' => 7 ) );

		$this->files->mark_dirty( 7 );
	}

	public function test_mark_all_dirty_flags_every_chunk(): void {
		$this->wpdb->shouldReceive( 'query' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'SET is_dirty = 1' ) && ! str_contains( $sql, 'WHERE' )
				)
			);

		$this->files->mark_all_dirty();
	}

	public function test_get_dirty_chunks_respects_limit(): void {
		$rows = array( array( 'id' => '1' ), array( 'id' => '2' ) );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'is_dirty = 1' ) && str_contains( $sql, 'LIMIT %d' ) ),
				20
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn( $rows );

		$this->assertSame( $rows, $this->files->get_dirty_chunks( 20 ) );
	}

	public function test_update_after_rebuild_clears_dirty_and_stamps_generation(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_taseo_sitemap_files',
				Mockery::on(
					fn( array $data ): bool => 0 === $data['is_dirty']
						&& is_string( $data['generated_at'] )
						&& '2026-07-02 10:00:00' === $data['last_modified']
				),
				array( 'id' => 7 )
			);

		$this->files->update_after_rebuild( 7, '2026-07-02 10:00:00' );
	}

	public function test_get_dirty_chunks_excludes_subtypes(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'is_dirty = 1' )
						&& str_contains( $sql, 'object_subtype NOT IN (%s,%s)' )
						&& str_contains( $sql, 'LIMIT %d' )
				),
				'vendor_store',
				'auctioneer_location',
				20
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn( array() );

		$this->files->get_dirty_chunks( 20, array( 'vendor_store', 'auctioneer_location' ) );
	}

	public function test_get_dirty_chunks_without_exclusions_keeps_simple_query(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'is_dirty = 1' ) && ! str_contains( $sql, 'NOT IN' )
				),
				20
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->files->get_dirty_chunks( 20 );
	}

	public function test_count_dirty_excludes_subtypes(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'NOT IN (%s)' ) ), array( 'vendor_store' ) )
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'SQL' )->andReturn( '2' );

		$this->assertSame( 2, $this->files->count_dirty( array( 'vendor_store' ) ) );
	}

	public function test_suspend_subtype_chunks_nulls_generated_at_and_marks_dirty(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'SET generated_at = NULL, is_dirty = 1' ) && str_contains( $sql, 'object_subtype = %s' ) ), 'vendor_store' )
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' );

		$this->files->suspend_subtype_chunks( 'vendor_store' );
	}

	public function test_get_chunks_for_subtype(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn( array( array( 'id' => '1' ) ) );

		$this->assertSame( array( array( 'id' => '1' ) ), $this->files->get_chunks_for_subtype( 'vendor_store' ) );
	}

	public function test_get_status_summary_shapes_per_subtype_counts(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'GROUP BY object_subtype' )
						&& str_contains( $sql, 'link_count > 0' )
				),
				ARRAY_A
			)
			->andReturn(
				array(
					array( 'object_subtype' => 'page', 'chunks' => '1', 'links' => '87' ),
					array( 'object_subtype' => 'product', 'chunks' => '4', 'links' => '3412' ),
				)
			);
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'is_dirty = 1' ) ) )
			->andReturn( '2' );
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'MAX(generated_at)' ) ) )
			->andReturn( '2026-07-03 09:00:00' );

		$summary = $this->files->get_status_summary();

		$this->assertSame( array( 'chunks' => 1, 'links' => 87 ), $summary['subtypes']['page'] );
		$this->assertSame( array( 'chunks' => 4, 'links' => 3412 ), $summary['subtypes']['product'] );
		$this->assertSame( 2, $summary['dirty'] );
		$this->assertSame( '2026-07-03 09:00:00', $summary['last_generated'] );
	}

	public function test_get_status_summary_forwards_excluded_subtypes_to_count_dirty(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'GROUP BY object_subtype' )
						&& str_contains( $sql, 'link_count > 0' )
				),
				ARRAY_A
			)
			->andReturn( array() );

		// count_dirty()'s excluded-subtypes branch: a NOT IN clause built from
		// the disabled families passed through, not the no-args simple query.
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'NOT IN (%s)' ) ),
				array( 'vendor_store' )
			)
			->andReturn( 'COUNT_SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'COUNT_SQL' )->andReturn( '1' );

		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'MAX(generated_at)' ) ) )
			->andReturn( null );

		$summary = $this->files->get_status_summary( array( 'vendor_store' ) );

		$this->assertSame( 1, $summary['dirty'] );
	}
}
