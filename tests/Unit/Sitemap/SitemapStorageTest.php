<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( SitemapStorage::class )]
class SitemapStorageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\when( 'trailingslashit' )->alias( fn( string $s ): string => rtrim( $s, '/' ) . '/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_uploads( string $basedir, string $error = '' ): void {
		Monkey\Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $basedir,
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => $error,
			)
		);
	}

	public function test_paths_resolve_from_upload_dir_per_call(): void {
		$this->stub_uploads( '/srv/uploads' );

		$storage = new SitemapStorage();
		$chunk   = array( 'object_subtype' => 'vendor_store', 'chunk_number' => 3 );

		$this->assertSame( '/srv/uploads/taseo-sitemaps', $storage->get_directory_path() );
		$this->assertSame( 'vendor_store-sitemap-3.xml', $storage->get_file_name( $chunk ) );
		$this->assertSame( '/srv/uploads/taseo-sitemaps/vendor_store-sitemap-3.xml', $storage->get_file_path( $chunk ) );
	}

	public function test_stream_wrapped_detection(): void {
		$this->stub_uploads( 's3://bucket/uploads' );
		$this->assertTrue( ( new SitemapStorage() )->is_stream_wrapped() );

		$this->stub_uploads( '/srv/uploads' );
		$this->assertFalse( ( new SitemapStorage() )->is_stream_wrapped() );
	}

	public function test_is_writable_requires_no_error_and_writable_basedir(): void {
		$this->stub_uploads( '/srv/uploads' );
		Monkey\Functions\when( 'wp_is_writable' )->justReturn( true );
		$this->assertTrue( ( new SitemapStorage() )->is_writable() );

		$this->stub_uploads( '/srv/uploads', 'broken' );
		$this->assertFalse( ( new SitemapStorage() )->is_writable() );
	}

	public function test_delete_unlinks_only_existing_files(): void {
		$this->stub_uploads( '/srv/uploads' );

		$deleted = array();
		Monkey\Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ) use ( &$deleted ): void {
				$deleted[] = $path;
			}
		);

		$storage = new SitemapStorage();

		// A path that certainly does not exist: nothing deleted.
		$storage->delete( array( 'object_subtype' => 'post', 'chunk_number' => 99 ) );
		$this->assertSame( array(), $deleted );
	}

	public function test_delete_unlinks_existing_file(): void {
		$dir = sys_get_temp_dir() . '/taseo-storage-test-uploads';

		if ( ! is_dir( $dir . '/taseo-sitemaps' ) ) {
			mkdir( $dir . '/taseo-sitemaps', 0777, true );
		}

		touch( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );

		$this->stub_uploads( $dir );

		Monkey\Functions\expect( 'wp_delete_file' )
			->once()
			->with( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );

		( new SitemapStorage() )->delete( array( 'object_subtype' => 'product', 'chunk_number' => 3 ) );

		unlink( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );
	}

	public function test_stream_returns_false_for_missing_file(): void {
		$this->stub_uploads( '/srv/uploads' );

		$this->assertFalse(
			( new SitemapStorage() )->stream( array( 'object_subtype' => 'post', 'chunk_number' => 99 ) )
		);
	}

	public function test_write_fails_when_mkdir_fails(): void {
		$this->stub_uploads( '/srv/uploads' );
		Monkey\Functions\when( 'wp_mkdir_p' )->justReturn( false );

		$this->assertFalse(
			( new SitemapStorage() )->write( array( 'object_subtype' => 'post', 'chunk_number' => 1 ), '<xml/>' )
		);
	}

	public function test_write_fails_when_filesystem_init_fails(): void {
		$this->stub_uploads( '/srv/uploads' );
		Monkey\Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Monkey\Functions\when( 'WP_Filesystem' )->justReturn( false );

		$this->assertFalse(
			( new SitemapStorage() )->write( array( 'object_subtype' => 'post', 'chunk_number' => 1 ), '<xml/>' )
		);
	}
}
