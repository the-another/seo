<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Container;
use TheAnother\Plugin\SEO\Installer;
use TheAnother\Plugin\SEO\Plugin;

#[CoversClass( Plugin::class )]
class PluginTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		foreach ( array( Container::class, Plugin::class ) as $singleton ) {
			$reflection = new \ReflectionClass( $singleton );
			$instance   = $reflection->getProperty( 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}

		// start() runs the schema migration and registers a shortcode; stub
		// everything those paths touch.
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_shortcode' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_start_registers_all_services(): void {
		Plugin::get_instance()->start();

		$container = Container::get_instance();

		foreach ( array(
			'settings',
			'indexable_repository',
			'indexable_sync',
			'indexable_backfill',
			'current_context',
			'template_resolver',
			'template_variables',
			'meta_output',
			'social_output',
			'schema_graph',
			'schema_output',
			'breadcrumb_trail',
			'breadcrumb_renderer',
			'metabox',
			'settings_page',
			'blocks',
			'sitemap_file_repository',
			'sitemap_storage',
			'sitemap_file_writer',
			'sitemap_assignment',
			'sitemap_sweeper',
			'sitemap_server',
			'sitemap_families',
			'sitemap_external_urls',
			'verification_output',
			'verification_file_server',
			'analytics_output',
			'meta_pixel_output',
		) as $key ) {
			$this->assertTrue( $container->has( $key ), "Missing service: {$key}" );
		}
	}

	public function test_start_registers_frontend_hooks(): void {
		Plugin::get_instance()->start();

		$hooks = Container::get_instance()->get_hook_manager()->get_registered_hooks();
		$names = array_column( $hooks, 'hook' );

		$this->assertContains( 'save_post', $names );
		$this->assertContains( 'wp_head', $names );
		$this->assertContains( 'pre_get_document_title', $names );
		$this->assertContains( 'init', $names );
		$this->assertContains( 'taseo_indexable_synced', $names );
		$this->assertContains( 'taseo_indexable_deleting', $names );
		$this->assertContains( 'taseo_sitemap_sweep', $names );
		$this->assertContains( 'taseo_permalinks_rebuilt', $names );
		$this->assertContains( 'template_redirect', $names );
		$this->assertContains( 'robots_txt', $names );
		$this->assertContains( 'mod_rewrite_rules', $names );
	}

	public function test_start_flags_backfill_and_flush_when_upgrading_from_pre_sitemap_schema(): void {
		Functions\when( 'get_option' )->alias(
			fn( string $option, $fallback = false ) => 'taseo_db_version' === $option ? '1.0.0' : $fallback
		);

		$flagged = array();
		Functions\when( 'update_option' )->alias(
			function ( string $option, $value = null ) use ( &$flagged ): bool {
				$flagged[] = $option;
				return true;
			}
		);

		Plugin::get_instance()->start();

		$this->assertContains( 'taseo_needs_backfill', $flagged );
		$this->assertContains( 'taseo_needs_rewrite_flush', $flagged );
	}

	public function test_start_does_not_flag_upgrade_backfill_on_fresh_install(): void {
		Functions\when( 'get_option' )->alias(
			fn( string $option, $fallback = false ) => 'taseo_db_version' === $option ? '0' : $fallback
		);

		$flagged = array();
		Functions\when( 'update_option' )->alias(
			function ( string $option, $value = null ) use ( &$flagged ): bool {
				$flagged[] = $option;
				return true;
			}
		);

		Plugin::get_instance()->start();

		$this->assertNotContains( 'taseo_needs_backfill', $flagged );
		$this->assertNotContains( 'taseo_needs_rewrite_flush', $flagged );
	}

	/**
	 * The 1.1.0 upgrade needed a backfill because its new columns held values
	 * that had to be derived for existing rows. The image URL overrides have
	 * no derived value — absent means "not set", which is what NULL already
	 * means — so a 1.1.0 to 1.2.0 upgrade must NOT trigger a full-catalog
	 * resync. This test fails if someone widens the version_compare bound
	 * along with the DB_VERSION bump.
	 */
	public function test_upgrade_from_1_1_0_does_not_flag_a_backfill(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'taseo_db_version' === $name ? '1.1.0' : $default
		);

		$flagged = array();

		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$flagged ): bool {
				$flagged[ $name ] = $value;

				return true;
			}
		);

		$this->invoke_maybe_flag_upgrade_backfill();

		$this->assertArrayNotHasKey( Installer::NEEDS_BACKFILL_OPTION, $flagged );
	}

	/**
	 * Reflection helper for private methods: PluginTest constructs Plugin via
	 * Plugin::get_instance() rather than storing an instance property, so the
	 * helper fetches the singleton the same way.
	 *
	 * @return void
	 */
	private function invoke_maybe_flag_upgrade_backfill(): void {
		$method = new \ReflectionMethod( Plugin::class, 'maybe_flag_upgrade_backfill' );
		$method->setAccessible( true );
		$method->invoke( Plugin::get_instance() );
	}
}
