<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\SettingsPage;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( SettingsPage::class )]
class SettingsPageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $backfill;
	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->backfill = Mockery::mock( IndexableBackfill::class );

		Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$this->page = new SettingsPage( $this->settings, $this->backfill );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_settings_handles_all_field_families(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'enabled_post_types' => array( 'post', 'Product<script>' ),
				'separator'          => '|',
				'title_templates'    => array( 'post:product' => '%%title%%' ),
				'open_graph_enabled' => '1',
				'twitter_enabled'    => '',
				'site_logo_id'       => '42',
				'same_as_urls'       => "https://x.com/acme\n\nhttps://facebook.com/acme",
				'schema_types'       => array( 'post' => 'Article', 'page' => 'HackType' ),
			)
		);

		$this->assertSame( array( 'post', 'product<script>' ), $clean['enabled_post_types'] );
		$this->assertSame( '|', $clean['separator'] );
		$this->assertSame( array( 'post:product' => '%%title%%' ), $clean['title_templates'] );
		$this->assertTrue( $clean['open_graph_enabled'] );
		$this->assertFalse( $clean['twitter_enabled'] );
		$this->assertSame( 42, $clean['site_logo_id'] );
		$this->assertSame( array( 'https://x.com/acme', 'https://facebook.com/acme' ), $clean['same_as_urls'] );
		$this->assertSame( 'Article', $clean['schema_types']['post'] );
		$this->assertSame( 'WebPage', $clean['schema_types']['page'] ); // invalid value coerced to default.
	}

	public function test_handle_rescan_dispatches_full_chain(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wp_safe_redirect' )->once();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo' );

		$this->backfill->shouldReceive( 'dispatch' )->once()->with( 'full' );

		$this->page->handle_rescan( false ); // false = don't exit (testability flag).

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_handle_rescan_bails_without_capability(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->andReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->backfill->shouldNotReceive( 'dispatch' );

		$this->page->handle_rescan( false );

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_handle_save_sanitizes_and_persists_settings(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['taseo_settings']       = array( 'separator' => '|' );

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&updated=1' );
		Functions\expect( 'wp_safe_redirect' )->once()->with(
			Mockery::on( fn( $url ): bool => str_contains( $url, 'updated=1' ) )
		);

		$this->settings->shouldReceive( 'update' )->once()->with(
			Mockery::on( fn( array $v ): bool => '|' === $v['separator'] )
		);

		$this->page->handle_save( false );

		unset( $_POST['taseo_settings_nonce'] );
		unset( $_POST['taseo_settings'] );
	}

	public function test_handle_save_social_tab_forces_unchecked_checkboxes_to_false(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['tab']                  = 'social';
		$_POST['taseo_settings']       = array( 'facebook_app_id' => 'fb123' ); // both checkboxes absent (unchecked).

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&updated=1' );
		Functions\expect( 'wp_safe_redirect' )->once();

		$this->settings->shouldReceive( 'update' )->once()->with(
			Mockery::on(
				function ( array $v ): bool {
					return array_key_exists( 'open_graph_enabled', $v )
						&& false === $v['open_graph_enabled']
						&& array_key_exists( 'twitter_enabled', $v )
						&& false === $v['twitter_enabled'];
				}
			)
		);

		$this->page->handle_save( false );

		unset( $_POST['taseo_settings_nonce'], $_POST['tab'], $_POST['taseo_settings'] );
	}

	public function test_handle_save_types_tab_forces_empty_lists_when_nothing_checked(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['tab']                  = 'types';
		$_POST['taseo_settings']       = array(); // no post type / taxonomy checkboxes submitted.

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&updated=1' );
		Functions\expect( 'wp_safe_redirect' )->once();

		$this->settings->shouldReceive( 'update' )->once()->with(
			Mockery::on(
				function ( array $v ): bool {
					return array_key_exists( 'enabled_post_types', $v )
						&& array() === $v['enabled_post_types']
						&& array_key_exists( 'enabled_taxonomies', $v )
						&& array() === $v['enabled_taxonomies'];
				}
			)
		);

		$this->page->handle_save( false );

		unset( $_POST['taseo_settings_nonce'], $_POST['tab'], $_POST['taseo_settings'] );
	}

	public function test_handle_save_general_tab_preserves_merge_for_absent_booleans(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['tab']                  = 'general';
		$_POST['taseo_settings']       = array( 'separator' => '|' );

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&updated=1' );
		Functions\expect( 'wp_safe_redirect' )->once();

		$this->settings->shouldReceive( 'update' )->once()->with(
			Mockery::on(
				function ( array $v ): bool {
					return ! array_key_exists( 'open_graph_enabled', $v )
						&& ! array_key_exists( 'twitter_enabled', $v )
						&& ! array_key_exists( 'breadcrumb_link_current', $v )
						&& ! array_key_exists( 'breadcrumb_include_taxonomy_ancestors', $v )
						&& ! array_key_exists( 'enabled_post_types', $v )
						&& ! array_key_exists( 'enabled_taxonomies', $v );
				}
			)
		);

		$this->page->handle_save( false );

		unset( $_POST['taseo_settings_nonce'], $_POST['tab'], $_POST['taseo_settings'] );
	}

	public function test_handle_save_bails_without_capability(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['taseo_settings']       = array( 'separator' => '|' );

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->andReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->settings->shouldNotReceive( 'update' );

		$this->page->handle_save( false );

		unset( $_POST['taseo_settings_nonce'] );
		unset( $_POST['taseo_settings'] );
	}

	public function test_detect_conflicting_plugin_finds_yoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '23.0' );
		}

		$this->assertSame( 'Yoast SEO', $this->page->detect_conflicting_plugin() );
	}
}
