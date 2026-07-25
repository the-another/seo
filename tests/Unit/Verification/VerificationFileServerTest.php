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
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;

#[CoversClass( VerificationFileServer::class )]
class VerificationFileServerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private VerificationFileServer $server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->server   = new VerificationFileServer( $this->settings );

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

	private function files( array $files = array() ): void {
		$defaults = array(
			'google' => '',
			'bing'   => '',
			'yandex' => '',
		);

		foreach ( array_merge( $defaults, $files ) as $engine => $value ) {
			$this->settings->shouldReceive( 'get_verification_file' )
				->with( $engine )
				->andReturn( $value )
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
		$this->files( array( 'google' => 'google1a2b3c.html' ) );
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
		$this->files( array( 'yandex' => 'yandex_9f8e7d.html' ) );

		$body = $this->serve( '/yandex_9f8e7d.html' );

		$this->assertSame(
			"<html>\n<head>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n</head>\n<body>Verification: 9f8e7d</body>\n</html>",
			$body
		);
	}

	public function test_ignores_a_non_matching_path(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

		Functions\expect( 'status_header' )->never();

		$this->assertSame( '', $this->serve( '/some-post/' ) );
	}

	public function test_ignores_a_wrong_token(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

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
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

		$this->assertSame(
			'google-site-verification: google1a2b3c.html',
			$this->serve( '/blog/google1a2b3c.html' )
		);
	}

	public function test_ignores_a_query_string(): void {
		$this->files( array( 'google' => 'google1a2b3c.html' ) );

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
}
