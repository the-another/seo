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

		$this->assignment = new SitemapAssignment( $this->files, $this->storage, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the indexable-row lookup handle_* methods start with.
	 */
	private function stub_indexable_row( ?array $row ): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'SELECT id, is_indexable, sitemap_file_id' )
						&& str_contains( $sql, 'FROM wp_taseo_indexables' )
				),
				'post',
				'product',
				88123
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
		$this->files->shouldNotReceive( 'delete_chunk' );
		$this->storage->shouldNotReceive( 'delete' );

		// Releases are never gated on the enabled toggle — counters must stay true.
		$this->settings->shouldNotReceive( 'is_sitemap_enabled' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_chunk_deleted_and_file_unlinked_at_zero_links(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '7' );

		$this->wpdb->shouldReceive( 'update' )->once();
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 0 );
		$this->files->shouldReceive( 'get' )->once()->with( 7 )->andReturn( $chunk );
		$this->files->shouldReceive( 'delete_chunk' )->once()->with( 7 )->andReturn( true );
		$this->storage->shouldReceive( 'delete' )->once()->with( $chunk );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_reclaimed_chunk_is_not_deleted_or_unlinked(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '7' );

		$this->wpdb->shouldReceive( 'update' )->once();
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 0 );
		$this->files->shouldReceive( 'get' )->once()->with( 7 )->andReturn( $chunk );
		$this->files->shouldReceive( 'delete_chunk' )->once()->with( 7 )->andReturn( false );
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
}
