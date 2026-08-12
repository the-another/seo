<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;

#[CoversClass( VerificationFileServer::class )]
class VerificationFileServerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $domains;
	private VerificationFileServer $server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->domains  = Mockery::mock( DomainRegistry::class );
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'example.com' )->byDefault();

		$this->server = new VerificationFileServer( $this->settings, $this->domains );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'nocache_headers' )->justReturn( null );

		// header() is a PHP internal function: Brain Monkey cannot redefine
		// it, and under CLI it is a harmless no-op. Content types are
		// therefore asserted in the e2e suite against real HTTP responses,
		// which is where they can actually be observed. This mirrors
		// SitemapServerTest, which asserts status_header and body only.

		$this->files();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function files( array $tokens = array(), string $host = 'example.com' ): void {
		$defaults = array(
			'google' => '',
			'bing'   => '',
			'yandex' => '',
		);

		foreach ( array_merge( $defaults, $tokens ) as $engine => $token ) {
			$this->settings->shouldReceive( 'get_verification_code' )
				->with( $engine, $host )
				->andReturn( $token )
				->byDefault();

			$this->settings->shouldReceive( 'get_verification_method' )
				->with( $engine, $host )
				->andReturn( '' === $token ? 'meta' : 'file' )
				->byDefault();
		}
	}

	private function serve( string $uri ): string {
		$_SERVER['REQUEST_URI'] = $uri;

		// A permissive fallback, registered after any test-specific
		// Functions\expect( 'status_header' ) call above: Brain Monkey
		// matches expectations in registration order, so a test's own
		// ->once()->with( 200 ) (added first) is matched before this
		// catch-all, while tests that don't care about the status code
		// still get a harmless no-op instead of an "unexpected call" error.
		Functions\expect( 'status_header' )->zeroOrMoreTimes();

		ob_start();
		$this->server->maybe_serve( false );

		return (string) ob_get_clean();
	}

	public function test_serves_the_google_file_with_an_exact_body(): void {
		$this->files( array( 'google' => '1a2b3c' ) );
		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/google1a2b3c.html' )
		);
	}

	public function test_serves_the_bing_file_with_an_exact_body(): void {
		$this->files( array( 'bing' => 'BINGTOKEN123' ) );

		$body = $this->serve( '/BingSiteAuth.xml' );

		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<users>\n  <user>BINGTOKEN123</user>\n</users>",
			$body
		);
	}

	public function test_serves_the_yandex_file_with_an_exact_body(): void {
		$this->files( array( 'yandex' => '9f8e7d' ) );

		$body = $this->serve( '/yandex_9f8e7d.html' );

		$this->assertSame(
			"<html>\n<head>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n</head>\n<body>Verification: 9f8e7d</body>\n</html>",
			$body
		);
	}

	public function test_ignores_a_non_matching_path(): void {
		$this->files( array( 'google' => '1a2b3c' ) );

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/some-post/' ) );
	}

	public function test_ignores_a_wrong_token(): void {
		$this->files( array( 'google' => '1a2b3c' ) );

		$this->assertSame( '', $this->serve( '/google-wrong-token.html' ) );
	}

	public function test_does_nothing_when_no_files_are_configured(): void {
		$this->assertSame( '', $this->serve( '/google1a2b3c.html' ) );
	}

	public function test_matching_is_case_sensitive(): void {
		$this->files( array( 'bing' => 'BINGTOKEN123' ) );

		$this->assertSame( '', $this->serve( '/bingsiteauth.xml' ) );
	}

	public function test_strips_the_home_url_path_prefix_for_subdirectory_installs(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/blog' );
		$this->files( array( 'google' => '1a2b3c' ) );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/blog/google1a2b3c.html' )
		);
	}

	public function test_ignores_a_query_string(): void {
		$this->files( array( 'google' => '1a2b3c' ) );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/google1a2b3c.html?utm_source=x' )
		);
	}

	public function test_files_filter_can_add_a_file(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'ahrefs_1234.html' => array(
					'content_type' => 'text/plain',
					'body'         => 'ahrefs-site-verification: 1234',
				),
			)
		);

		$this->assertSame( 'ahrefs-site-verification: 1234', $this->serve( '/ahrefs_1234.html' ) );
	}

	public function test_files_filter_rejects_a_disallowed_content_type(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'evil.html' => array(
					'content_type' => 'application/javascript',
					'body'         => 'alert(1)',
				),
			)
		);

		$this->assertSame( '', $this->serve( '/evil.html' ) );
	}

	public function test_a_malformed_stored_google_filename_is_never_served(): void {
		// Options are writable outside SettingsPage::sanitize_settings()
		// (WP-CLI, a migration, this branch's own e2e harness); the class
		// must not trust a distant caller for its own safety.
		$this->files( array( 'google' => '../wp-config.php' ) );

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/../wp-config.php' ) );
	}

	public function test_a_malformed_stored_bing_token_is_never_served(): void {
		$this->files( array( 'bing' => '<script>alert(1)</script>' ) );

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/BingSiteAuth.xml' ) );
	}

	public function test_a_malformed_stored_yandex_filename_is_never_served(): void {
		$this->files( array( 'yandex' => '<script>alert(1)</script>' ) );

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/yandex_<script>.html' ) );
	}

	public function test_files_filter_key_containing_a_slash_is_not_served(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'evil/inject.html' => array(
					'content_type' => 'text/plain',
					'body'         => 'evil',
				),
			)
		);

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/evil/inject.html' ) );
	}

	public function test_files_filter_key_containing_dot_dot_is_not_served(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'../evil.html' => array(
					'content_type' => 'text/plain',
					'body'         => 'evil',
				),
			)
		);

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '../evil.html' ) );
	}

	public function test_files_filter_valid_key_is_still_served(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'ahrefs_1234.html' => array(
					'content_type' => 'text/plain',
					'body'         => 'ahrefs-site-verification: 1234',
				),
			)
		);

		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertSame( 'ahrefs-site-verification: 1234', $this->serve( '/ahrefs_1234.html' ) );
	}

	public function test_files_filter_rejects_a_non_string_body(): void {
		Filters\expectApplied( 'taseo_verification_files' )->once()->andReturn(
			array(
				'evil.html' => array(
					'content_type' => 'text/plain',
					'body'         => array( 'not' => 'a string' ),
				),
			)
		);

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/evil.html' ) );
	}

	public function test_serves_a_brand_domains_own_file(): void {
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'brandtwo.com' );

		$this->files( array( 'google' => 'brandtwo' ), 'brandtwo.com' );

		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertSame(
			'google-site-verification: googlebrandtwo.html',
			$this->serve( '/googlebrandtwo.html' )
		);
	}

	public function test_does_not_serve_one_domains_file_on_another_domain(): void {
		// The current host stays example.com (the setUp default) and has no
		// file of its own. googlebrandtwo.html exists only under brandtwo.com,
		// so a pass here means host resolution actually gated the lookup —
		// unlike asserting on a filename no host owns, which passes even if
		// maybe_serve() ignored the host entirely.
		$this->files();

		$this->settings->shouldReceive( 'get_verification_code' )
			->with( 'google', 'brandtwo.com' )
			->andReturn( 'brandtwo' )
			->byDefault();

		$this->settings->shouldReceive( 'get_verification_method' )
			->with( 'google', 'brandtwo.com' )
			->andReturn( 'file' )
			->byDefault();

		$this->assertSame( '', $this->serve( '/googlebrandtwo.html' ) );
	}

	public function test_derives_the_google_filename_from_the_token(): void {
		$this->files( array( 'google' => '1a2b3c' ) );

		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/google1a2b3c.html' )
		);
	}

	public function test_derives_the_yandex_filename_from_the_token(): void {
		$this->files( array( 'yandex' => 'abc123' ) );

		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertStringContainsString(
			'Verification: abc123',
			$this->serve( '/yandex_abc123.html' )
		);
	}

	public function test_serves_bing_at_its_fixed_filename(): void {
		$this->files( array( 'bing' => 'BINGTOKEN' ) );

		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->assertStringContainsString( '<user>BINGTOKEN</user>', $this->serve( '/BingSiteAuth.xml' ) );
	}

	public function test_serves_nothing_for_a_service_in_meta_mode(): void {
		$this->settings->shouldReceive( 'get_verification_code' )->with( 'google', 'example.com' )->andReturn( '1a2b3c' );
		$this->settings->shouldReceive( 'get_verification_method' )->with( 'google', 'example.com' )->andReturn( 'meta' );
		$this->settings->shouldReceive( 'get_verification_code' )->with( 'bing', 'example.com' )->andReturn( '' );
		$this->settings->shouldReceive( 'get_verification_method' )->with( 'bing', 'example.com' )->andReturn( 'meta' );
		$this->settings->shouldReceive( 'get_verification_code' )->with( 'yandex', 'example.com' )->andReturn( '' );
		$this->settings->shouldReceive( 'get_verification_method' )->with( 'yandex', 'example.com' )->andReturn( 'meta' );

		$this->assertSame( '', $this->serve( '/google1a2b3c.html' ) );
	}
}
