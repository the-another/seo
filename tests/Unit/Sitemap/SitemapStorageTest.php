<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery;
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
		unset( $GLOBALS['wp_filesystem'] );
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

	public function test_parse_file_name_round_trips_get_file_name(): void {
		$this->stub_uploads( '/srv/uploads' );

		$storage = new SitemapStorage();
		$chunk   = array( 'object_subtype' => 'vendor_store', 'chunk_number' => 3 );

		$parsed = $storage->parse_file_name( $storage->get_file_name( $chunk ) );

		$this->assertSame( array( 'object_subtype' => 'vendor_store', 'chunk_number' => 3 ), $parsed );
	}

	public function test_parse_file_name_keeps_the_longest_subtype(): void {
		$this->stub_uploads( '/srv/uploads' );

		$storage = new SitemapStorage();

		// A subtype key may itself contain "-sitemap-N"; the greedy match has
		// to return whatever get_file_name() was given, or the round trip
		// silently retargets another chunk.
		$chunk = array( 'object_subtype' => 'odd-sitemap-1', 'chunk_number' => 2 );

		$this->assertSame(
			array( 'object_subtype' => 'odd-sitemap-1', 'chunk_number' => 2 ),
			$storage->parse_file_name( $storage->get_file_name( $chunk ) )
		);
	}

	public function test_parse_file_name_rejects_non_chunk_names(): void {
		$this->stub_uploads( '/srv/uploads' );

		$storage = new SitemapStorage();

		$this->assertNull( $storage->parse_file_name( 'index.html' ) );
		$this->assertNull( $storage->parse_file_name( 'sitemap.xml' ) );
		$this->assertNull( $storage->parse_file_name( 'Vendor-sitemap-1.xml' ) );
		$this->assertNull( $storage->parse_file_name( 'vendor-sitemap-0.xml' ) );
		$this->assertNull( $storage->parse_file_name( 'vendor-sitemap-1.xml.bak' ) );
	}

	public function test_list_files_returns_only_files(): void {
		$this->stub_uploads( '/srv/uploads' );

		$filesystem = Mockery::mock( 'WP_Filesystem_Base' );
		$filesystem->shouldReceive( 'dirlist' )
			->once()
			->with( '/srv/uploads/taseo-sitemaps' )
			->andReturn(
				array(
					'product-sitemap-1.xml' => array( 'type' => 'f' ),
					'nested'                => array( 'type' => 'd' ),
					'page-sitemap-2.xml'    => array( 'type' => 'f' ),
				)
			);

		$GLOBALS['wp_filesystem'] = $filesystem;
		Monkey\Functions\when( 'WP_Filesystem' )->justReturn( true );

		$names = ( new SitemapStorage() )->list_files();

		sort( $names );
		$this->assertSame( array( 'page-sitemap-2.xml', 'product-sitemap-1.xml' ), $names );
	}

	public function test_list_files_returns_empty_when_directory_cannot_be_listed(): void {
		$this->stub_uploads( '/srv/uploads' );

		$filesystem = Mockery::mock( 'WP_Filesystem_Base' );
		$filesystem->shouldReceive( 'dirlist' )->once()->andReturn( false );

		$GLOBALS['wp_filesystem'] = $filesystem;
		Monkey\Functions\when( 'WP_Filesystem' )->justReturn( true );

		$this->assertSame( array(), ( new SitemapStorage() )->list_files() );
	}
}
