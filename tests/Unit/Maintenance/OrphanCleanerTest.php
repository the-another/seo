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

		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category' ) )->byDefault();
		$this->families->shouldReceive( 'all' )->andReturn( array( 'vendor_store' => 'Stores' ) )->byDefault();
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
		$this->wpdb->shouldReceive( 'get_results' )
			->with( Mockery::on( static fn( string $sql ): bool => str_contains( $sql, 'SELECT id, object_type' ) ), ARRAY_A )
			->andReturn( $rows, array() );
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
}
