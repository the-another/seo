<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;

#[CoversClass( SitemapFileWriter::class )]
class SitemapFileWriterTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private $files;
	private SitemapFileWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/uploads',
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => false,
			)
		);
		Functions\when( 'trailingslashit' )->alias( fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'esc_url' )->returnArg();

		$this->files  = Mockery::mock( SitemapFileRepository::class );
		$this->writer = new SitemapFileWriter( $this->files );
	}

	protected function tearDown(): void {
		global $wp_filesystem;
		$wp_filesystem = null;

		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_file_naming_follows_subtype_and_chunk_number(): void {
		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->assertSame( 'product-sitemap-3.xml', $this->writer->get_file_name( $chunk ) );
		$this->assertSame( '/tmp/uploads/taseo-sitemaps/product-sitemap-3.xml', $this->writer->get_file_path( $chunk ) );
	}

	public function test_format_lastmod_converts_gmt_datetime_to_w3c(): void {
		$this->assertSame( '2026-07-02T10:00:00+00:00', SitemapFileWriter::format_lastmod( '2026-07-02 10:00:00' ) );
		$this->assertNull( SitemapFileWriter::format_lastmod( null ) );
		$this->assertNull( SitemapFileWriter::format_lastmod( '' ) );
	}

	public function test_rebuild_writes_urlset_and_clears_dirty(): void {
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock();

		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'FROM wp_taseo_indexables' )
						&& str_contains( $sql, 'sitemap_file_id = %d' )
						&& str_contains( $sql, 'is_indexable = 1' )
						&& str_contains( $sql, 'ORDER BY id ASC' )
				),
				7
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn(
			array(
				array( 'permalink' => 'https://example.com/product/widget/', 'last_modified' => '2026-07-02 10:00:00' ),
				array( 'permalink' => 'https://example.com/product/gadget/', 'last_modified' => '2026-06-30 08:00:00' ),
				array( 'permalink' => '', 'last_modified' => '2026-07-01 00:00:00' ), // no permalink — skipped.
			)
		);

		$captured = null;
		$wp_filesystem->shouldReceive( 'put_contents' )
			->once()
			->with( '/tmp/uploads/taseo-sitemaps/product-sitemap-3.xml', Mockery::capture( $captured ), Mockery::any() )
			->andReturn( true );

		$this->files->shouldReceive( 'update_after_rebuild' )->once()->with( 7, '2026-07-02 10:00:00' );

		$this->assertTrue( $this->writer->rebuild( $chunk ) );

		$this->assertStringContainsString( '<?xml version="1.0" encoding="UTF-8"?>', $captured );
		$this->assertStringContainsString( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $captured );
		$this->assertStringContainsString( '<loc>https://example.com/product/widget/</loc>', $captured );
		$this->assertStringContainsString( '<lastmod>2026-07-02T10:00:00+00:00</lastmod>', $captured );
		$this->assertSame( 2, substr_count( $captured, '<url>' ) );
	}

	public function test_rebuild_max_modified_ignores_rows_skipped_from_render(): void {
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock();

		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn(
			array(
				array( 'permalink' => 'https://example.com/product/widget/', 'last_modified' => '2026-07-02 10:00:00' ),
				array( 'permalink' => 'https://example.com/product/gadget/', 'last_modified' => '2026-06-30 08:00:00' ),
				// No permalink — skipped from the rendered file, so its (newest) last_modified
				// must not stamp the chunk's freshness.
				array( 'permalink' => '', 'last_modified' => '2026-07-05 12:00:00' ),
			)
		);

		$wp_filesystem->shouldReceive( 'put_contents' )->once()->andReturn( true );

		$this->files->shouldReceive( 'update_after_rebuild' )->once()->with( 7, '2026-07-02 10:00:00' );

		$this->assertTrue( $this->writer->rebuild( $chunk ) );
	}

	public function test_rebuild_keeps_dirty_flag_when_write_fails(): void {
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock();

		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$wp_filesystem->shouldReceive( 'put_contents' )->once()->andReturn( false );

		$this->files->shouldNotReceive( 'update_after_rebuild' );

		$this->assertFalse( $this->writer->rebuild( $chunk ) );
	}

	public function test_rebuild_returns_false_when_directory_cannot_be_created(): void {
		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn(
			array(
				array( 'permalink' => 'https://example.com/product/widget/', 'last_modified' => '2026-07-02 10:00:00' ),
			)
		);

		$this->files->shouldNotReceive( 'update_after_rebuild' );

		$this->assertFalse( $this->writer->rebuild( $chunk ) );
	}

	public function test_rebuild_returns_false_when_filesystem_init_fails(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( false );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn(
			array(
				array( 'permalink' => 'https://example.com/product/widget/', 'last_modified' => '2026-07-02 10:00:00' ),
			)
		);

		$this->files->shouldNotReceive( 'update_after_rebuild' );

		$this->assertFalse( $this->writer->rebuild( $chunk ) );
	}

	public function test_delete_file_unlinks_existing_file(): void {
		$dir = sys_get_temp_dir() . '/taseo-writer-test-uploads';

		if ( ! is_dir( $dir . '/taseo-sitemaps' ) ) {
			mkdir( $dir . '/taseo-sitemaps', 0777, true );
		}

		touch( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );

		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $dir, 'error' => false ) );
		Functions\expect( 'wp_delete_file' )->once()->with( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );

		$this->writer->delete_file( array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' ) );

		unlink( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );
	}

	public function test_delete_file_skips_missing_file(): void {
		Functions\expect( 'wp_delete_file' )->never();

		$this->writer->delete_file( array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '999' ) );
	}

	public function test_is_writable_requires_healthy_writable_uploads_dir(): void {
		Functions\when( 'wp_is_writable' )->justReturn( true );

		$this->assertTrue( $this->writer->is_writable() );
	}

	public function test_is_writable_fails_on_uploads_error(): void {
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/tmp/uploads', 'error' => 'nope' ) );

		$this->assertFalse( $this->writer->is_writable() );
	}
}
