<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the uninstall.php shim at the plugin root.
 *
 * The file is procedural and lives outside includes/, so it carries no
 * CoversClass attribute — what is asserted is that it delegates to
 * Uninstaller rather than growing teardown logic of its own, and that the
 * teardown really runs when WordPress includes it.
 */
class UninstallFileTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function uninstall_file(): string {
		return dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_including_it_under_wp_uninstall_plugin_runs_the_teardown(): void {
		$dropped = array();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ) use ( &$dropped ): int {
				$dropped[] = $sql;
				return 0;
			}
		);

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'trailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/srv/uploads',
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => '',
			)
		);
		// An unavailable filesystem is the cheap branch of delete_directory()
		// here; SitemapStorageTest covers the removal itself.
		Functions\when( 'WP_Filesystem' )->justReturn( false );

		// WordPress defines this immediately before including the file; the
		// guard inside exits without it.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'the-another-seo/the-another-seo.php' );
		}

		require $this->uninstall_file();

		$this->assertContains( 'DROP TABLE IF EXISTS wp_taseo_indexables', $dropped );
		$this->assertContains( 'DROP TABLE IF EXISTS wp_taseo_sitemap_files', $dropped );
	}

	public function test_it_refuses_to_run_outside_a_wordpress_uninstall(): void {
		$source = (string) file_get_contents( $this->uninstall_file() );

		// A direct hit on wp-content/plugins/the-another-seo/uninstall.php
		// must not drop the tables. WP_UNINSTALL_PLUGIN is the only signal
		// available, so the guard has to be the first statement that runs.
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*!\s*defined\(\s*\'WP_UNINSTALL_PLUGIN\'\s*\)\s*\)\s*{\s*exit;/',
			$source
		);
	}

	public function test_it_delegates_instead_of_reimplementing_the_teardown(): void {
		$source = (string) file_get_contents( $this->uninstall_file() );

		$this->assertStringContainsString( 'Uninstaller::uninstall()', $source );
		$this->assertStringNotContainsString( 'DROP TABLE', $source, 'Teardown SQL belongs in the table classes, not the shim.' );
	}
}
