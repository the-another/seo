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
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapServer;

#[CoversClass( SitemapServer::class )]
class SitemapServerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $files;
	private $writer;
	private $settings;
	private SitemapServer $server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->writer   = Mockery::mock( SitemapFileWriter::class );
		$this->settings = Mockery::mock( Settings::class );

		Functions\when( 'home_url' )->alias( fn( string $path = '' ): string => 'https://example.com' . $path );
		Functions\when( 'esc_url' )->returnArg();

		$this->server = new SitemapServer( $this->files, $this->writer, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_root_index_lists_only_populated_chunks_at_root_level_urls(): void {
		$this->writer->shouldReceive( 'get_file_name' )->andReturnUsing(
			fn( array $chunk ): string => $chunk['object_subtype'] . '-sitemap-' . $chunk['chunk_number'] . '.xml'
		);

		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn(
			array(
				array( 'object_subtype' => 'page', 'chunk_number' => '1', 'link_count' => '87', 'last_modified' => null ),
				array( 'object_subtype' => 'product', 'chunk_number' => '1', 'link_count' => '1000', 'last_modified' => '2026-07-02 10:00:00' ),
				array( 'object_subtype' => 'product', 'chunk_number' => '2', 'link_count' => '0', 'last_modified' => null ),
			)
		);

		$xml = $this->server->render_root_index();

		$this->assertStringContainsString( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/page-sitemap-1.xml</loc>', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/product-sitemap-1.xml</loc>', $xml );
		$this->assertStringContainsString( '<lastmod>2026-07-02T10:00:00+00:00</lastmod>', $xml );
		// Empty chunks and uploads URLs never appear.
		$this->assertStringNotContainsString( 'product-sitemap-2.xml', $xml );
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

	public function test_maybe_serve_ignores_normal_requests(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();

		$this->server->maybe_serve( false );
	}

	public function test_maybe_serve_ignores_requests_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->never();

		$this->server->maybe_serve( false );
	}

	public function test_maybe_serve_streams_existing_chunk_file(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		$path = tempnam( sys_get_temp_dir(), 'taseo' );
		file_put_contents( $path, '<urlset>static</urlset>' );

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

		$this->writer->shouldReceive( 'get_file_path' )
			->once()
			->with( array( 'object_subtype' => 'product', 'chunk_number' => 3 ) )
			->andReturn( $path );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '<urlset>static</urlset>', $output );

		unlink( $path );
	}

	public function test_maybe_serve_404s_missing_chunk_file(): void {
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

		$this->writer->shouldReceive( 'get_file_path' )->once()->andReturn( '/nonexistent/product-sitemap-999.xml' );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_register_rewrites_adds_both_rules_at_top(): void {
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with( '^sitemap\.xml$', 'index.php?taseo_sitemap=index', 'top' );
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with(
				'^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$',
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

	public function test_apache_rules_untouched_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->assertSame( "# WP rules\n", $this->server->prepend_apache_static_rules( "# WP rules\n" ) );
	}
}
