<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Container;
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
			'sitemap_file_writer',
			'sitemap_assignment',
			'sitemap_sweeper',
			'sitemap_server',
			'verification_output',
			'verification_file_server',
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
}
