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
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
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
	private TemplateVariables $template_variables;
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
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		// Default: no carried-over validation failures. Tests exercising the
		// redirect/render boundary override this expectation directly.
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		// This class uses a real TemplateVariables instance (see below), so
		// its is_object_in_taxonomy() gate (see TemplateVariables::get_for())
		// needs a stub. None of these tests exercise the page/product
		// distinction that gate encodes, so a blanket true is enough here.
		Functions\when( 'is_object_in_taxonomy' )->justReturn( true );

		$this->sitemap_files      = Mockery::mock( SitemapFileRepository::class );
		$this->sitemap_writer     = Mockery::mock( SitemapFileWriter::class );
		$this->sitemap_sweeper    = Mockery::mock( SitemapSweeper::class );
		$this->template_variables = new TemplateVariables();

		$this->page = new SettingsPage(
			$this->settings,
			$this->backfill,
			$this->sitemap_files,
			$this->sitemap_writer,
			$this->sitemap_sweeper,
			$this->template_variables
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_settings_handles_all_field_families(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

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

	/**
	 * Stub the WP escaping/i18n/nonce/button functions render_page() and its
	 * tab renderers call. None carry assertions of their own; they exist so
	 * the full render_page() dispatch doesn't fatal on undefined functions.
	 *
	 * @return void
	 */
	private function stub_render_functions(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		// esc_html() is a real function (tests/Unit/bootstrap.php), defined
		// directly in the bootstrap script rather than through a Patchwork-
		// wrapped include, so Brain\Monkey cannot redefine it — stubbing it
		// throws Patchwork\Exceptions\DefinedTooEarly. Its real
		// implementation (htmlspecialchars) is a no-op on the plain
		// alphanumeric strings and URLs these tests use, so no stub is
		// needed here.
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.com' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
	}

	/**
	 * Wire up Settings getter expectations for the templates tab, defaulting
	 * to no enabled post types/taxonomies and empty templates unless
	 * overridden — the system-page rows render unconditionally regardless.
	 *
	 * @param array<int, string> $post_types Enabled post type slugs.
	 * @param array<int, string> $taxonomies Enabled taxonomy slugs.
	 * @return void
	 */
	private function stub_templates_settings( array $post_types = array(), array $taxonomies = array() ): void {
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( $post_types );
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( $taxonomies );
		$this->settings->shouldReceive( 'get_title_template' )->andReturn( '' );
		$this->settings->shouldReceive( 'get_description_template' )->andReturn( '' );
	}

	/**
	 * Render the settings page and return its markup.
	 *
	 * Defaults to no enabled post types/taxonomies, so only the hardcoded
	 * system-page rows render; pass non-empty lists to exercise the post
	 * type / taxonomy loops too.
	 *
	 * @param array<int, string> $post_types Enabled post type slugs.
	 * @param array<int, string> $taxonomies Enabled taxonomy slugs.
	 * @return string Markup.
	 */
	private function render_page( array $post_types = array(), array $taxonomies = array() ): string {
		$this->stub_render_functions();
		$this->stub_templates_settings( $post_types, $taxonomies );

		ob_start();
		$this->page->render_page();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		return $html;
	}

	/**
	 * Wire up Settings getter expectations for the webmaster tab, defaulting
	 * every verification code/file and tracking ID to '' unless overridden.
	 *
	 * @param array<string, string> $codes Verification codes keyed by engine.
	 * @param array<string, string> $files Verification files keyed by engine.
	 * @param string                $ga4   GA4 measurement ID.
	 * @param string                $gtm   GTM container ID.
	 * @param string                $pixel Meta Pixel ID.
	 * @return void
	 */
	private function stub_webmaster_settings(
		array $codes = array(),
		array $files = array(),
		string $ga4 = '',
		string $gtm = '',
		string $pixel = ''
	): void {
		$codes = array_merge(
			array(
				'google'   => '',
				'bing'     => '',
				'yandex'   => '',
				'yahoo'    => '',
				'facebook' => '',
			),
			$codes
		);

		$files = array_merge(
			array(
				'google' => '',
				'bing'   => '',
				'yandex' => '',
			),
			$files
		);

		foreach ( $codes as $engine => $value ) {
			$this->settings->shouldReceive( 'get_verification_code' )->with( $engine )->andReturn( $value );
		}

		foreach ( $files as $engine => $value ) {
			$this->settings->shouldReceive( 'get_verification_file' )->with( $engine )->andReturn( $value );
		}

		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( $ga4 );
		$this->settings->shouldReceive( 'get_gtm_id' )->andReturn( $gtm );
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( $pixel );
	}

	/**
	 * Render the full tabbed page with the webmaster tab active and return
	 * the output. render_webmaster_tab() is private, so this drives it the
	 * way the real admin screen does: through render_page()'s tab dispatch.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_webmaster_html(): string {
		$this->stub_render_functions();

		$_GET['tab'] = 'webmaster';

		ob_start();
		$this->page->render_page();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		return $html;
	}

	public function test_webmaster_tab_renders_an_input_for_every_verification_and_tracking_key(): void {
		$this->stub_webmaster_settings();

		$html = $this->render_webmaster_html();

		foreach ( array( 'verify_google', 'verify_bing', 'verify_yandex', 'verify_yahoo', 'verify_facebook' ) as $key ) {
			$this->assertStringContainsString( 'name="taseo_settings[' . $key . ']"', $html );
		}

		foreach ( array( 'verify_google_file', 'verify_bing_file', 'verify_yandex_file' ) as $key ) {
			$this->assertStringContainsString( 'name="taseo_settings[' . $key . ']"', $html );
		}

		foreach ( array( 'analytics_ga4_id', 'analytics_gtm_id', 'meta_pixel_id' ) as $key ) {
			$this->assertStringContainsString( 'name="taseo_settings[' . $key . ']"', $html );
		}
	}

	public function test_webmaster_tab_shows_stored_values_as_input_values(): void {
		$this->stub_webmaster_settings(
			array(
				'google'   => 'googletoken',
				'bing'     => 'bingtoken',
				'yandex'   => 'yandextoken',
				'yahoo'    => 'yahootoken',
				'facebook' => 'facebooktoken',
			),
			array(
				'google' => 'google1a2b3c.html',
				'bing'   => 'bingfiletoken',
				'yandex' => 'yandex_9f8e7d.html',
			),
			'G-ABCD1234',
			'GTM-ABCD123',
			'123456789012345'
		);

		$html = $this->render_webmaster_html();

		$this->assertStringContainsString( 'name="taseo_settings[verify_google]" value="googletoken"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[verify_bing]" value="bingtoken"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[verify_yandex]" value="yandextoken"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[verify_yahoo]" value="yahootoken"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[verify_facebook]" value="facebooktoken"', $html );

		$this->assertStringContainsString( 'name="taseo_settings[verify_google_file]" value="google1a2b3c.html"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[verify_bing_file]" value="bingfiletoken"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[verify_yandex_file]" value="yandex_9f8e7d.html"', $html );

		$this->assertStringContainsString( 'name="taseo_settings[analytics_ga4_id]" value="G-ABCD1234"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[analytics_gtm_id]" value="GTM-ABCD123"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[meta_pixel_id]" value="123456789012345"', $html );
	}

	public function test_webmaster_tab_links_a_configured_verification_file(): void {
		$this->stub_webmaster_settings( array(), array( 'google' => 'google1a2b3c.html' ) );

		$html = $this->render_webmaster_html();

		$this->assertStringContainsString(
			'<a href="https://example.com/google1a2b3c.html" target="_blank" rel="noreferrer noopener">https://example.com/google1a2b3c.html</a>',
			$html
		);
	}

	public function test_webmaster_tab_omits_the_file_link_when_no_file_is_configured(): void {
		$this->stub_webmaster_settings();

		$html = $this->render_webmaster_html();

		$this->assertStringNotContainsString( 'target="_blank"', $html );
	}

	public function test_webmaster_tab_bing_file_link_uses_the_fixed_filename_not_the_stored_token(): void {
		$this->stub_webmaster_settings( array(), array( 'bing' => 'BINGTOKEN123' ) );

		$html = $this->render_webmaster_html();

		$this->assertStringContainsString( 'https://example.com/BingSiteAuth.xml', $html );
		$this->assertStringNotContainsString( 'https://example.com/BINGTOKEN123', $html );
	}

	public function test_webmaster_tab_warns_when_both_ga4_and_gtm_are_set(): void {
		$this->stub_webmaster_settings( array(), array(), 'G-ABCD1234', 'GTM-ABCD123' );

		$html = $this->render_webmaster_html();

		$this->assertStringContainsString( 'counted twice', $html );
	}

	public function test_webmaster_tab_does_not_warn_when_only_ga4_is_set(): void {
		$this->stub_webmaster_settings( array(), array(), 'G-ABCD1234', '' );

		$html = $this->render_webmaster_html();

		$this->assertStringNotContainsString( 'counted twice', $html );
	}

	public function test_webmaster_tab_does_not_warn_when_only_gtm_is_set(): void {
		$this->stub_webmaster_settings( array(), array(), '', 'GTM-ABCD123' );

		$html = $this->render_webmaster_html();

		$this->assertStringNotContainsString( 'counted twice', $html );
	}

	public function test_webmaster_tab_does_not_warn_when_neither_ga4_nor_gtm_is_set(): void {
		$this->stub_webmaster_settings();

		$html = $this->render_webmaster_html();

		$this->assertStringNotContainsString( 'counted twice', $html );
	}

	public function test_templates_tab_renders_a_pill_for_each_available_variable(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		$this->assertStringContainsString( 'data-taseo-template-var="%%title%%"', $html );
		$this->assertStringContainsString( 'class="button button-small"', $html );
	}

	public function test_templates_tab_no_longer_prints_the_hardcoded_variable_line(): void {
		$_GET['tab'] = 'templates';

		$this->assertStringNotContainsString( 'Available variables:', $this->render_page() );
	}

	public function test_system_page_rows_offer_only_the_base_variables(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		// The 404 row is a system page: no excerpt, date, or primary_category.
		$this->assertStringContainsString( 'taseo_settings[title_templates][system_page:404]', $html );
		$this->assertStringNotContainsString( 'data-taseo-template-var="%%price%%"', $html );
	}

	public function test_post_type_rows_offer_excerpt_date_and_primary_category(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'taseo_settings[title_templates][post:post]', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%excerpt%%"', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%date%%"', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%primary_category%%"', $html );
	}

	public function test_term_rows_offer_excerpt_but_not_date_or_primary_category(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page( array(), array( 'category' ) );

		$this->assertStringContainsString( 'taseo_settings[title_templates][term:category]', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%excerpt%%"', $html );
		$this->assertStringNotContainsString( 'data-taseo-template-var="%%date%%"', $html );
		$this->assertStringNotContainsString( 'data-taseo-template-var="%%primary_category%%"', $html );
	}

	public function test_assets_enqueue_only_on_this_settings_page(): void {
		$enqueued = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_options_page' )->justReturn( 'settings_page_taseo' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '', array $deps = array() ) use ( &$enqueued ): void {
				$enqueued[ $handle ] = $deps;
			}
		);

		$this->page->register_menu();

		$this->page->enqueue_assets( 'edit.php' );
		$this->assertSame( array(), $enqueued, 'must not enqueue on unrelated admin screens' );

		$this->page->enqueue_assets( 'settings_page_taseo' );
		$this->assertArrayHasKey( 'taseo-settings', $enqueued );
		$this->assertContains( 'jquery-ui-autocomplete', $enqueued['taseo-settings'] );
	}

	public function test_a_template_using_available_variables_saves(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => '%%title%% %%sep%% %%sitename%%' ) ),
			'templates'
		);

		$this->assertSame( '%%title%% %%sep%% %%sitename%%', $clean['title_templates']['post:page'] );
	}

	public function test_an_unknown_variable_rejects_only_its_own_row(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn(
			array( 'post:product' => '%%title%%' )
		);
		Functions\expect( 'add_settings_error' )->once();

		$clean = $this->page->sanitize_settings(
			array(
				'title_templates' => array(
					'post:product' => '%%title%% %%discount%%',
					'post:page'    => '%%title%% %%sep%%',
				),
			),
			'templates'
		);

		$this->assertSame( '%%title%%', $clean['title_templates']['post:product'], 'rejected row keeps its stored value' );
		$this->assertSame( '%%title%% %%sep%%', $clean['title_templates']['post:page'], 'sibling row still saves' );
	}

	public function test_a_variable_from_the_wrong_context_is_rejected(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );
		Functions\expect( 'add_settings_error' )->once();

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => '%%title%% %%price%%' ) ),
			'templates'
		);

		$this->assertArrayNotHasKey( 'post:page', $clean['title_templates'] );
	}

	public function test_a_template_without_variables_is_valid(): void {
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => 'Just a static title' ) ),
			'templates'
		);

		$this->assertSame( 'Just a static title', $clean['title_templates']['post:page'] );
	}

	/**
	 * Call SettingsPage's private template_row_label().
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return string Label.
	 */
	private function invoke_row_label( string $object_type, string $object_subtype ): string {
		$method = new \ReflectionMethod( SettingsPage::class, 'template_row_label' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->page, $object_type, $object_subtype );
	}

	public function test_template_row_label_uses_the_registered_post_type_name(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				return 'product' === $type
					? (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
					: null;
			}
		);

		$this->assertSame( 'Products', $this->invoke_row_label( 'post', 'product' ) );
	}

	public function test_template_row_label_uses_the_registered_taxonomy_name(): void {
		Functions\when( 'get_taxonomy' )->alias(
			static function ( string $tax ): ?object {
				return 'post_tag' === $tax
					? (object) array( 'labels' => (object) array( 'name' => 'Tags' ) )
					: null;
			}
		);

		$this->assertSame( 'Tags', $this->invoke_row_label( 'term', 'post_tag' ) );
	}

	public function test_template_row_label_names_the_system_pages(): void {
		$this->assertSame( 'Home page', $this->invoke_row_label( 'system_page', 'home' ) );
		$this->assertSame( 'Search results', $this->invoke_row_label( 'system_page', 'search' ) );
		$this->assertSame( 'Not found (404)', $this->invoke_row_label( 'system_page', '404' ) );
	}

	public function test_template_row_label_falls_back_to_the_slug_for_a_deregistered_type(): void {
		Functions\when( 'get_post_type_object' )->justReturn( null );

		$this->assertSame( 'gone_type', $this->invoke_row_label( 'post', 'gone_type' ) );
	}

	public function test_template_row_label_falls_back_to_the_slug_for_a_deregistered_taxonomy(): void {
		Functions\when( 'get_taxonomy' )->justReturn( null );

		$this->assertSame( 'gone_tax', $this->invoke_row_label( 'term', 'gone_tax' ) );
	}

	public function test_template_row_label_falls_back_to_the_slug_for_an_unrecognised_object_type(): void {
		$this->assertSame( 'sidebar', $this->invoke_row_label( 'widget', 'sidebar' ) );
	}

	public function test_template_row_label_falls_back_to_the_slug_for_an_unrecognised_system_page_key(): void {
		$this->assertSame( 'archive', $this->invoke_row_label( 'system_page', 'archive' ) );
	}
}
