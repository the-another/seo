<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Uninstaller;

#[CoversClass( Uninstaller::class )]
class UninstallerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Every call the teardown made, in order, as "verb:subject" strings.
	 *
	 * @var array<int, string>
	 */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->calls = array();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ): int {
				$this->calls[] = 'query:' . $sql;
				return 0;
			}
		);

		Functions\when( 'delete_option' )->alias(
			function ( string $option ): bool {
				$this->calls[] = 'delete_option:' . $option;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A storage double that logs its teardown into the shared call log.
	 *
	 * @param bool $result What delete_directory() reports.
	 * @return SitemapStorage&Mockery\MockInterface Double.
	 */
	private function logging_storage( bool $result = true ): SitemapStorage {
		$storage = Mockery::mock( SitemapStorage::class );
		$storage->shouldReceive( 'delete_directory' )->andReturnUsing(
			function () use ( $result ): bool {
				$this->calls[] = 'delete_directory';
				return $result;
			}
		);

		return $storage;
	}

	/**
	 * Option names deleted by the table classes' own drop_table().
	 *
	 * @var array<int, string>
	 */
	private const TABLE_OWNED_OPTIONS = array(
		'taseo_db_version',
		'taseo_sitemap_db_version',
	);

	public function test_uninstall_site_removes_the_sitemap_files_before_touching_the_database(): void {
		Uninstaller::uninstall_site( $this->logging_storage() );

		$this->assertSame( 'delete_directory', $this->calls[0], 'Chunk files keep being served after deletion; they go first.' );
	}

	public function test_uninstall_site_drops_both_tables(): void {
		Uninstaller::uninstall_site( $this->logging_storage() );

		$this->assertContains( 'query:DROP TABLE IF EXISTS wp_taseo_indexables', $this->calls );
		$this->assertContains( 'query:DROP TABLE IF EXISTS wp_taseo_sitemap_files', $this->calls );
	}

	public function test_uninstall_site_deletes_every_option_the_plugin_writes(): void {
		Uninstaller::uninstall_site( $this->logging_storage() );

		$deleted = array_map(
			static fn( string $call ): string => substr( $call, strlen( 'delete_option:' ) ),
			array_values( array_filter( $this->calls, static fn( string $call ): bool => str_starts_with( $call, 'delete_option:' ) ) )
		);

		sort( $deleted );

		$this->assertSame(
			array(
				'taseo_backfill_progress',
				'taseo_db_version',
				'taseo_needs_backfill',
				'taseo_needs_rewrite_flush',
				'taseo_settings',
				'taseo_sitemap_db_version',
				'taseo_verification_method_migrated',
				'taseo_verification_migration_notice',
			),
			$deleted
		);
	}

	public function test_uninstall_site_keeps_going_when_the_files_cannot_be_removed(): void {
		Uninstaller::uninstall_site( $this->logging_storage( false ) );

		$this->assertContains( 'query:DROP TABLE IF EXISTS wp_taseo_indexables', $this->calls );
		$this->assertContains( 'delete_option:taseo_settings', $this->calls );
	}

	public function test_uninstall_runs_once_on_a_single_site_install(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'get_sites' )->never();
		Functions\expect( 'switch_to_blog' )->never();

		Uninstaller::uninstall( $this->logging_storage() );

		$this->assertSame( 1, count( array_filter( $this->calls, static fn( string $c ): bool => 'delete_directory' === $c ) ) );
	}

	public function test_uninstall_visits_every_site_on_multisite(): void {
		$switched = array();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->once()->andReturn( array( 1, 7, 9 ) );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $site_id ) use ( &$switched ): bool {
				$switched[] = $site_id;
				return true;
			}
		);
		Functions\expect( 'restore_current_blog' )->times( 3 );

		Uninstaller::uninstall( $this->logging_storage() );

		$this->assertSame( array( 1, 7, 9 ), $switched );
		$this->assertSame( 3, count( array_filter( $this->calls, static fn( string $c ): bool => 'delete_directory' === $c ) ) );
	}

	public function test_every_option_constant_in_the_codebase_is_torn_down(): void {
		$declared = array();

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes' ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( preg_match_all( '/const\s+[A-Z_]*OPTION[A-Z_]*\s*=\s*\'(taseo_[a-z_]+)\'/', (string) file_get_contents( $file->getPathname() ), $matches ) ) {
				$declared = array_merge( $declared, $matches[1] );
			}
		}

		$this->assertNotEmpty( $declared, 'The option-constant scan found nothing; the regex has gone stale.' );

		$torn_down = array_merge( Uninstaller::OPTIONS, self::TABLE_OWNED_OPTIONS );

		foreach ( array_unique( $declared ) as $option ) {
			$this->assertContains(
				$option,
				$torn_down,
				"{$option} is written by the plugin but survives uninstall. Add it to Uninstaller::OPTIONS."
			);
		}
	}
}
