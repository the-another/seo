<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapServer;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( SitemapServer::class )]
class SitemapServerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $files;
	private $storage;
	private $settings;
	private SitemapServer $server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->storage  = Mockery::mock( SitemapStorage::class );
		$this->settings = Mockery::mock( Settings::class );

		Functions\when( 'home_url' )->alias( fn( string $path = '' ): string => 'https://example.com' . $path );
		Functions\when( 'esc_url' )->returnArg();

		// The real predicate. The root index and orphan cleanup must agree on
		// what "live" means — they used to state it separately and could drift
		// — so this exercises the shared implementation rather than a mock
		// that would happily agree with a broken one.
		$real_files = new SitemapFileRepository();
		$this->files->shouldReceive( 'is_listable' )->andReturnUsing(
			static fn( ?array $chunk ): bool => $real_files->is_listable( $chunk )
		)->byDefault();

		$this->server = new SitemapServer( $this->files, $this->storage, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_root_index_lists_only_populated_chunks_at_root_level_urls(): void {
		$this->storage->shouldReceive( 'get_file_name' )->andReturnUsing(
			fn( array $chunk ): string => $chunk['object_subtype'] . '-sitemap-' . $chunk['chunk_number'] . '.xml'
		);

		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn(
			array(
				array( 'object_subtype' => 'page', 'chunk_number' => '1', 'link_count' => '87', 'last_modified' => null, 'generated_at' => '2026-07-03 09:00:00' ),
				array( 'object_subtype' => 'product', 'chunk_number' => '1', 'link_count' => '1000', 'last_modified' => '2026-07-02 10:00:00', 'generated_at' => '2026-07-03 09:00:00' ),
				array( 'object_subtype' => 'product', 'chunk_number' => '2', 'link_count' => '0', 'last_modified' => null, 'generated_at' => null ),
				array( 'object_subtype' => 'product', 'chunk_number' => '3', 'link_count' => '500', 'last_modified' => null, 'generated_at' => null ),
			)
		);

		$xml = $this->server->render_root_index();

		$this->assertStringContainsString( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/page-sitemap-1.xml</loc>', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/product-sitemap-1.xml</loc>', $xml );
		$this->assertStringContainsString( '<lastmod>2026-07-02T10:00:00+00:00</lastmod>', $xml );
		// Empty chunks, unwritten chunks (no generated_at yet), and uploads URLs never appear.
		$this->assertStringNotContainsString( 'product-sitemap-2.xml', $xml );
		$this->assertStringNotContainsString( 'product-sitemap-3.xml', $xml );
		$this->assertStringNotContainsString( 'wp-content/uploads', $xml );
	}

	public function test_maybe_serve_outputs_live_root_index(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn( array() );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->once()->with( 200 );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<sitemapindex', $output );
	}

	public function test_root_index_output_passes_through_sitemap_xml_filter(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->storage->shouldReceive( 'get_file_name' )->andReturn( 'page-sitemap-1.xml' );
		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn(
			array(
				array( 'object_subtype' => 'page', 'chunk_number' => '1', 'link_count' => '10', 'last_modified' => null, 'generated_at' => '2026-07-03 09:00:00' ),
			)
		);

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->once()->with( 200 );

		Monkey\Filters\expectApplied( 'taseo_sitemap_xml' )
			->once()
			->andReturnUsing( static fn( string $xml ): string => str_replace( 'example.com', 'brand.test', $xml ) );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<loc>https://brand.test/page-sitemap-1.xml</loc>', $output );
		$this->assertStringNotContainsString( 'example.com', $output );
	}

	public function test_root_index_ignores_non_string_filter_return(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn( array() );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->once()->with( 200 );

		Monkey\Filters\expectApplied( 'taseo_sitemap_xml' )->once()->andReturn( null );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<sitemapindex', $output );
	}

	public function test_chunk_output_passes_through_sitemap_xml_filter_when_subscribed(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '3',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\expect( 'status_header' )->once()->with( 200 );

		$expected_chunk = array( 'object_subtype' => 'product', 'chunk_number' => 3 );

		$this->storage->shouldReceive( 'read' )
			->once()
			->with( $expected_chunk )
			->andReturn( '<urlset><loc>https://example.com/x.html</loc></urlset>' );
		$this->storage->shouldNotReceive( 'stream' );

		Monkey\Filters\expectApplied( 'taseo_sitemap_xml' )
			->once()
			->andReturnUsing( static fn( string $xml ): string => str_replace( 'example.com', 'brand.test', $xml ) );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '<urlset><loc>https://brand.test/x.html</loc></urlset>', $output );
	}

	public function test_chunk_read_miss_with_subscriber_falls_back_to_registry_status(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '3',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\expect( 'status_header' )->once()->with( 410 );

		$expected_chunk = array( 'object_subtype' => 'product', 'chunk_number' => 3 );

		$this->storage->shouldReceive( 'read' )->once()->with( $expected_chunk )->andReturn( null );
		$this->storage->shouldNotReceive( 'stream' );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->once()->with( 'product', 3 )
			->andReturn( array( 'id' => '3', 'link_count' => '0' ) );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_maybe_serve_ignores_normal_requests(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();

		$this->server->maybe_serve( false );
	}

	public function test_maybe_serve_404s_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->once()->with( 404 );

		$this->server->maybe_serve( false );
	}

	public function test_maybe_serve_streams_existing_chunk_file(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '3',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\expect( 'status_header' )->once()->with( 200 );

		$expected_chunk = array( 'object_subtype' => 'product', 'chunk_number' => 3 );

		$this->storage->shouldReceive( 'exists' )->once()->with( $expected_chunk )->andReturn( true );
		$this->storage->shouldReceive( 'stream' )
			->once()
			->with( $expected_chunk )
			->andReturnUsing(
				function () {
					echo '<urlset>static</urlset>';

					return true;
				}
			);

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '<urlset>static</urlset>', $output );
	}

	public function test_maybe_serve_404s_when_no_row_exists_for_missing_chunk_file(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '999',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\expect( 'status_header' )->once()->with( 404 );

		$expected_chunk = array( 'object_subtype' => 'product', 'chunk_number' => 999 );

		$this->storage->shouldReceive( 'exists' )->once()->with( $expected_chunk )->andReturn( false );
		$this->storage->shouldNotReceive( 'stream' );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->once()->with( 'product', 999 )->andReturn( null );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_maybe_serve_404s_when_row_has_links_but_no_file(): void {
		// Temporarily gone: a disabled family, or a chunk claimed before the
		// first sweep ever wrote its file — not a tombstone (link_count > 0).
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '3',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\expect( 'status_header' )->once()->with( 404 );

		$expected_chunk = array( 'object_subtype' => 'product', 'chunk_number' => 3 );

		$this->storage->shouldReceive( 'exists' )->once()->with( $expected_chunk )->andReturn( false );
		$this->storage->shouldNotReceive( 'stream' );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->once()->with( 'product', 3 )
			->andReturn( array( 'id' => '3', 'link_count' => '412' ) );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_maybe_serve_410s_a_tombstoned_chunk(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '3',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		// Only 410 is an acceptable status_header() call here: Mockery errors
		// on any call this expectation doesn't match, so a stray 200 (which
		// would mean send_xml_headers()/header() ran first) fails the test.
		Functions\expect( 'status_header' )->once()->with( 410 );

		$expected_chunk = array( 'object_subtype' => 'product', 'chunk_number' => 3 );

		$this->storage->shouldReceive( 'exists' )->once()->with( $expected_chunk )->andReturn( false );
		$this->storage->shouldNotReceive( 'stream' );
		$this->files->shouldReceive( 'get_by_subtype_and_number' )->once()->with( 'product', 3 )
			->andReturn( array( 'id' => '3', 'link_count' => '0' ) );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_register_rewrites_adds_both_rules_at_top(): void {
		$this->assertSame( '^sitemap\.xml$', SitemapServer::PATTERN_INDEX );
		$this->assertSame( '^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$', SitemapServer::PATTERN_CHUNK );

		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with( SitemapServer::PATTERN_INDEX, 'index.php?taseo_sitemap=index', 'top' );
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with(
				SitemapServer::PATTERN_CHUNK,
				'index.php?taseo_sitemap=chunk&taseo_sitemap_subtype=$matches[1]&taseo_sitemap_chunk=$matches[2]',
				'top'
			);

		$this->server->register_rewrites();
	}

	public function test_register_query_vars_appends_all_three(): void {
		$vars = $this->server->register_query_vars( array( 'p' ) );

		$this->assertContains( 'taseo_sitemap', $vars );
		$this->assertContains( 'taseo_sitemap_subtype', $vars );
		$this->assertContains( 'taseo_sitemap_chunk', $vars );
	}

	public function test_robots_txt_gets_sitemap_line(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		$output = $this->server->append_sitemap_line( "User-agent: *\nDisallow:\n", '1' );

		$this->assertStringContainsString( 'Sitemap: https://example.com/sitemap.xml', $output );
	}

	public function test_robots_txt_untouched_for_private_sites_or_disabled_sitemap(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->assertSame( 'X', $this->server->append_sitemap_line( 'X', '1' ) );
		$this->assertSame( 'X', $this->server->append_sitemap_line( 'X', '0' ) );
	}

	public function test_apache_rules_prepend_static_serving_with_existence_guard(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->storage->shouldReceive( 'is_stream_wrapped' )->andReturn( false );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array( 'basedir' => '/var/www/wp-content/uploads', 'baseurl' => 'https://example.com/wp-content/uploads', 'error' => false )
		);
		Functions\when( 'wp_parse_url' )->alias( fn( string $url, int $component ) => parse_url( $url, $component ) );

		$rules = $this->server->prepend_apache_static_rules( "# WP rules\n" );

		$this->assertStringContainsString( 'RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/taseo-sitemaps/$1-sitemap-$2.xml -f', $rules );
		$this->assertStringContainsString( 'RewriteRule ^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ /wp-content/uploads/taseo-sitemaps/$1-sitemap-$2.xml [L]', $rules );
		// Our block comes BEFORE WP's catch-all.
		$this->assertLessThan( strpos( $rules, '# WP rules' ), strpos( $rules, 'RewriteRule ^([a-z0-9_-]+)-sitemap-' ) );
	}

	public function test_apache_rules_scope_static_serving_to_canonical_host(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->storage->shouldReceive( 'is_stream_wrapped' )->andReturn( false );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array( 'basedir' => '/var/www/wp-content/uploads', 'baseurl' => 'https://example.com/wp-content/uploads', 'error' => false )
		);
		Functions\when( 'wp_parse_url' )->alias( fn( string $url, int $component ) => parse_url( $url, $component ) );

		$rules = $this->server->prepend_apache_static_rules( "# WP rules\n" );

		// A request on any other host (a Brand domain) must fall through to
		// the WP fallback, where the taseo_sitemap_xml filter can run.
		$host_cond = 'RewriteCond %{HTTP_HOST} ^(?:www\.)?example\.com(?::\d+)?$ [NC]';
		$this->assertStringContainsString( $host_cond, $rules );
		$this->assertLessThan(
			strpos( $rules, 'RewriteCond %{DOCUMENT_ROOT}' ),
			strpos( $rules, $host_cond )
		);
	}

	public function test_apache_rules_untouched_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->assertSame( "# WP rules\n", $this->server->prepend_apache_static_rules( "# WP rules\n" ) );
	}

	public function test_apache_rules_suppressed_when_stream_wrapped(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->storage->shouldReceive( 'is_stream_wrapped' )->andReturn( true );

		$this->assertSame( "EXISTING\n", $this->server->prepend_apache_static_rules( "EXISTING\n" ) );
	}

	public function test_core_sitemaps_disabled_while_feature_enabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		$this->assertFalse( $this->server->filter_core_sitemaps( true ) );
	}

	public function test_core_sitemaps_restored_when_feature_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->assertTrue( $this->server->filter_core_sitemaps( true ) );
	}
}
