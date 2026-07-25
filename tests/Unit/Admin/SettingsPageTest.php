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
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;

#[CoversClass( SettingsPage::class )]
class SettingsPageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $backfill;
	private $sitemap_files;
	private $sitemap_writer;
	private $sitemap_sweeper;
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
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ): string => $url . '&' . $key . '=' . $value
		);

		$this->sitemap_files   = Mockery::mock( SitemapFileRepository::class );
		$this->sitemap_writer  = Mockery::mock( SitemapFileWriter::class );
		$this->sitemap_sweeper = Mockery::mock( SitemapSweeper::class );

		$this->page = new SettingsPage(
			$this->settings,
			$this->backfill,
			$this->sitemap_files,
			$this->sitemap_writer,
			$this->sitemap_sweeper
		);
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

	public function test_sanitize_settings_clamps_sitemap_max_links(): void {
		$this->assertSame( 1000, $this->page->sanitize_settings( array( 'sitemap_max_links' => '5000' ) )['sitemap_max_links'] );
		$this->assertSame( 1, $this->page->sanitize_settings( array( 'sitemap_max_links' => '0' ) )['sitemap_max_links'] );
		$this->assertSame( 500, $this->page->sanitize_settings( array( 'sitemap_max_links' => '500' ) )['sitemap_max_links'] );
	}

	public function test_sanitize_settings_sitemap_tab_forces_unchecked_toggle_off(): void {
		$clean = $this->page->sanitize_settings( array( 'sitemap_max_links' => '1000' ), 'sitemap' );

		$this->assertArrayHasKey( 'sitemap_enabled', $clean );
		$this->assertFalse( $clean['sitemap_enabled'] );
	}

	public function test_handle_sitemap_regenerate_dispatches_full_regeneration(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wp_safe_redirect' )->once();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&tab=sitemap' );

		$this->sitemap_sweeper->shouldReceive( 'dispatch_full_regeneration' )->once();

		$this->page->handle_sitemap_regenerate( false );

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_handle_sitemap_regenerate_bails_without_capability(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->andReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->sitemap_sweeper->shouldNotReceive( 'dispatch_full_regeneration' );

		$this->page->handle_sitemap_regenerate( false );

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_sitemap_storage_notice_prints_when_uploads_unwritable(): void {
		Functions\when( 'esc_html__' )->returnArg();

		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->sitemap_writer->shouldReceive( 'is_writable' )->andReturn( false );

		ob_start();
		$this->page->maybe_print_sitemap_storage_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
	}

	public function test_sitemap_storage_notice_silent_when_writable(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->sitemap_writer->shouldReceive( 'is_writable' )->andReturn( true );

		ob_start();
		$this->page->maybe_print_sitemap_storage_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_sanitizes_verification_codes_from_pasted_meta_tags(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'verify_google'   => '<meta name="google-site-verification" content="AbC123_-xyz" />',
				'verify_bing'     => '  BINGTOKEN  ',
				'verify_yandex'   => 'yan"dex<token>',
				'verify_yahoo'    => 'yahootoken',
				'verify_facebook' => 'metatoken',
			),
			'webmaster'
		);

		$this->assertSame( 'AbC123_-xyz', $clean['verify_google'] );
		$this->assertSame( 'BINGTOKEN', $clean['verify_bing'] );
		$this->assertSame( 'yandextoken', $clean['verify_yandex'] );
		$this->assertSame( 'yahootoken', $clean['verify_yahoo'] );
		$this->assertSame( 'metatoken', $clean['verify_facebook'] );
	}

	public function test_accepts_valid_verification_filenames(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'verify_google_file' => 'google1a2b3c.html',
				'verify_bing_file'   => 'BINGTOKEN123',
				'verify_yandex_file' => 'yandex_9f8e7d.html',
			),
			'webmaster'
		);

		$this->assertSame( 'google1a2b3c.html', $clean['verify_google_file'] );
		$this->assertSame( 'BINGTOKEN123', $clean['verify_bing_file'] );
		$this->assertSame( 'yandex_9f8e7d.html', $clean['verify_yandex_file'] );
	}

	public function test_rejects_verification_filenames_containing_paths(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'verify_google_file' => '../wp-config.php',
				'verify_yandex_file' => 'yandex_x.html/../../etc/passwd',
			),
			'webmaster'
		);

		$this->assertSame( '', $clean['verify_google_file'] );
		$this->assertSame( '', $clean['verify_yandex_file'] );
	}

	public function test_rejects_a_verification_filename_with_the_wrong_prefix(): void {
		$clean = $this->page->sanitize_settings(
			array( 'verify_google_file' => 'notgoogle123.html' ),
			'webmaster'
		);

		$this->assertSame( '', $clean['verify_google_file'] );
	}

	public function test_normalizes_and_validates_tracking_ids(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'analytics_ga4_id' => ' g-abcd1234 ',
				'analytics_gtm_id' => 'gtm-xyz789',
				'meta_pixel_id'    => ' 0123456789012 ',
			),
			'webmaster'
		);

		$this->assertSame( 'G-ABCD1234', $clean['analytics_ga4_id'] );
		$this->assertSame( 'GTM-XYZ789', $clean['analytics_gtm_id'] );
		$this->assertSame( '0123456789012', $clean['meta_pixel_id'] );
	}

	public function test_rejects_malformed_tracking_ids(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'analytics_ga4_id' => 'UA-12345-1',
				'analytics_gtm_id' => 'GTM',
				'meta_pixel_id'    => '12345',
			),
			'webmaster'
		);

		$this->assertSame( '', $clean['analytics_ga4_id'] );
		$this->assertSame( '', $clean['analytics_gtm_id'] );
		$this->assertSame( '', $clean['meta_pixel_id'] );
	}

	public function test_clearing_a_verification_field_clears_the_stored_key(): void {
		$clean = $this->page->sanitize_settings( array( 'verify_google' => '' ), 'webmaster' );

		$this->assertSame( '', $clean['verify_google'] );
	}

	public function test_save_redirect_preserves_the_active_tab(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['tab']                  = 'webmaster';
		$_POST['taseo_settings']       = array( 'verify_google' => 'googletoken' );

		$redirected = '';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ) => $url . '&' . $key . '=' . $value
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ) use ( &$redirected ): void {
				$redirected = $location;
			}
		);

		$this->settings->shouldReceive( 'update' )->once();

		$this->page->handle_save( false );

		$this->assertStringContainsString( 'tab=webmaster', $redirected );

		unset( $_POST['taseo_settings_nonce'], $_POST['tab'], $_POST['taseo_settings'] );
	}
}
