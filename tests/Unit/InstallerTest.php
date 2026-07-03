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
}
