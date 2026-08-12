<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\MigrationNotice;

#[CoversClass( MigrationNotice::class )]
class MigrationNoticeTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private MigrationNotice $notice;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// esc_html() is deliberately not stubbed: it is a real function defined
		// directly in tests/Unit/bootstrap.php rather than through a
		// Patchwork-wrapped include, so Brain\Monkey cannot redefine it (see the
		// same note on MetaboxTest::render_metabox_fields()). Its real
		// htmlspecialchars() implementation is a no-op on the plain labels and
		// domains these tests render, so none is needed.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_url' )->alias( static fn( string $url ) => $url );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . $path );

		$this->notice = new MigrationNotice();
	}

	protected function tearDown(): void {
		unset( $_GET['taseo_settings_nonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->notice->render();

		return (string) ob_get_clean();
	}

	public function test_renders_nothing_without_a_stored_notice(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', $this->render() );
	}

	public function test_names_each_dropped_service_and_domain(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				array( 'engine' => 'google', 'domain' => '' ),
				array( 'engine' => 'google', 'domain' => 'brandtwo.com' ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Google', $html );
		$this->assertStringContainsString( 'brandtwo.com', $html );
	}

	public function test_renders_nothing_when_the_option_is_corrupt(): void {
		Functions\when( 'get_option' )->justReturn( 'not an array' );

		$this->assertSame( '', $this->render() );
	}

	public function test_skips_an_entry_that_is_not_an_array(): void {
		Functions\when( 'get_option' )->justReturn( array( 'not an array' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_skips_an_entry_missing_an_engine(): void {
		Functions\when( 'get_option' )->justReturn( array( array( 'domain' => 'brandtwo.com' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_falls_back_to_the_raw_slug_for_an_unknown_engine(): void {
		Functions\when( 'get_option' )->justReturn( array( array( 'engine' => 'duckduckgo', 'domain' => '' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'duckduckgo', $html );
	}

	public function test_renders_nothing_when_the_user_cannot_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_option' )->never();

		$this->assertSame( '', $this->render() );
	}

	public function test_dismiss_deletes_the_option(): void {
		$_GET['taseo_settings_nonce'] = 'nonce';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		Functions\expect( 'delete_option' )->once()->with( 'taseo_verification_migration_notice' );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$this->notice->dismiss( false );
	}

	public function test_dismiss_does_nothing_without_a_valid_nonce(): void {
		unset( $_GET['taseo_settings_nonce'] );

		Functions\expect( 'delete_option' )->never();

		$this->notice->dismiss( false );
	}
}
