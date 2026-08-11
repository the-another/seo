<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;
use TheAnother\Plugin\SEO\Admin\SettingsPage;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Meta\CustomPages;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapAssignment;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;

#[CoversClass( SettingsPage::class )]
class SettingsPageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $backfill;
	private $sitemap_files;
	private $sitemap_storage;
	private $sitemap_sweeper;
	private TemplateVariables $template_variables;
	private $custom_pages;
	private $sitemap_families;
	private $sitemap_assignment;
	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->backfill = Mockery::mock( IndexableBackfill::class );

		Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		// SettingsPage::sanitize_template() calls these instead of
		// sanitize_text_field() (see its docblock); tests exercising actual
		// tag-stripping/UTF-8 behaviour override these with faithful aliases.
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
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
		$this->sitemap_storage    = Mockery::mock( SitemapStorage::class );
		$this->sitemap_sweeper    = Mockery::mock( SitemapSweeper::class );
		$this->template_variables = new TemplateVariables( new PostSubtypes() );

		$this->custom_pages = Mockery::mock( CustomPages::class );
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array() )->byDefault();

		$this->sitemap_families   = Mockery::mock( SitemapFamilies::class );
		$this->sitemap_assignment = Mockery::mock( SitemapAssignment::class );
		$this->sitemap_families->shouldReceive( 'all' )->andReturn( array() )->byDefault();
		// The sitemap toggle list spans post subtypes, taxonomies, and
		// families, so the save path now reads the first two as well. Tests
		// asserting on specific rows override these.
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array() )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array() )->byDefault();
		// handle_save() always reads this before update() regardless of tab;
		// only the sitemap-toggle tests below care about its value.
		$this->settings->shouldReceive( 'get_disabled_sitemap_families' )->andReturn( array() )->byDefault();

		$this->page = new SettingsPage(
			$this->settings,
			$this->backfill,
			$this->sitemap_files,
			$this->sitemap_storage,
			$this->sitemap_sweeper,
			$this->template_variables,
			$this->custom_pages,
			$this->sitemap_families,
			$this->sitemap_assignment,
			new PostSubtypes()
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

	public function test_sanitize_stores_the_image_url_overrides(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'default_social_image_url' => 'https://cdn.example.com/social.jpg',
				'site_logo_url'            => 'https://cdn.example.com/logo.png',
			),
			'social'
		);

		$this->assertSame( 'https://cdn.example.com/social.jpg', $clean['default_social_image_url'] );
		$this->assertSame( 'https://cdn.example.com/logo.png', $clean['site_logo_url'] );
	}

	/**
	 * The URL override is a sibling of the ID, not a replacement: saving one
	 * must not disturb the other.
	 */
	public function test_sanitize_keeps_the_attachment_ids_alongside_the_urls(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'default_social_image_id'  => '42',
				'default_social_image_url' => 'https://cdn.example.com/social.jpg',
			),
			'social'
		);

		$this->assertSame( 42, $clean['default_social_image_id'] );
		$this->assertSame( 'https://cdn.example.com/social.jpg', $clean['default_social_image_url'] );
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

	public function test_sanitize_sitemap_tab_derives_disabled_families_from_checkboxes(): void {
		$this->sitemap_families->shouldReceive( 'all' )->andReturn(
			array(
				'vendor_store'        => 'Vendor stores',
				'auctioneer_location' => 'Auctioneer locations',
				'vendor_items'        => 'Vendor items',
			)
		);

		$clean = $this->page->sanitize_settings(
			array(
				'sitemap_enabled'  => '1',
				'sitemap_families' => array( 'vendor_store' ),
			),
			'sitemap'
		);

		$this->assertSame( array( 'auctioneer_location', 'vendor_items' ), $clean['sitemap_disabled_families'] );
	}

	public function test_sanitize_sitemap_tab_prunes_unregistered_keys(): void {
		// A previously stored 'ghost_family' from a deactivated provider is
		// absent from the registry, so it cannot appear in the derived list.
		$this->sitemap_families->shouldReceive( 'all' )->andReturn( array( 'vendor_store' => 'Vendor stores' ) );

		$clean = $this->page->sanitize_settings(
			array(
				'sitemap_enabled'  => '1',
				'sitemap_families' => array(),
			),
			'sitemap'
		);

		$this->assertSame( array( 'vendor_store' ), $clean['sitemap_disabled_families'] );
	}

	public function test_sanitize_other_tabs_do_not_touch_disabled_families(): void {
		$clean = $this->page->sanitize_settings( array( 'separator' => '|' ), 'general' );

		$this->assertArrayNotHasKey( 'sitemap_disabled_families', $clean );
	}

	public function test_save_calls_toggle_transitions_for_changed_families(): void {
		// Arrange the existing valid-save stubs this file already uses, with
		// $_POST['tab'] = 'sitemap' and $_POST['taseo_settings'] carrying
		// sitemap_families = ['vendor_store'] while the registry holds
		// vendor_store + auctioneer_location and the STORED disabled list is
		// ['vendor_store'] (so: vendor_store re-enabled, auctioneer_location
		// newly disabled).
		$_POST['taseo_settings_nonce'] = 'nonce';
		$_POST['tab']                  = 'sitemap';
		$_POST['taseo_settings']       = array(
			'sitemap_enabled'  => '1',
			'sitemap_families' => array( 'vendor_store' ),
		);

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&updated=1' );
		Functions\expect( 'wp_safe_redirect' )->once();

		$this->sitemap_families->shouldReceive( 'all' )->andReturn(
			array(
				'vendor_store'        => 'Vendor stores',
				'auctioneer_location' => 'Auctioneer locations',
			)
		);
		// Settings mock: before update → ['vendor_store'], after → ['auctioneer_location'].
		$this->settings->shouldReceive( 'get_disabled_sitemap_families' )
			->twice()
			->andReturn( array( 'vendor_store' ), array( 'auctioneer_location' ) );
		$this->settings->shouldReceive( 'update' )->once();

		$this->sitemap_assignment->shouldReceive( 'handle_family_disabled' )->once()->with( 'auctioneer_location' );
		$this->sitemap_assignment->shouldReceive( 'handle_family_enabled' )->once()->with( 'vendor_store' );

		$this->page->handle_save( false );

		unset( $_POST['taseo_settings_nonce'], $_POST['tab'], $_POST['taseo_settings'] );
	}

	/**
	 * Render the full tabbed page with the sitemap tab active and return the
	 * output. render_sitemap_tab() is private, so this drives it the way the
	 * real admin screen does: through render_page()'s tab dispatch.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_sitemap_html(): string {
		$this->stub_render_functions();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->returnArg();

		$_GET['tab'] = 'sitemap';

		ob_start();
		$this->page->render_page();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		return $html;
	}

	/**
	 * The disabled-families forwarding the status panel needs: a disabled
	 * family's suspended chunks are permanently dirty, so count_dirty()
	 * (and thus the "Files awaiting regeneration" figure) must exclude them
	 * rather than show a count that can never drain.
	 */
	public function test_sitemap_tab_forwards_disabled_families_to_the_status_summary(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->settings->shouldReceive( 'get_disabled_sitemap_families' )->andReturn( array( 'vendor_store' ) );

		$this->sitemap_files->shouldReceive( 'get_status_summary' )
			->once()
			->with( array( 'vendor_store' ) )
			->andReturn(
				array(
					'subtypes'       => array(),
					'dirty'          => 3,
					'last_generated' => null,
				)
			);

		$html = $this->render_sitemap_html();

		$this->assertStringContainsString( '<strong>3</strong>', $html );
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
		$this->sitemap_storage->shouldReceive( 'is_writable' )->andReturn( false );

		ob_start();
		$this->page->maybe_print_sitemap_storage_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
	}

	public function test_sitemap_storage_notice_silent_when_writable(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->sitemap_storage->shouldReceive( 'is_writable' )->andReturn( true );

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

	/**
	 * The pre-pill implementation printed one static sentence listing every
	 * token as body text, which could drift from the registry. What must not
	 * come back is that hardcoded run of tokens — not the words "Available
	 * variables", which now legitimately head the pills. Asserting the
	 * distinctive prefix rather than the heading keeps this test about the
	 * drift hazard it was written for.
	 */
	public function test_templates_tab_no_longer_prints_the_hardcoded_variable_line(): void {
		$_GET['tab'] = 'templates';

		$this->assertStringNotContainsString(
			'Available variables: %%title%% %%sitename%%',
			$this->render_page()
		);
	}

	public function test_templates_tab_pills_show_the_label_not_the_raw_token(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		// The token stays in the data attribute — that is what the admin
		// script and the e2e selectors read — while the visible text is the
		// human label, matching the chip a click on this pill inserts.
		$this->assertStringContainsString( 'data-taseo-template-var="%%sitename%%"', $html );
		$this->assertStringContainsString( '>Site title</button>', $html );
		$this->assertStringNotContainsString( '>%%sitename%%</button>', $html );
	}

	/**
	 * The pills render below the last input in the row, so without a heading
	 * they read as belonging to the meta description alone — which is
	 * backwards, since a click lands in whichever field was last focused and
	 * defaults to the title.
	 */
	public function test_templates_tab_labels_the_variable_pills(): void {
		$_GET['tab'] = 'templates';
		Functions\when( 'get_post_type_object' )->justReturn( null );

		// A post-type row, because only rows with both fields carry the
		// two-field wording; system pages get the single-field variant.
		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'Available variables', $html );
		$this->assertStringContainsString( 'these apply to both fields above', $html );
	}

	/**
	 * System pages have a title field and no description field, so their pill
	 * heading must not tell the administrator both fields accept these.
	 */
	public function test_system_page_pill_heading_does_not_claim_a_description_field(): void {
		$_GET['tab'] = 'templates';

		// No post types or taxonomies, so every row rendered is a system page.
		$html = $this->render_page();

		$this->assertStringContainsString( 'Available variables', $html );
		$this->assertStringNotContainsString( 'these apply to both fields above', $html );
	}

	public function test_templates_tab_splits_into_four_titled_sections(): void {
		$_GET['tab'] = 'templates';
		$html        = $this->render_page();

		$this->assertStringContainsString( '<h2 id="taseo-post-types">Post types</h2>', $html );
		$this->assertStringContainsString( '<h2 id="taseo-taxonomies">Taxonomies</h2>', $html );
		$this->assertStringContainsString( '<h2 id="taseo-system-pages">System pages</h2>', $html );
		$this->assertStringContainsString( '<h2 id="taseo-custom-pages">Custom pages</h2>', $html );
		$this->assertSame( 3, substr_count( $html, '<hr />' ), 'separators sit between the four sections, not after the last' );
		// Custom pages is empty by default here, so its section renders the
		// empty state rather than a fourth table.
		$this->assertSame( 3, substr_count( $html, '<table class="form-table">' ) );
	}

	public function test_system_pages_section_explains_it_has_no_description_field(): void {
		$_GET['tab'] = 'templates';
		$this->assertStringContainsString(
			'System pages take a title template only.',
			$this->render_page()
		);
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
		Functions\when( 'get_post_type_object' )->justReturn( null );

		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'taseo_settings[title_templates][post:post]', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%excerpt%%"', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%date%%"', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%primary_category%%"', $html );
	}

	public function test_term_rows_offer_excerpt_but_not_date_or_primary_category(): void {
		$_GET['tab'] = 'templates';
		Functions\when( 'get_taxonomy' )->justReturn( null );

		$html = $this->render_page( array(), array( 'category' ) );

		$this->assertStringContainsString( 'taseo_settings[title_templates][term:category]', $html );
		$this->assertStringContainsString( 'data-taseo-template-var="%%excerpt%%"', $html );
		$this->assertStringNotContainsString( 'data-taseo-template-var="%%date%%"', $html );
		$this->assertStringNotContainsString( 'data-taseo-template-var="%%primary_category%%"', $html );
	}

	public function test_a_registered_custom_page_renders_a_row(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$html = $this->render_page();

		$this->assertStringContainsString(
			'name="taseo_settings[title_templates][custom_page:checkout]"',
			$html
		);
		$this->assertStringContainsString(
			'name="taseo_settings[description_templates][custom_page:checkout]"',
			$html
		);
	}

	public function test_a_custom_page_row_shows_its_label_and_its_key(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$html = $this->render_page();

		$this->assertStringContainsString( '>Checkout<', $html );
		$this->assertStringContainsString( '<code>custom_page:checkout</code>', $html );
	}

	/**
	 * Registering a row without claiming a request produces a template that
	 * never renders, so the empty state has to name both filters — that is
	 * the mistake it exists to prevent.
	 */
	public function test_the_empty_state_documents_both_filters(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array() );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Custom pages', $html );
		$this->assertStringContainsString( 'taseo_custom_pages', $html );
		$this->assertStringContainsString( 'taseo_custom_page_context', $html );
	}

	public function test_the_empty_state_is_absent_once_a_page_is_registered(): void {
		$_GET['tab'] = 'templates';
		$this->custom_pages->shouldReceive( 'all' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$this->assertStringNotContainsString( 'taseo_custom_page_context', $this->render_page() );
	}

	/**
	 * The path enqueue_assets() reads its dependencies and version from.
	 *
	 * @return string Absolute path.
	 */
	private function settings_asset_file(): string {
		return THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/settings/index.asset.php';
	}

	/**
	 * The path ImageField::enqueue() reads its dependencies and version
	 * from — enqueue_assets() calls it at the end of its own guard.
	 *
	 * @return string Absolute path.
	 */
	private function media_picker_asset_file(): string {
		return THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/media-picker/index.asset.php';
	}

	/**
	 * Put a known built asset file at $file, returning a callable that
	 * restores whatever was there before (including its absence, and the
	 * directory this created for it).
	 *
	 * @param string $file         Absolute path to write.
	 * @param array  $dependencies Dependency handles for the asset file.
	 * @return callable Restores the previous state.
	 */
	private function write_built_asset_file( string $file, array $dependencies ): callable {
		$existing = file_exists( $file ) ? (string) file_get_contents( $file ) : null;
		$made_dir = ! is_dir( dirname( $file ) );

		if ( $made_dir ) {
			mkdir( dirname( $file ), 0777, true );
		}

		file_put_contents(
			$file,
			'<?php return array(\'dependencies\' => ' . var_export( $dependencies, true ) . ", 'version' => 'testassetversion');"
		);

		return function () use ( $file, $existing, $made_dir ): void {
			if ( null === $existing ) {
				unlink( $file );

				if ( $made_dir ) {
					rmdir( dirname( $file ) );
				}

				return;
			}

			file_put_contents( $file, $existing );
		};
	}

	/**
	 * Put known asset files where both enqueue_assets() and the
	 * ImageField::enqueue() it delegates to expect built ones.
	 *
	 * dist/ is build output and is not in the source tree, so neither its
	 * presence nor its absence can be assumed: these tests own the files for
	 * their duration and put everything back exactly as they found it.
	 *
	 * @return callable Restores the previous state.
	 */
	private function with_built_asset_file(): callable {
		$restore_settings     = $this->write_built_asset_file( $this->settings_asset_file(), array( 'wp-element', 'wp-rich-text' ) );
		$restore_media_picker = $this->write_built_asset_file( $this->media_picker_asset_file(), array() );

		return function () use ( $restore_settings, $restore_media_picker ): void {
			$restore_media_picker();
			$restore_settings();
		};
	}

	/**
	 * Register the settings page and enqueue on its own screen — the shared
	 * setup every enqueue-side-effect test needs, short of the asserting
	 * itself.
	 *
	 * @return void
	 */
	private function enqueue_on_our_page(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_options_page' )->justReturn( 'settings_page_taseo' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		$this->page->register_menu();
		$this->page->enqueue_assets( 'settings_page_taseo' );
	}

	public function test_assets_enqueue_only_on_this_settings_page(): void {
		$enqueued = array();
		$styles   = array();
		$restore  = $this->with_built_asset_file();

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_options_page' )->justReturn( 'settings_page_taseo' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '', array $deps = array(), $ver = false ) use ( &$enqueued ): void {
				$enqueued[ $handle ] = array(
					'src'     => $src,
					'deps'    => $deps,
					'version' => $ver,
				);
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( string $handle ) use ( &$styles ): void {
				$styles[] = $handle;
			}
		);
		// enqueue_assets() delegates to ImageField::enqueue() at the end of
		// its guard, which calls this — not under test here.
		Functions\when( 'wp_enqueue_media' )->justReturn( null );

		try {
			$this->page->register_menu();

			$this->page->enqueue_assets( 'edit.php' );
			$this->assertSame( array(), $enqueued, 'must not enqueue on unrelated admin screens' );

			$this->page->enqueue_assets( 'settings_page_taseo' );
			$this->assertArrayHasKey( 'taseo-settings', $enqueued );

			// The built bundle, not a hand-maintained source file, and its
			// dependencies and version come from the generated asset file
			// rather than from a list here that could drift from the build.
			$this->assertStringEndsWith( 'dist/settings/index.js', $enqueued['taseo-settings']['src'] );
			$this->assertSame( array( 'wp-element', 'wp-rich-text' ), $enqueued['taseo-settings']['deps'] );
			$this->assertSame( 'testassetversion', $enqueued['taseo-settings']['version'] );

			// Core's own stylesheet, so the autocomplete popover is readable
			// without this plugin shipping any CSS.
			$this->assertSame( array( 'wp-components' ), $styles );
		} finally {
			$restore();
		}
	}

	public function test_assets_are_skipped_when_the_bundle_is_not_built(): void {
		$file    = $this->settings_asset_file();
		$backup  = file_exists( $file ) ? (string) file_get_contents( $file ) : null;
		$enqueued = array();

		if ( null !== $backup ) {
			unlink( $file );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_options_page' )->justReturn( 'settings_page_taseo' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle ) use ( &$enqueued ): void {
				$enqueued[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		try {
			$this->page->register_menu();
			$this->page->enqueue_assets( 'settings_page_taseo' );

			// A source checkout that has never been built must render a
			// perfectly usable tab with plain inputs, not fatal on `require`.
			$this->assertSame( array(), $enqueued );
		} finally {
			if ( null !== $backup ) {
				file_put_contents( $file, $backup );
			}
		}
	}

	/**
	 * wp.media is undefined without wp_enqueue_media(), so the picker would
	 * silently do nothing.
	 */
	public function test_the_settings_page_enqueues_the_media_library(): void {
		$called  = false;
		$restore = $this->with_built_asset_file();

		Functions\when( 'wp_enqueue_media' )->alias(
			static function () use ( &$called ): void {
				$called = true;
			}
		);

		$this->enqueue_on_our_page();

		$restore();

		$this->assertTrue( $called );
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
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
		);
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
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Pages' ) )
		);
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );
		Functions\expect( 'add_settings_error' )->once();

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:page' => '%%title%% %%price%%' ) ),
			'templates'
		);

		$this->assertArrayNotHasKey( 'post:page', $clean['title_templates'] );
	}

	public function test_validation_error_names_the_row_in_plain_language(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
		);
		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$message = '';

		Functions\when( 'add_settings_error' )->alias(
			function ( string $slug, string $code, string $text ) use ( &$message ): void {
				$message = $text;
			}
		);

		$this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:product' => '%%title%% %%discount%%' ) ),
			'templates'
		);

		$this->assertStringContainsString( 'Products', $message );
		$this->assertStringNotContainsString( 'post:product', $message );
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
	 * Regression test for the hex-prefixed-slug corruption bug.
	 *
	 * WordPress's sanitize_text_field() ends (via _sanitize_text_fields() in
	 * wp-includes/formatting.php) with an unconditional loop that strips any
	 * substring matching /%[a-f0-9]{2}/i, treating it as a stray
	 * percent-encoded byte. `%%date%%` contains such a substring at offset 1
	 * ("%da"), so sanitize_text_field( '%%date%%' ) silently returns
	 * '%te%%'. The row then saves corrupted, and because the mangled text no
	 * longer contains a %%token%%, extract_variables() finds nothing to
	 * reject, so validation never notices either.
	 *
	 * This test stubs sanitize_text_field() with a faithful port of that
	 * real WordPress behaviour instead of this file's usual no-op
	 * returnArg() stub, so it exercises the actual defect rather than
	 * masking it. It also stubs wp_check_invalid_utf8() and
	 * wp_strip_all_tags(), which the fix uses in place of
	 * sanitize_text_field().
	 *
	 * Runs in its own process: stubbing wc_get_product() (needed so
	 * %%price%%/%%sku%% are valid for the post:product row under test)
	 * permanently flips function_exists( 'wc_get_product' ) to true for the
	 * rest of the process — same hazard documented in
	 * TemplateVariablesTest::test_products_add_price_and_sku_with_woocommerce().
	 */
	#[RunInSeparateProcess]
	public function test_every_registry_variable_survives_a_save_round_trip_byte_identically(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		// Faithful port of wp-includes/formatting.php's _sanitize_text_fields(),
		// specifically its unconditional percent-octet strip — the real source
		// of the corruption, not the no-op returnArg() the rest of this file uses.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $str ): string {
				$filtered = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) );
				$found    = false;

				while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
					$filtered = str_replace( $match[0], '', $filtered );
					$found    = true;
				}

				if ( $found ) {
					$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
				}

				return $filtered;
			}
		);

		// Stand-ins for the functions the fix uses instead of sanitize_text_field().
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text, $remove_breaks = false ): string {
				$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
				$text = strip_tags( $text );

				if ( $remove_breaks ) {
					$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
				}

				return trim( (string) $text );
			}
		);

		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		// All ten TemplateVariables::get_for( 'post', 'product' ) slugs. date
		// is the only one whose first two characters are both in [a-f0-9],
		// which is what makes sanitize_text_field()'s percent-octet loop
		// treat "%da" as a stray encoded byte and eat it; the other nine are
		// here as a regression net for any future hex-prefixed slug.
		$template = '%%date%% %%sep%% %%sitename%% %%title%% %%tagline%% %%page%% '
			. '%%excerpt%% %%primary_category%% %%price%% %%sku%%';

		$clean = $this->page->sanitize_settings(
			array( 'title_templates' => array( 'post:product' => $template ) ),
			'templates'
		);

		$this->assertSame( $template, $clean['title_templates']['post:product'] );
	}

	/**
	 * Guards against the fix regressing into a no-op sanitizer: templates
	 * render straight into a <title> element and a meta tag, so tags must
	 * still be stripped and newlines/whitespace still collapsed.
	 */
	public function test_template_sanitizer_still_strips_tags_and_collapses_whitespace(): void {
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text, $remove_breaks = false ): string {
				$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
				$text = strip_tags( $text );

				if ( $remove_breaks ) {
					$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
				}

				return trim( (string) $text );
			}
		);

		$this->settings->shouldReceive( 'get' )->with( 'title_templates', array() )->andReturn( array() );

		$clean = $this->page->sanitize_settings(
			array(
				'title_templates' => array(
					'post:page' => "  <script>alert(1)</script><b>Hello</b>\n\n  World  \t",
				),
			),
			'templates'
		);

		$this->assertSame( 'Hello World', $clean['title_templates']['post:page'] );
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

	public function test_template_rows_show_human_labels_with_the_slug_beneath(): void {
		$_GET['tab'] = 'templates';

		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Products' ) )
		);

		$html = $this->render_page( array( 'product' ) );

		$this->assertStringContainsString( 'Products', $html );
		$this->assertStringContainsString( '<code>post:product</code>', $html );
	}

	public function test_taxonomy_rows_show_human_labels_with_the_slug_beneath(): void {
		$_GET['tab'] = 'templates';

		Functions\when( 'get_taxonomy' )->alias(
			static fn( string $tax ): object => (object) array( 'labels' => (object) array( 'name' => 'Categories' ) )
		);

		$html = $this->render_page( array(), array( 'category' ) );

		$this->assertStringContainsString( 'Categories', $html );
		$this->assertStringContainsString( '<code>term:category</code>', $html );
	}

	public function test_system_page_rows_show_their_own_names_not_slugs(): void {
		$_GET['tab'] = 'templates';
		$html = $this->render_page();

		$this->assertStringContainsString( 'Not found (404)', $html );
		$this->assertStringContainsString( '<code>system_page:404</code>', $html );
	}

	/**
	 * The post and term loops each wrap their row in a <fieldset> whose
	 * screen-reader legend names the row, since the row has more than one
	 * input under a single <th>. The system-page loop has only one input
	 * per row but must carry the same fieldset/legend, or the row's entire
	 * accessible name collapses to the shared "Title template" label with
	 * nothing telling a screen-reader user which system page it belongs to.
	 */
	public function test_system_page_rows_carry_a_legend_naming_the_row(): void {
		$_GET['tab'] = 'templates';
		$html        = $this->render_page();

		$this->assertStringContainsString( '<legend class="screen-reader-text"><span>Home page</span></legend>', $html );
		$this->assertStringContainsString( '<legend class="screen-reader-text"><span>Search results</span></legend>', $html );
		$this->assertStringContainsString( '<legend class="screen-reader-text"><span>Not found (404)</span></legend>', $html );
	}

	public function test_each_template_input_has_a_label_bound_by_id(): void {
		$_GET['tab'] = 'templates';
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Posts' ) )
		);

		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'for="taseo-title-post-post"', $html );
		$this->assertStringContainsString( 'id="taseo-title-post-post"', $html );
		$this->assertStringContainsString( 'for="taseo-desc-post-post"', $html );
		$this->assertStringContainsString( 'id="taseo-desc-post-post"', $html );
	}

	public function test_template_input_names_are_unchanged(): void {
		$_GET['tab'] = 'templates';
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ): object => (object) array( 'labels' => (object) array( 'name' => 'Posts' ) )
		);

		$html = $this->render_page( array( 'post' ) );

		$this->assertStringContainsString( 'name="taseo_settings[title_templates][post:post]"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[description_templates][post:post]"', $html );
		$this->assertStringContainsString( 'data-taseo-template-input', $html );
	}

	public function test_variable_pills_carry_both_the_token_and_its_human_label(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		$this->assertStringContainsString( 'data-taseo-template-var="%%title%%"', $html );
		$this->assertStringContainsString( 'data-taseo-template-label="', $html );
		$this->assertMatchesRegularExpression(
			'/data-taseo-template-var="%%title%%"\s+data-taseo-template-label="[^"]+"/',
			$html,
			'each pill must carry its label alongside its token'
		);
	}

	public function test_social_tab_renders_an_image_picker_not_a_number_box(): void {
		$_GET['tab'] = 'social';

		Functions\when( 'checked' )->justReturn( '' );

		$this->settings->shouldReceive( 'is_open_graph_enabled' )->andReturn( false );
		$this->settings->shouldReceive( 'is_twitter_enabled' )->andReturn( false );
		$this->settings->shouldReceive( 'get_default_social_image_id' )->andReturn( 0 );
		$this->settings->shouldReceive( 'get_default_social_image_url' )->andReturn( '' );
		$this->settings->shouldReceive( 'get_facebook_app_id' )->andReturn( '' );
		$this->settings->shouldReceive( 'get_twitter_site' )->andReturn( '' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'data-taseo-image-field', $html );
		$this->assertStringContainsString( 'name="taseo_settings[default_social_image_url]"', $html );
		$this->assertStringNotContainsString(
			'<input type="number" name="taseo_settings[default_social_image_id]"',
			$html
		);
	}

	/**
	 * Asserted as a pair rather than as two lists: a renamed heading id with
	 * no matching nav change would leave both halves individually plausible
	 * and the link broken.
	 */
	public function test_the_section_nav_links_match_the_section_headings(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		foreach ( array( 'taseo-post-types', 'taseo-taxonomies', 'taseo-system-pages', 'taseo-custom-pages' ) as $anchor ) {
			$this->assertStringContainsString( 'href="#' . $anchor . '"', $html, "nav link missing for {$anchor}" );
			$this->assertStringContainsString( 'id="' . $anchor . '"', $html, "heading id missing for {$anchor}" );
		}
	}

	/**
	 * .subsubsub is float: left (wp-admin/css/common.css:428). Without the
	 * clear, the first heading wraps alongside the nav instead of below it.
	 */
	public function test_the_section_nav_uses_core_classes_and_clears_its_float(): void {
		$_GET['tab'] = 'templates';

		$html = $this->render_page();

		$this->assertStringContainsString( 'class="subsubsub"', $html );
		$this->assertStringContainsString( 'class="clear"', $html );

		// A plain assertStringNotContainsString( 'class="taseo', $html ) only
		// catches a class attribute BEGINNING with taseo — 'class="button
		// taseo-x"' would slip through. Match a taseo-prefixed class token
		// anywhere inside any class attribute instead.
		$this->assertDoesNotMatchRegularExpression( '/class="[^"]*\btaseo[^"]*"/', $html );
	}
}
