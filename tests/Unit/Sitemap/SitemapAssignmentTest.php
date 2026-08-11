<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapAssignment;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( SitemapAssignment::class )]
class SitemapAssignmentTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private $files;
	private $storage;
	private $settings;
	private SitemapAssignment $assignment;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->storage  = Mockery::mock( SitemapStorage::class );
		$this->settings = Mockery::mock( Settings::class );

		// The inclusion toggle now gates post and term subtypes too, not just
		// families. Tests exercising the toggle itself override this.
		$this->settings->shouldReceive( 'is_sitemap_family_enabled' )->andReturn( true )->byDefault();

		$this->assignment = new SitemapAssignment( $this->files, $this->storage, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the indexable-row lookup handle_* methods start with.
	 */
	private function stub_indexable_row( ?array $row, string $object_type = 'post', string $object_subtype = 'product', int $object_id = 88123 ): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'SELECT id, is_indexable, sitemap_file_id' )
						&& str_contains( $sql, 'FROM wp_taseo_indexables' )
				),
				$object_type,
				$object_subtype,
				$object_id
			)
			->andReturn( 'FIND_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'FIND_SQL', ARRAY_A )->andReturn( $row );
	}

	public function test_new_indexable_claims_lowest_open_chunk(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->once()->with( 'product', 1000 )->andReturn( array( 'id' => '3' ) );
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 3, 1000 )->andReturn( true );
		$this->files->shouldNotReceive( 'create_chunk' );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 3 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_opens_new_chunk_when_every_existing_chunk_is_full(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->once()->andReturn( null );
		$this->files->shouldReceive( 'create_chunk' )->once()->with( 'product' )->andReturn( array( 'id' => '8' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 8 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_lost_slot_race_reruns_the_search(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )
			->twice()
			->andReturn( array( 'id' => '3' ), array( 'id' => '4' ) );
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 3, 1000 )->andReturn( false ); // lost the last slot.
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 4, 1000 )->andReturn( true );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 4 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_lost_create_race_reruns_the_search(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )
			->twice()
			->andReturn( null, array( 'id' => '5' ) ); // second pass sees the race winner's chunk.
		$this->files->shouldReceive( 'create_chunk' )->once()->andReturn( null ); // unique-key race lost.
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 5, 1000 )->andReturn( true );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 5 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_retry_exhaustion_gives_up_without_assignment(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->times( 5 )->andReturn( array( 'id' => '3' ) );
		$this->files->shouldReceive( 'claim_slot' )->times( 5 )->andReturn( false );

		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_edit_to_assigned_object_only_marks_chunk_dirty(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => '7' ) );

		$this->files->shouldReceive( 'mark_dirty' )->once()->with( 7 );
		$this->files->shouldNotReceive( 'find_lowest_open_chunk' );
		$this->files->shouldNotReceive( 'claim_slot' );

		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_becoming_non_indexable_releases_slot(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => null ), array( 'id' => 9 ) );
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 412 );
		$this->files->shouldNotReceive( 'tombstone_chunk' );
		$this->storage->shouldNotReceive( 'delete' );

		// Releases are never gated on the enabled toggle — counters must stay true.
		$this->settings->shouldNotReceive( 'is_sitemap_enabled' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_chunk_tombstoned_and_file_deleted_at_zero_links(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '7' );

		$this->wpdb->shouldReceive( 'update' )->once();
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 0 );
		$this->files->shouldReceive( 'get' )->once()->with( 7 )->andReturn( $chunk );
		$this->files->shouldReceive( 'tombstone_chunk' )->once()->with( 7 )->andReturn( true );
		$this->storage->shouldReceive( 'delete' )->once()->with( $chunk );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_reclaimed_chunk_is_not_tombstoned_or_unlinked(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '7' );

		$this->wpdb->shouldReceive( 'update' )->once();
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 0 );
		$this->files->shouldReceive( 'get' )->once()->with( 7 )->andReturn( $chunk );
		$this->files->shouldReceive( 'tombstone_chunk' )->once()->with( 7 )->andReturn( false );
		$this->storage->shouldNotReceive( 'delete' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_non_indexable_without_assignment_is_a_noop(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => null ) );

		$this->files->shouldNotReceive( 'release_slot' );
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_system_pages_never_participate(): void {
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assignment->handle_indexable_synced( 'system_page', 'home', 0 );
		$this->assignment->handle_indexable_deleting( 'system_page', 'search', 0 );
	}

	public function test_disabled_sitemap_skips_new_assignment(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldNotReceive( 'find_lowest_open_chunk' );
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_disabled_sitemap_still_marks_assigned_chunk_dirty_on_edit(): void {
		// Intentionally no shouldReceive() for is_sitemap_enabled(): the
		// mark-dirty-on-edit path must not even consult the toggle. If the
		// code calls it anyway, Mockery errors on the unstubbed call, which
		// fails this test — a stricter check than merely asserting a return
		// value.
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => '7' ) );

		$this->files->shouldReceive( 'mark_dirty' )->once()->with( 7 );

		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_deleting_handler_releases_while_pointer_still_readable(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => null ), array( 'id' => 9 ) );
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 412 );

		$this->assignment->handle_indexable_deleting( 'post', 'product', 88123 );
	}

	public function test_custom_page_rows_are_assigned_when_family_enabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'is_sitemap_family_enabled' )->with( 'vendor_store' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ), 'custom_page', 'vendor_store', 501 );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->once()->with( 'vendor_store', 1000 )->andReturn( array( 'id' => '3' ) );
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 3, 1000 )->andReturn( true );
		$this->wpdb->shouldReceive( 'update' )->once();

		$this->assignment->handle_indexable_synced( 'custom_page', 'vendor_store', 501 );
	}

	public function test_disabled_family_blocks_new_assignment(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'is_sitemap_family_enabled' )->with( 'vendor_store' )->andReturn( false );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ), 'custom_page', 'vendor_store', 501 );

		$this->files->shouldNotReceive( 'find_lowest_open_chunk' );

		$this->assignment->handle_indexable_synced( 'custom_page', 'vendor_store', 501 );
	}

	public function test_disabled_family_still_releases_on_unindexable(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '3' ), 'custom_page', 'vendor_store', 501 );

		$this->wpdb->shouldReceive( 'update' )->once(); // pointer cleared
		$this->files->shouldReceive( 'release_slot' )->once()->with( 3 )->andReturn( 5 );

		$this->assignment->handle_indexable_synced( 'custom_page', 'vendor_store', 501 );
	}

	public function test_system_page_rows_are_still_ignored(): void {
		// No row lookup, no chunk work.
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assignment->handle_indexable_synced( 'system_page', 'search', 0 );
		$this->assignment->handle_indexable_deleting( 'system_page', 'search', 0 );
	}

	public function test_family_disabled_deletes_files_and_suspends_chunks(): void {
		$chunk_a = array( 'id' => '1', 'object_subtype' => 'vendor_store', 'chunk_number' => '1' );
		$chunk_b = array( 'id' => '2', 'object_subtype' => 'vendor_store', 'chunk_number' => '2' );

		$this->files->shouldReceive( 'get_chunks_for_subtype' )->once()->with( 'vendor_store' )->andReturn( array( $chunk_a, $chunk_b ) );
		$this->storage->shouldReceive( 'delete' )->once()->with( $chunk_a );
		$this->storage->shouldReceive( 'delete' )->once()->with( $chunk_b );
		$this->files->shouldReceive( 'suspend_subtype_chunks' )->once()->with( 'vendor_store' );

		$this->assignment->handle_family_disabled( 'vendor_store' );
	}

	public function test_family_enabled_enqueues_sweep_and_assign_jobs(): void {
		$enqueued = array();
		Monkey\Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args = array(), string $group = '' ) use ( &$enqueued ): int {
				$enqueued[] = array( $hook, $args, $group );
				return 1;
			}
		);

		$this->assignment->handle_family_enabled( 'vendor_store' );

		$this->assertSame(
			array(
				array( 'taseo_sitemap_sweep', array(), 'taseo' ),
				array( 'taseo_sitemap_assign_family', array( 'family' => 'vendor_store' ), 'taseo' ),
			),
			$enqueued
		);
	}

	public function test_assign_family_batch_assigns_unassigned_rows_and_chains_on_full_batch(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'is_sitemap_family_enabled' )->with( 'vendor_store' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );

		// A full batch of 200 IDs.
		$ids = array();
		for ( $i = 1; $i <= 200; $i++ ) {
			$ids[] = array( 'id' => (string) $i );
		}

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'object_type IN (%s,%s,%s)' )
						&& str_contains( $sql, 'sitemap_file_id IS NULL' )
						&& str_contains( $sql, 'is_indexable = 1' )
				),
				array( 'post', 'term', 'custom_page', 'vendor_store', 200 )
			)
			->andReturn( 'BATCH_SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'BATCH_SQL', ARRAY_A )->andReturn( $ids );

		// Every row goes through the claim loop.
		$this->files->shouldReceive( 'find_lowest_open_chunk' )->times( 200 )->andReturn( array( 'id' => '3' ) );
		$this->files->shouldReceive( 'claim_slot' )->times( 200 )->andReturn( true );
		$this->wpdb->shouldReceive( 'update' )->times( 200 );

		$chained = false;
		Monkey\Functions\when( 'as_enqueue_async_action' )->alias(
			function () use ( &$chained ): int {
				$chained = true;
				return 1;
			}
		);

		$this->assignment->assign_family_batch( 'vendor_store' );

		$this->assertTrue( $chained );
	}

	public function test_assign_family_batch_noops_when_family_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'is_sitemap_family_enabled' )->with( 'vendor_store' )->andReturn( false );

		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assignment->assign_family_batch( 'vendor_store' );
	}

	public function test_assign_family_action_unwraps_legacy_array_args(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		// Reaching the enabled check without erroring proves the unwrap.
		$this->assignment->handle_assign_family_action( array( 'family' => 'vendor_store' ) );
		$this->assignment->handle_assign_family_action( 'vendor_store' );
		$this->addToAssertionCount( 1 );
	}
}
