<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Sitemap\ExternalUrls;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( ExternalUrls::class )]
class ExternalUrlsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $families;
	private $repository;
	private $files;
	private $storage;
	private ExternalUrls $external;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg();

		$this->families   = Mockery::mock( SitemapFamilies::class );
		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->files      = Mockery::mock( SitemapFileRepository::class );
		$this->storage    = Mockery::mock( SitemapStorage::class );

		$this->external = new ExternalUrls( $this->families, $this->repository, $this->files, $this->storage );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sync_url_upserts_a_valid_push(): void {
		$this->families->shouldReceive( 'has' )->with( 'vendor_store' )->andReturn( true );
		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'custom_page',
				'vendor_store',
				501,
				array(
					'permalink'     => 'https://example.com/store/acme/',
					'is_indexable'  => true,
					'images'        => array( 'https://example.com/logo.jpg' ),
					'last_modified' => '2026-08-01 12:00:00',
				)
			);

		$this->assertTrue(
			$this->external->sync_url(
				'vendor_store',
				501,
				array(
					'permalink'     => 'https://example.com/store/acme/',
					'images'        => array( 'https://example.com/logo.jpg' ),
					'last_modified' => '2026-08-01 12:00:00',
				)
			)
		);
	}

	public function test_sync_url_rejects_unregistered_family(): void {
		$this->families->shouldReceive( 'has' )->with( 'nope' )->andReturn( false );
		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->assertFalse( $this->external->sync_url( 'nope', 1, array( 'permalink' => 'https://example.com/x/' ) ) );
	}

	public function test_sync_url_rejects_id_zero(): void {
		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->assertFalse( $this->external->sync_url( 'vendor_store', 0, array( 'permalink' => 'https://example.com/x/' ) ) );
	}

	public function test_sync_url_rejects_foreign_host_and_relative_permalinks(): void {
		$this->families->shouldReceive( 'has' )->andReturn( true );
		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->assertFalse( $this->external->sync_url( 'vendor_store', 1, array( 'permalink' => 'https://evil.com/x/' ) ) );
		$this->assertFalse( $this->external->sync_url( 'vendor_store', 1, array( 'permalink' => '/relative/' ) ) );
		$this->assertFalse( $this->external->sync_url( 'vendor_store', 1, array() ) );
	}

	public function test_sync_url_defaults_is_indexable_true_and_respects_false(): void {
		$this->families->shouldReceive( 'has' )->andReturn( true );
		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with( 'custom_page', 'vendor_store', 1, Mockery::on( fn( array $f ): bool => false === $f['is_indexable'] ) );

		$this->external->sync_url( 'vendor_store', 1, array( 'permalink' => 'https://example.com/x/', 'is_indexable' => false ) );
	}

	public function test_delete_url_delegates_to_repository(): void {
		$this->repository->shouldReceive( 'delete' )->once()->with( 'custom_page', 'vendor_store', 501 );

		$this->external->delete_url( 'vendor_store', 501 );
	}

	public function test_delete_family_clears_pointers_chunks_files_and_rows(): void {
		global $wpdb;

		$chunk = array( 'id' => '1', 'object_subtype' => 'vendor_store', 'chunk_number' => '1' );

		$this->files->shouldReceive( 'get_chunks_for_subtype' )->once()->with( 'vendor_store' )->andReturn( array( $chunk ) );

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'SET sitemap_file_id = NULL' ) ), 'vendor_store' )
			->andReturn( 'CLEAR_SQL' );
		$wpdb->shouldReceive( 'query' )->once()->with( 'CLEAR_SQL' )->ordered();

		$this->files->shouldReceive( 'delete_chunks_for_subtype' )->once()->with( 'vendor_store' );
		$this->storage->shouldReceive( 'delete' )->once()->with( $chunk );

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'DELETE FROM wp_taseo_indexables' ) ), 'vendor_store' )
			->andReturn( 'DELETE_SQL' );
		$wpdb->shouldReceive( 'query' )->once()->with( 'DELETE_SQL' )->ordered();

		// Registration NOT required: no $this->families->has() expectation.
		$this->external->delete_family( 'vendor_store' );
	}
}
