<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Installer;

#[CoversClass( Installer::class )]
class InstallerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_activate_creates_both_tables_and_sets_flags(): void {
		$updated = array();

		// Not false: a site with an existing options row, so the consent
		// backfill below has nothing to do and stays out of this test's count.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'dbDelta' )->twice();
		Functions\expect( 'update_option' )
			->times( 4 )
			->andReturnUsing(
				function ( string $option ) use ( &$updated ): bool {
					$updated[] = $option;
					return true;
				}
			);

		Installer::activate();

		$this->assertContains( 'taseo_db_version', $updated );
		$this->assertContains( 'taseo_sitemap_db_version', $updated );
		$this->assertContains( 'taseo_needs_backfill', $updated );
		$this->assertContains( 'taseo_needs_rewrite_flush', $updated );
	}

	public function test_activation_turns_consent_on_for_a_fresh_install(): void {
		$written = array();

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): void {
				$written[ $key ] = $value;
			}
		);

		Installer::activate();

		$this->assertSame( array( 'consent_enabled' => true ), $written['taseo_settings'] );
	}

	public function test_activation_leaves_an_existing_option_alone(): void {
		$written = array();

		Functions\when( 'get_option' )->justReturn( array( 'separator' => '-' ) );
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): void {
				$written[ $key ] = $value;
			}
		);

		Installer::activate();

		$this->assertArrayNotHasKey( 'taseo_settings', $written );
	}
}
