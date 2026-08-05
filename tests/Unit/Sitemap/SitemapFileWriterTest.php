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
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( SitemapFileWriter::class )]
class SitemapFileWriterTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private $files;
	private $storage;
	private SitemapFileWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->files   = Mockery::mock( SitemapFileRepository::class );
		$this->storage = Mockery::mock( SitemapStorage::class );
		$this->writer  = new SitemapFileWriter( $this->files, $this->storage );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_format_lastmod_converts_gmt_datetime_to_w3c(): void {
		$this->assertSame( '2026-07-02T10:00:00+00:00', SitemapFileWriter::format_lastmod( '2026-07-02 10:00:00' ) );
		$this->assertNull( SitemapFileWriter::format_lastmod( null ) );
		$this->assertNull( SitemapFileWriter::format_lastmod( '' ) );
	}

	public function test_rebuild_writes_urlset_and_clears_dirty(): void {
		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'FROM wp_taseo_indexables' )
						&& str_contains( $sql, 'sitemap_images' )
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
		$this->storage->shouldReceive( 'write' )
			->once()
			->with( $chunk, Mockery::capture( $captured ) )
			->andReturn( true );

		$this->files->shouldReceive( 'update_after_rebuild' )->once()->with( 7, '2026-07-02 10:00:00' );

		$this->assertTrue( $this->writer->rebuild( $chunk ) );

		$this->assertStringContainsString( '<?xml version="1.0" encoding="UTF-8"?>', $captured );
		$this->assertStringContainsString( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">', $captured );
		$this->assertStringContainsString( '<loc>https://example.com/product/widget/</loc>', $captured );
		$this->assertStringContainsString( '<lastmod>2026-07-02T10:00:00+00:00</lastmod>', $captured );
		$this->assertSame( 2, substr_count( $captured, '<url>' ) );
	}

	public function test_rebuild_max_modified_ignores_rows_skipped_from_render(): void {
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

		$this->storage->shouldReceive( 'write' )->once()->andReturn( true );

		$this->files->shouldReceive( 'update_after_rebuild' )->once()->with( 7, '2026-07-02 10:00:00' );

		$this->assertTrue( $this->writer->rebuild( $chunk ) );
	}

	public function test_rebuild_keeps_dirty_flag_when_write_fails(): void {
		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->storage->shouldReceive( 'write' )->once()->andReturn( false );

		$this->files->shouldNotReceive( 'update_after_rebuild' );

		$this->assertFalse( $this->writer->rebuild( $chunk ) );
	}

	public function test_urlset_declares_image_namespace(): void {
		$xml = $this->rebuild_capturing_xml( array() );

		$this->assertStringContainsString( 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml );
	}

	public function test_row_images_render_as_image_loc(): void {
		$xml = $this->rebuild_capturing_xml(
			array(
				array(
					'permalink'      => 'https://example.com/a/',
					'last_modified'  => null,
					'sitemap_images' => wp_json_encode( array( 'https://example.com/1.jpg', 'https://example.com/2.jpg' ) ),
				),
			)
		);

		$this->assertStringContainsString( '<image:image><image:loc>https://example.com/1.jpg</image:loc></image:image>', $xml );
		$this->assertStringContainsString( '<image:image><image:loc>https://example.com/2.jpg</image:loc></image:image>', $xml );
	}

	public function test_malformed_images_json_renders_no_images(): void {
		$xml = $this->rebuild_capturing_xml(
			array(
				array(
					'permalink'      => 'https://example.com/a/',
					'last_modified'  => null,
					'sitemap_images' => '{not json',
				),
			)
		);

		$this->assertStringNotContainsString( '<image:image>', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/a/</loc>', $xml );
	}

	public function test_null_images_render_unchanged(): void {
		$xml = $this->rebuild_capturing_xml(
			array(
				array(
					'permalink'      => 'https://example.com/a/',
					'last_modified'  => null,
					'sitemap_images' => null,
				),
			)
		);

		$this->assertStringNotContainsString( '<image:image>', $xml );
	}

	/**
	 * Stub the member-row query and chunk write, returning the XML handed to
	 * SitemapStorage::write() for one rebuild() call.
	 *
	 * @param array<int, array<string, mixed>> $rows Member rows to return from the DB query.
	 * @return string Captured XML.
	 */
	private function rebuild_capturing_xml( array $rows ): string {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn( $rows );

		$captured = null;
		$this->storage->shouldReceive( 'write' )
			->once()
			->andReturnUsing(
				function ( array $chunk, string $xml ) use ( &$captured ): bool {
					$captured = $xml;
					return true;
				}
			);

		$this->files->shouldReceive( 'update_after_rebuild' );

		$this->writer->rebuild( array( 'id' => 1, 'object_subtype' => 'post', 'chunk_number' => 1 ) );

		return $captured;
	}
}
