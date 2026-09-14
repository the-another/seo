<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Database\IndexablesTable;
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

		// Not false: a site that already records a schema version, so the
		// consent backfill below has nothing to do and stays out of this
		// test's count.
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

		// No schema version recorded: the plugin has never been installed
		// here, so there is no established tracking behaviour to preserve.
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

	public function test_activation_leaves_an_existing_install_alone(): void {
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

	public function test_an_installed_site_with_no_settings_row_does_not_become_gated(): void {
		$written = array();

		// A site whose tracking IDs arrive entirely through
		// taseo_tracking_tag_ids or the per-vendor ID filters never saves
		// settings, so taseo_settings is absent — but it has been installed
		// for versions and its tracking has been running all along. Keying the
		// fresh-install decision off the settings row turned the gate on for
		// exactly this site on its next reactivation, silently stopping the
		// tracking the false default exists to protect.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $fallback = false ) => IndexablesTable::DB_VERSION_OPTION === $option ? IndexablesTable::DB_VERSION : $fallback
		);
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): void {
				$written[ $key ] = $value;
			}
		);

		Installer::activate();

		$this->assertArrayNotHasKey( 'taseo_settings', $written );
	}

	public function test_a_fresh_install_gains_the_gate_without_discarding_stored_settings(): void {
		$written = array();

		// Not reachable through a normal lifecycle — uninstall removes both
		// options together — but reading a different option to decide this
		// makes "no schema version, settings present" expressible for the
		// first time, and the write must add the gate rather than replace
		// whatever a site had stored.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $fallback = false ) => 'taseo_settings' === $option ? array( 'separator' => '-' ) : $fallback
		);
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): void {
				$written[ $key ] = $value;
			}
		);

		Installer::activate();

		$this->assertSame(
			array(
				'separator'       => '-',
				'consent_enabled' => true,
			),
			$written['taseo_settings']
		);
	}
}
