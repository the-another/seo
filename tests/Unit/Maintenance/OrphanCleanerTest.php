<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Maintenance;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;
use TheAnother\Plugin\SEO\Maintenance\OrphanCleaner;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( OrphanCleaner::class )]
class OrphanCleanerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private $repository;
	private $subtypes;
	private $families;
	private $files;
	private $storage;
	private $settings;
	private OrphanCleaner $cleaner;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $sql ): string => $sql
		)->byDefault();

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->subtypes   = Mockery::mock( PostSubtypes::class );
		$this->families   = Mockery::mock( SitemapFamilies::class );
		$this->files      = Mockery::mock( SitemapFileRepository::class );
		$this->storage    = Mockery::mock( SitemapStorage::class );
		$this->settings   = Mockery::mock( Settings::class );

		// Both table scans suspend cache addition for their duration so a
		// per-row get_post() cannot grow the object cache's runtime array
		// without limit over a 121k-row table.
		Monkey\Functions\when( 'wp_suspend_cache_addition' )->justReturn( false );

		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category' ) )->byDefault();
		$this->families->shouldReceive( 'all' )->andReturn( array( 'vendor_store' => 'Stores' ) )->byDefault();
		$this->subtypes->shouldReceive( 'all' )->andReturn( array( 'product' => array( 'aucteeno_item' => 'Item' ) ) )->byDefault();
		$this->subtypes->shouldReceive( 'post_type_for' )->andReturnUsing(
			static fn( string $subtype ): string => 'aucteeno_item' === $subtype ? 'product' : $subtype
		)->byDefault();

		$this->cleaner = new OrphanCleaner(
			$this->repository,
			$this->subtypes,
			$this->families,
			$this->files,
			$this->storage,
			$this->settings
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function stub_row_scan( array $rows ): void {
		$this->stub_row_scan_batches( array( $rows ) );
	}

	/**
	 * Serve the row scan one batch per iteration, then run dry.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $batches Successive batches.
	 * @param array<int, string>                           $order   Category call order, appended to by reference.
	 */
	private function stub_row_scan_batches( array $batches, ?array &$order = null ): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->with( Mockery::on( static fn( string $sql ): bool => str_contains( $sql, 'SELECT id, object_type' ) ), ARRAY_A )
			->andReturnUsing(
				static function () use ( &$batches, &$order ): array {
					if ( null !== $order ) {
						$order[] = 'rows';
					}

					return array_shift( $batches ) ?? array();
				}
			);
	}

	/**
	 * Serve the duplicate scan one batch per iteration, then run dry.
	 *
	 * @param array<int, array<int, int>> $batches Successive batches.
	 * @param array<int, string>          $order   Category call order, appended to by reference.
	 */
	private function stub_duplicate_scan_batches( array $batches, ?array &$order = null ): void {
		$this->wpdb->shouldReceive( 'get_col' )
			->with( Mockery::on( static fn( string $sql ): bool => str_contains( $sql, 'COUNT(DISTINCT object_subtype) > 1' ) ) )
			->andReturnUsing(
				static function () use ( &$batches, &$order ): array {
					if ( null !== $order ) {
						$order[] = 'duplicates';
					}

					return array_shift( $batches ) ?? array();
				}
			);
	}

	/**
	 * Capture the arguments each $wpdb->prepare() call binds.
	 *
	 * The default stub throws them away, which is exactly why the ID cursor
	 * — a Global Constraint of this class — had no coverage at all.
	 *
	 * @param array<int, array<int, mixed>> $captured Bindings, appended to by reference.
	 */
	private function capture_prepared_bindings( array &$captured ): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ) use ( &$captured ): string {
				$captured[] = $args;

				return $sql;
			}
		);
	}

	public function test_deletes_rows_whose_post_is_gone(): void {
		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'post', 'object_subtype' => 'post', 'object_id' => 10 ),
				array( 'id' => 2, 'object_type' => 'post', 'object_subtype' => 'post', 'object_id' => 11 ),
			)
		);

		Monkey\Functions\when( 'get_post' )->alias( static fn( int $id ) => 10 === $id ? null : (object) array( 'ID' => $id ) );

		$this->repository->shouldReceive( 'delete' )->once()->with( 'post', 'post', 10 );

		$result = $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS );

		$this->assertSame( 1, $result['rows'] );
	}

	public function test_keeps_subtype_rows_whose_owning_post_type_is_enabled(): void {
		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'post', 'object_subtype' => 'aucteeno_item', 'object_id' => 10 ),
			)
		);

		Monkey\Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 10 ) );

		$this->repository->shouldNotReceive( 'delete' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_never_deletes_template_rows_or_system_pages(): void {
		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'custom_page', 'object_subtype' => 'gone_family', 'object_id' => 0 ),
				array( 'id' => 2, 'object_type' => 'system_page', 'object_subtype' => 'search', 'object_id' => 0 ),
			)
		);

		$this->repository->shouldNotReceive( 'delete' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_deletes_pushed_rows_for_unregistered_families(): void {
		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'custom_page', 'object_subtype' => 'gone_family', 'object_id' => 42 ),
				array( 'id' => 2, 'object_type' => 'custom_page', 'object_subtype' => 'vendor_store', 'object_id' => 42 ),
			)
		);

		$this->families->shouldReceive( 'has' )->with( 'gone_family' )->andReturn( false );
		$this->families->shouldReceive( 'has' )->with( 'vendor_store' )->andReturn( true );

		$this->repository->shouldReceive( 'delete' )->once()->with( 'custom_page', 'gone_family', 42 );

		$this->assertSame( 1, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_dry_run_counts_without_deleting(): void {
		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'post', 'object_subtype' => 'post', 'object_id' => 10 ),
			)
		);

		Monkey\Functions\when( 'get_post' )->justReturn( null );

		$this->repository->shouldNotReceive( 'delete' );

		$this->assertSame( 1, $this->cleaner->clean( true, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_suspends_cache_addition_for_the_length_of_the_row_scan(): void {
		$calls = array();
		Monkey\Functions\when( 'wp_suspend_cache_addition' )->alias(
			static function ( bool $suspend ) use ( &$calls ): bool {
				$calls[] = $suspend;

				return $suspend;
			}
		);

		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'post', 'object_subtype' => 'post', 'object_id' => 10 ),
			)
		);

		Monkey\Functions\when( 'get_post' )->justReturn( null );
		$this->repository->shouldReceive( 'delete' );

		$this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS );

		// Without this every get_post() miss wp_cache_add()s a full WP_Post
		// into a runtime array that is never trimmed, so a 121k-row scan
		// holds 121k post bodies in memory at once.
		$this->assertSame( array( true, false ), $calls );
	}

	public function test_restores_cache_addition_when_the_scan_throws(): void {
		$calls = array();
		Monkey\Functions\when( 'wp_suspend_cache_addition' )->alias(
			static function ( bool $suspend ) use ( &$calls ): bool {
				$calls[] = $suspend;

				return $suspend;
			}
		);

		$this->wpdb->shouldReceive( 'get_results' )->andThrow( new RuntimeException( 'server has gone away' ) );

		try {
			$this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS );
			$this->fail( 'Expected the scan failure to propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'server has gone away', $e->getMessage() );
		}

		// A leaked suspension would silently disable cache writes for the
		// rest of a long-lived CLI process.
		$this->assertSame( array( true, false ), $calls );
	}

	public function test_resolves_each_distinct_subtype_once_rather_than_once_per_row(): void {
		$this->stub_row_scan(
			array(
				array( 'id' => 1, 'object_type' => 'post', 'object_subtype' => 'aucteeno_item', 'object_id' => 10 ),
				array( 'id' => 2, 'object_type' => 'post', 'object_subtype' => 'aucteeno_item', 'object_id' => 11 ),
				array( 'id' => 3, 'object_type' => 'post', 'object_subtype' => 'aucteeno_item', 'object_id' => 12 ),
				array( 'id' => 4, 'object_type' => 'post', 'object_subtype' => 'post', 'object_id' => 13 ),
			)
		);

		Monkey\Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 1 ) );

		// post_type_for() calls all(), which re-runs the taseo_post_subtypes
		// filter plus get_post_types() and get_taxonomies(). Once per row
		// would be a full registry walk per row on the site this exists for.
		$this->subtypes->shouldReceive( 'post_type_for' )->once()->with( 'aucteeno_item' )->andReturn( 'product' );
		$this->subtypes->shouldReceive( 'post_type_for' )->once()->with( 'post' )->andReturn( 'post' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_aborts_when_no_families_are_registered_but_pushed_rows_exist(): void {
		$this->families->shouldReceive( 'all' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1200' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/1200/' );

		$this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS );
	}

	public function test_does_not_abort_when_no_families_and_no_pushed_rows(): void {
		$this->families->shouldReceive( 'all' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->stub_row_scan( array() );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	/**
	 * Registered post types and taxonomies, as the subtype guard reads them.
	 *
	 * @return void
	 */
	private function stub_registries(): void {
		Monkey\Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post', 'page' => 'page' ) );
		Monkey\Functions\when( 'get_taxonomies' )->justReturn( array( 'category' => 'category' ) );
	}

	public function test_aborts_when_no_subtypes_are_registered_but_undeclarable_rows_exist(): void {
		// WooCommerce (or whatever declares aucteeno_item) is deactivated, so
		// PostSubtypes::all() is empty and post_type_for() hands every such
		// row back its own key — which is not an enabled post type, so every
		// one of them reads as an orphan.
		$this->subtypes->shouldReceive( 'all' )->andReturn( array() );
		$this->stub_registries();
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '121000' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/121000/' );
		$this->expectExceptionMessageMatches( '/inactive/' );

		$this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS );
	}

	public function test_does_not_abort_when_the_subtype_registry_is_populated(): void {
		// Non-empty registry: the declaring plugin is present, so subtype rows
		// resolve normally and there is nothing to protect them from.
		$this->wpdb->shouldNotReceive( 'get_var' );
		$this->stub_row_scan( array() );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_does_not_abort_when_no_undeclarable_subtype_rows_exist(): void {
		// Empty registry on a site that never split anything: every post row
		// carries a real post type, so nothing is unexplained.
		$this->subtypes->shouldReceive( 'all' )->andReturn( array() );
		$this->stub_registries();
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->stub_row_scan( array() );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS )['rows'] );
	}

	public function test_counts_only_subtypes_that_are_neither_a_post_type_nor_a_taxonomy(): void {
		$this->subtypes->shouldReceive( 'all' )->andReturn( array() );
		$this->stub_registries();

		$bindings = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, $args = array() ) use ( &$bindings ): string {
				$bindings[] = $args;

				return $sql;
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->stub_row_scan( array() );

		$this->cleaner->clean( false, OrphanCleaner::ONLY_ROWS );

		$this->assertSame( array( 'post', 'page', 'category' ), $bindings[0] );
	}

	/**
	 * @param array<int, int> $ids
	 */
	private function stub_duplicate_scan( array $ids ): void {
		$this->stub_duplicate_scan_batches( array( $ids ) );
	}

	public function test_purges_duplicate_subtype_rows_keeping_the_resolved_subtype(): void {
		$this->stub_duplicate_scan( array( 77 ) );

		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = 77;
		$post->post_type = 'product';
		Monkey\Functions\when( 'get_post' )->justReturn( $post );

		$this->subtypes->shouldReceive( 'resolve' )->once()->with( $post )->andReturn( 'aucteeno_item' );
		$this->repository->shouldReceive( 'purge_stale_subtypes' )->once()->with( 'post', 'aucteeno_item', 77 );

		$this->assertSame( 1, $this->cleaner->clean( false, OrphanCleaner::ONLY_DUPLICATES )['duplicates'] );
	}

	public function test_skips_duplicates_whose_post_is_gone(): void {
		$this->stub_duplicate_scan( array( 77 ) );

		Monkey\Functions\when( 'get_post' )->justReturn( null );

		$this->repository->shouldNotReceive( 'purge_stale_subtypes' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_DUPLICATES )['duplicates'] );
	}

	public function test_dry_run_counts_duplicates_without_purging(): void {
		$this->stub_duplicate_scan( array( 77 ) );

		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = 77;
		$post->post_type = 'product';
		Monkey\Functions\when( 'get_post' )->justReturn( $post );

		$this->subtypes->shouldReceive( 'resolve' )->andReturn( 'aucteeno_item' );
		$this->repository->shouldNotReceive( 'purge_stale_subtypes' );

		$this->assertSame( 1, $this->cleaner->clean( true, OrphanCleaner::ONLY_DUPLICATES )['duplicates'] );
	}

	/**
	 * The real is_listable(), so these tests exercise the shared predicate
	 * rather than a mock that could agree with a broken one.
	 *
	 * @return void
	 */
	private function use_real_listable_predicate(): void {
		$this->files->shouldReceive( 'is_listable' )->andReturnUsing(
			static fn( ?array $chunk ): bool => ( new SitemapFileRepository() )->is_listable( $chunk )
		);
	}

	public function test_deletes_files_with_no_live_chunk_behind_them(): void {
		$this->use_real_listable_predicate();
		$this->storage->shouldReceive( 'modified_time' )->andReturn( time() - OrphanCleaner::MIN_FILE_AGE - 1 );
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn(
			array(
				'gone-sitemap-1.xml',      // no registry row.
				'tombstoned-sitemap-2.xml', // link_count 0.
				'suspended-sitemap-3.xml',  // generated_at null.
				'live-sitemap-4.xml',       // keep.
				'notes.txt',                // unparseable, keep.
			)
		);

		$this->storage->shouldReceive( 'parse_file_name' )->andReturnUsing(
			static function ( string $name ): ?array {
				if ( 1 !== preg_match( '/^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$/', $name, $m ) ) {
					return null;
				}

				return array( 'object_subtype' => $m[1], 'chunk_number' => (int) $m[2] );
			}
		);

		$this->files->shouldReceive( 'get_by_subtype_and_number' )->with( 'gone', 1 )->andReturn( null );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->with( 'tombstoned', 2 )
			->andReturn( array( 'link_count' => 0, 'generated_at' => '2026-08-01 00:00:00' ) );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->with( 'suspended', 3 )
			->andReturn( array( 'link_count' => 12, 'generated_at' => null ) );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->with( 'live', 4 )
			->andReturn( array( 'link_count' => 12, 'generated_at' => '2026-08-01 00:00:00' ) );

		$this->storage->shouldReceive( 'delete' )->once()->with( array( 'object_subtype' => 'gone', 'chunk_number' => 1 ) );
		$this->storage->shouldReceive( 'delete' )->once()->with( array( 'object_subtype' => 'tombstoned', 'chunk_number' => 2 ) );
		$this->storage->shouldReceive( 'delete' )->once()->with( array( 'object_subtype' => 'suspended', 'chunk_number' => 3 ) );

		$this->assertSame( 3, $this->cleaner->clean( false, OrphanCleaner::ONLY_FILES )['files'] );
	}

	public function test_dry_run_counts_files_without_deleting(): void {
		$this->use_real_listable_predicate();
		$this->storage->shouldReceive( 'modified_time' )->andReturn( time() - OrphanCleaner::MIN_FILE_AGE - 1 );
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array( 'gone-sitemap-1.xml' ) );
		$this->storage->shouldReceive( 'parse_file_name' )->andReturn( array( 'object_subtype' => 'gone', 'chunk_number' => 1 ) );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->andReturn( null );
		$this->storage->shouldNotReceive( 'delete' );

		$this->assertSame( 1, $this->cleaner->clean( true, OrphanCleaner::ONLY_FILES )['files'] );
	}

	public function test_keeps_a_file_written_inside_the_grace_period(): void {
		// SitemapFileWriter::rebuild() writes the file, then stamps
		// generated_at. A cleanup landing in that window sees an unstamped
		// row behind a live file — indistinguishable from a suspended
		// family's leftover — and deleting it would 404 the URL forever,
		// because the row is stamped clean straight afterwards and nothing
		// would ever mark it dirty again.
		$this->use_real_listable_predicate();
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array( 'rebuilding-sitemap-1.xml' ) );
		$this->storage->shouldReceive( 'parse_file_name' )->andReturn( array( 'object_subtype' => 'rebuilding', 'chunk_number' => 1 ) );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->andReturn( array( 'link_count' => 12, 'generated_at' => null ) );
		$this->storage->shouldReceive( 'modified_time' )->once()->andReturn( time() - 5 );
		$this->storage->shouldNotReceive( 'delete' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_FILES )['files'] );
	}

	public function test_keeps_a_file_whose_modification_time_is_unreadable(): void {
		$this->use_real_listable_predicate();
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array( 'gone-sitemap-1.xml' ) );
		$this->storage->shouldReceive( 'parse_file_name' )->andReturn( array( 'object_subtype' => 'gone', 'chunk_number' => 1 ) );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->andReturn( null );
		$this->storage->shouldReceive( 'modified_time' )->once()->andReturn( null );
		$this->storage->shouldNotReceive( 'delete' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_FILES )['files'] );
	}

	public function test_asks_the_repository_whether_a_chunk_is_live(): void {
		// The predicate has one owner: the root index lists exactly the
		// chunks it returns true for, and this keeps exactly their files.
		// A local re-statement here is what let the two drift apart.
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array( 'live-sitemap-4.xml' ) );
		$this->storage->shouldReceive( 'parse_file_name' )->andReturn( array( 'object_subtype' => 'live', 'chunk_number' => 4 ) );

		$row = array( 'link_count' => 12, 'generated_at' => '2026-08-01 00:00:00' );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->andReturn( $row );
		$this->files->shouldReceive( 'is_listable' )->once()->with( $row )->andReturn( true );

		$this->storage->shouldNotReceive( 'delete' );

		$this->assertSame( 0, $this->cleaner->clean( false, OrphanCleaner::ONLY_FILES )['files'] );
	}

	public function test_reports_a_skip_when_stream_wrapped_storage_cannot_be_listed(): void {
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array() );
		$this->storage->shouldReceive( 'is_stream_wrapped' )->once()->andReturn( true );

		$result = $this->cleaner->clean( false, OrphanCleaner::ONLY_FILES );

		$this->assertSame( 0, $result['files'] );
		$this->assertCount( 1, $result['skipped'] );
		$this->assertStringContainsString( 'stream-wrapped', $result['skipped'][0] );
	}

	public function test_reports_no_skip_for_a_genuinely_empty_directory(): void {
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array() );
		$this->storage->shouldReceive( 'is_stream_wrapped' )->once()->andReturn( false );

		$this->assertSame( array(), $this->cleaner->clean( false, OrphanCleaner::ONLY_FILES )['skipped'] );
	}

	public function test_the_default_run_covers_all_three_categories_rows_first(): void {
		// `wp taseo cleanup` with no arguments takes this path, and every
		// other test in this file passes an explicit category — so dropping
		// the `null === $only` disjunct from any branch would leave the whole
		// suite green while the default invocation deleted nothing.
		$order = array();

		$this->stub_row_scan_batches(
			array(
				array( array( 'id' => 1, 'object_type' => 'post', 'object_subtype' => 'post', 'object_id' => 10 ) ),
			),
			$order
		);
		$this->stub_duplicate_scan_batches( array( array( 77 ) ), $order );

		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = 77;
		$post->post_type = 'product';

		// Post 10 is gone so the row pass removes it; post 77 survives so the
		// duplicate pass has something to collapse.
		Monkey\Functions\when( 'get_post' )->alias(
			static fn( int $id ) => 10 === $id ? null : $post
		);

		$this->repository->shouldReceive( 'delete' )->once()->with( 'post', 'post', 10 );
		$this->subtypes->shouldReceive( 'resolve' )->once()->with( $post )->andReturn( 'aucteeno_item' );
		$this->repository->shouldReceive( 'purge_stale_subtypes' )->once()->with( 'post', 'aucteeno_item', 77 );

		$this->use_real_listable_predicate();
		$this->storage->shouldReceive( 'list_files' )->once()->andReturn( array( 'gone-sitemap-1.xml' ) );
		$this->storage->shouldReceive( 'parse_file_name' )->andReturn( array( 'object_subtype' => 'gone', 'chunk_number' => 1 ) );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->andReturn( null );
		$this->storage->shouldReceive( 'modified_time' )->andReturn( time() - OrphanCleaner::MIN_FILE_AGE - 1 );
		$this->storage->shouldReceive( 'delete' )->once();

		$result = $this->cleaner->clean( false, null );

		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 1, $result['duplicates'] );
		$this->assertSame( 1, $result['files'] );

		// Rows before duplicates, documented and load-bearing:
		// clean_duplicates() skips objects whose post is gone because
		// clean_rows() has already handled them.
		$this->assertSame( array( 'rows', 'duplicates' ), $order );
	}

	public function test_the_row_scan_resumes_from_the_last_id_of_the_previous_batch(): void {
		// "Batch by ascending ID cursor, never OFFSET" is a Global Constraint
		// of this class. Every other stub returns a short batch, so the loop
		// exits after one pass and never advances the cursor at all; drop
		// `$last_id = (int) $row['id']` and production re-reads batch one
		// forever on any table with BATCH_SIZE or more rows.
		$full = array();

		for ( $i = 1; $i <= OrphanCleaner::BATCH_SIZE; $i++ ) {
			// system_page rows are never orphans, so the scan walks the whole
			// batch without needing a get_post() stub per row.
			$full[] = array( 'id' => $i * 2, 'object_type' => 'system_page', 'object_subtype' => 'search', 'object_id' => 0 );
		}

		$short = array(
			array( 'id' => 9001, 'object_type' => 'system_page', 'object_subtype' => 'search', 'object_id' => 0 ),
		);

		$bindings = array();
		$this->capture_prepared_bindings( $bindings );
		$this->stub_row_scan_batches( array( $full, $short ) );

		$this->cleaner->clean( true, OrphanCleaner::ONLY_ROWS );

		$this->assertCount( 2, $bindings );
		$this->assertSame( array( 0, OrphanCleaner::BATCH_SIZE ), $bindings[0] );
		$this->assertSame( array( OrphanCleaner::BATCH_SIZE * 2, OrphanCleaner::BATCH_SIZE ), $bindings[1] );
	}

	public function test_the_duplicate_scan_resumes_from_the_last_id_of_the_previous_batch(): void {
		$full = array();

		for ( $i = 1; $i <= OrphanCleaner::BATCH_SIZE; $i++ ) {
			$full[] = $i * 2;
		}

		$bindings = array();
		$this->capture_prepared_bindings( $bindings );
		$this->stub_duplicate_scan_batches( array( $full, array( 9001 ) ) );

		// Every post gone: the cursor must still advance, since it is set
		// before the existence check precisely so a batch of dead IDs cannot
		// stall the scan.
		Monkey\Functions\when( 'get_post' )->justReturn( null );

		$this->cleaner->clean( true, OrphanCleaner::ONLY_DUPLICATES );

		$this->assertCount( 2, $bindings );
		$this->assertSame( array( 0, OrphanCleaner::BATCH_SIZE ), $bindings[0] );
		$this->assertSame( array( OrphanCleaner::BATCH_SIZE * 2, OrphanCleaner::BATCH_SIZE ), $bindings[1] );
	}
}
