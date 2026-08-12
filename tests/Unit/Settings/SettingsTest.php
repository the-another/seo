<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( Settings::class )]
class SettingsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_returns_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'separator' => '|' ) );

		$this->assertSame( '|', ( new Settings() )->get( 'separator' ) );
	}

	public function test_get_returns_default_when_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'fallback', ( new Settings() )->get( 'missing', 'fallback' ) );
	}

	public function test_enabled_post_types_default_to_public_minus_attachment(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'get_post_types' )
			->once()
			->with( array( 'public' => true ), 'names' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'product' => 'product' ) );

		$this->assertSame( array( 'post', 'page', 'product' ), ( new Settings() )->get_enabled_post_types() );
	}

	public function test_enabled_post_types_respect_stored_selection(): void {
		Functions\when( 'get_option' )->justReturn( array( 'enabled_post_types' => array( 'product' ) ) );

		$this->assertSame( array( 'product' ), ( new Settings() )->get_enabled_post_types() );
	}

	public function test_title_template_falls_back_to_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame(
			'%%title%% %%sep%% %%sitename%%',
			( new Settings() )->get_title_template( 'post', 'product' )
		);
	}

	public function test_title_template_reads_stored_per_subtype_value(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'title_templates' => array( 'post:product' => '%%title%% %%sep%% %%price%%' ) )
		);

		$this->assertSame(
			'%%title%% %%sep%% %%price%%',
			( new Settings() )->get_title_template( 'post', 'product' )
		);
	}

	public function test_description_template_falls_back_to_excerpt_for_post(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame(
			'%%excerpt%%',
			( new Settings() )->get_description_template( 'post', 'product' )
		);
	}

	public function test_description_template_falls_back_to_empty_string_for_custom_page(): void {
		// %%excerpt%% is only ever guaranteed resolvable for post and term
		// rows (TemplateVariables::get_for()); a custom_page has no built-in
		// source of one, so handing back that token as a default would be a
		// guess the type can't back up — and the settings-page validator
		// would reject it on every save until the registering plugin's
		// author overrides it. An empty template ("no meta description") is
		// the only default this type can honestly offer.
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame(
			'',
			( new Settings() )->get_description_template( 'custom_page', 'checkout' )
		);
	}

	public function test_description_template_reads_stored_per_subtype_value(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'description_templates' => array( 'custom_page:checkout' => '%%title%% details' ) )
		);

		$this->assertSame(
			'%%title%% details',
			( new Settings() )->get_description_template( 'custom_page', 'checkout' )
		);
	}

	public function test_schema_type_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$settings = new Settings();

		$this->assertSame( 'Article', $settings->get_schema_type( 'post' ) );
		$this->assertSame( 'WebPage', $settings->get_schema_type( 'page' ) );
		$this->assertSame( 'Product', $settings->get_schema_type( 'product' ) );
		$this->assertSame( 'WebPage', $settings->get_schema_type( 'custom_thing' ) );
	}

	public function test_schema_type_stored_mapping_wins(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'schema_types' => array( 'post' => 'WebPage' ) )
		);

		$this->assertSame( 'WebPage', ( new Settings() )->get_schema_type( 'post' ) );
	}

	/**
	 * Only post types are in the defaults table, so a subtype split out of one
	 * must inherit its owner's — otherwise splitting `product` into auctions
	 * and items would silently downgrade both from Product to WebPage.
	 */
	public function test_a_subtype_inherits_its_owning_post_types_schema_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$settings = new Settings();

		$this->assertSame( 'Product', $settings->get_schema_type( 'aucteeno_auction', 'product' ) );
		$this->assertSame( 'Article', $settings->get_schema_type( 'editorial_note', 'post' ) );
	}

	public function test_a_stored_subtype_value_still_beats_the_inherited_default(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'schema_types' => array( 'aucteeno_auction' => 'Article' ) )
		);

		$this->assertSame( 'Article', ( new Settings() )->get_schema_type( 'aucteeno_auction', 'product' ) );
	}

	public function test_an_unknown_owner_still_falls_back_to_webpage(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'WebPage', ( new Settings() )->get_schema_type( 'whatever', 'no_such_type' ) );
	}

	public function test_social_toggles_default_on(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$settings = new Settings();

		$this->assertTrue( $settings->is_open_graph_enabled() );
		$this->assertTrue( $settings->is_twitter_enabled() );
	}

	public function test_update_merges_into_stored_option(): void {
		Functions\expect( 'get_option' )->once()->with( 'taseo_settings', array() )->andReturn( array( 'separator' => '|' ) );
		Functions\expect( 'update_option' )
			->once()
			->with( 'taseo_settings', array( 'separator' => '|', 'twitter_enabled' => false ) );

		( new Settings() )->update( array( 'twitter_enabled' => false ) );
	}

	public function test_sitemap_enabled_defaults_on(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertTrue( ( new Settings() )->is_sitemap_enabled() );
	}

	public function test_sitemap_max_links_defaults_to_protocol_cap(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 1000, ( new Settings() )->get_sitemap_max_links() );
	}

	public function test_sitemap_max_links_is_clamped_to_1_1000(): void {
		Functions\when( 'get_option' )->justReturn( array( 'sitemap_max_links' => 5000 ) );
		$this->assertSame( 1000, ( new Settings() )->get_sitemap_max_links() );

		Functions\when( 'get_option' )->justReturn( array( 'sitemap_max_links' => 0 ) );
		$this->assertSame( 1, ( new Settings() )->get_sitemap_max_links() );

		Functions\when( 'get_option' )->justReturn( array( 'sitemap_max_links' => 500 ) );
		$this->assertSame( 500, ( new Settings() )->get_sitemap_max_links() );
	}

	public function test_verification_code_returns_stored_value_per_engine(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google'   => 'googletoken',
				'verify_bing'     => 'bingtoken',
				'verify_yandex'   => 'yandextoken',
				'verify_yahoo'    => 'yahootoken',
				'verify_facebook' => 'metatoken',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'googletoken', $settings->get_verification_code( 'google' ) );
		$this->assertSame( 'bingtoken', $settings->get_verification_code( 'bing' ) );
		$this->assertSame( 'yandextoken', $settings->get_verification_code( 'yandex' ) );
		$this->assertSame( 'yahootoken', $settings->get_verification_code( 'yahoo' ) );
		$this->assertSame( 'metatoken', $settings->get_verification_code( 'facebook' ) );
	}

	public function test_verification_code_defaults_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', ( new Settings() )->get_verification_code( 'google' ) );
	}

	public function test_verification_code_returns_empty_string_for_unknown_engine(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verify_google' => 'googletoken' ) );

		$this->assertSame( '', ( new Settings() )->get_verification_code( 'duckduckgo' ) );
	}

	public function test_verification_method_defaults_to_meta(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'meta', ( new Settings() )->get_verification_method( 'google' ) );
	}

	public function test_verification_method_reads_a_stored_file_method(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verify_google_method' => 'file' ) );

		$this->assertSame( 'file', ( new Settings() )->get_verification_method( 'google' ) );
	}

	public function test_verification_method_rejects_an_unrecognised_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verify_google_method' => 'carrier-pigeon' ) );

		$this->assertSame( 'meta', ( new Settings() )->get_verification_method( 'google' ) );
	}

	public function test_verification_method_is_per_domain_and_does_not_inherit(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google_method' => 'file',
				'verification_domains' => array( 'brandtwo.com' => array() ),
			)
		);

		$settings = new Settings();

		$this->assertSame( 'file', $settings->get_verification_method( 'google' ) );
		$this->assertSame( 'meta', $settings->get_verification_method( 'google', 'brandtwo.com' ) );
	}

	public function test_verification_method_is_empty_for_an_unknown_engine(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'meta', ( new Settings() )->get_verification_method( 'yahoo' ) );
	}

	public function test_tracking_ids_return_stored_values(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'analytics_ga4_id' => 'G-ABCD1234',
				'analytics_gtm_id' => 'GTM-XYZ789',
				'meta_pixel_id'    => '0123456789012345',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'G-ABCD1234', $settings->get_ga4_id() );
		$this->assertSame( 'GTM-XYZ789', $settings->get_gtm_id() );
		$this->assertSame( '0123456789012345', $settings->get_meta_pixel_id() );
	}

	public function test_tracking_ids_default_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$settings = new Settings();

		$this->assertSame( '', $settings->get_ga4_id() );
		$this->assertSame( '', $settings->get_gtm_id() );
		$this->assertSame( '', $settings->get_meta_pixel_id() );
	}

	public function test_meta_pixel_id_preserves_leading_zero(): void {
		Functions\when( 'get_option' )->justReturn( array( 'meta_pixel_id' => '0987654321098' ) );

		$this->assertSame( '0987654321098', ( new Settings() )->get_meta_pixel_id() );
	}

	public function test_image_url_overrides_default_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$settings = new Settings();

		$this->assertSame( '', $settings->get_default_social_image_url() );
		$this->assertSame( '', $settings->get_site_logo_url() );
	}

	public function test_image_url_overrides_are_read_from_the_option(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'default_social_image_url' => 'https://cdn.example.com/social.jpg',
				'site_logo_url'            => 'https://cdn.example.com/logo.png',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'https://cdn.example.com/social.jpg', $settings->get_default_social_image_url() );
		$this->assertSame( 'https://cdn.example.com/logo.png', $settings->get_site_logo_url() );
	}

	public function test_disabled_sitemap_families_default_to_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( array(), ( new Settings() )->get_disabled_sitemap_families() );
		$this->assertTrue( ( new Settings() )->is_sitemap_family_enabled( 'vendor_store' ) );
	}

	public function test_disabled_sitemap_families_reads_stored_list(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'sitemap_disabled_families' => array( 'vendor_store' ) )
		);

		$settings = new Settings();

		$this->assertSame( array( 'vendor_store' ), $settings->get_disabled_sitemap_families() );
		$this->assertFalse( $settings->is_sitemap_family_enabled( 'vendor_store' ) );
		$this->assertTrue( $settings->is_sitemap_family_enabled( 'auctioneer_location' ) );
	}

	public function test_disabled_sitemap_families_tolerates_garbage(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'sitemap_disabled_families' => 'not-an-array' )
		);

		$this->assertSame( array(), ( new Settings() )->get_disabled_sitemap_families() );
	}

	public function test_verification_code_for_the_default_host_reads_the_flat_key(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google'        => 'defaultcode',
				'verification_domains' => array( 'brandtwo.com' => array( 'verify_google' => 'brandcode' ) ),
			)
		);

		$settings = new Settings();

		$this->assertSame( 'defaultcode', $settings->get_verification_code( 'google' ) );
		$this->assertSame( 'defaultcode', $settings->get_verification_code( 'google', 'example.com' ) );
		$this->assertSame( 'defaultcode', $settings->get_verification_code( 'google', 'WWW.Example.com' ) );
	}

	public function test_verification_code_for_another_host_reads_its_own_record(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google'        => 'defaultcode',
				'verification_domains' => array( 'brandtwo.com' => array( 'verify_google' => 'brandcode' ) ),
			)
		);

		$this->assertSame( 'brandcode', ( new Settings() )->get_verification_code( 'google', 'brandtwo.com' ) );
	}

	public function test_verification_code_does_not_inherit_the_default(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google'        => 'defaultcode',
				'verification_domains' => array( 'brandtwo.com' => array( 'verify_google' => '' ) ),
			)
		);

		$settings = new Settings();

		$this->assertSame( '', $settings->get_verification_code( 'google', 'brandtwo.com' ) );
		$this->assertSame( '', $settings->get_verification_code( 'google', 'unconfigured.com' ) );
	}

	public function test_tracking_ids_inherit_the_default_when_blank(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'analytics_ga4_id'     => 'G-DEFAULT',
				'analytics_gtm_id'     => 'GTM-DEFAULT',
				'meta_pixel_id'        => '111111111111111',
				'verification_domains' => array(
					'brandtwo.com' => array( 'analytics_ga4_id' => 'G-BRAND' ),
				),
			)
		);

		$settings = new Settings();

		$this->assertSame( 'G-BRAND', $settings->get_ga4_id( 'brandtwo.com' ) );
		$this->assertSame( 'GTM-DEFAULT', $settings->get_gtm_id( 'brandtwo.com' ) );
		$this->assertSame( '111111111111111', $settings->get_meta_pixel_id( 'brandtwo.com' ) );
	}

	public function test_get_domain_record_returns_an_empty_array_for_an_unknown_host(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verification_domains' => 'corrupt' ) );

		$this->assertSame( array(), ( new Settings() )->get_domain_record( 'brandtwo.com' ) );
	}

	/**
	 * Records are keyed on normalized hosts, so this public getter normalizes
	 * its argument like every other host-taking method on this class — a caller
	 * holding a raw `www.`/scheme/port form resolves the same record.
	 */
	public function test_get_domain_record_normalizes_its_host(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verification_domains' => array( 'brandtwo.com' => array( 'verify_google' => 'brandcode' ) ),
			)
		);

		$settings = new Settings();
		$expected = array( 'verify_google' => 'brandcode' );

		$this->assertSame( $expected, $settings->get_domain_record( 'brandtwo.com' ) );
		$this->assertSame( $expected, $settings->get_domain_record( 'WWW.BrandTwo.com' ) );
		$this->assertSame( $expected, $settings->get_domain_record( 'https://www.brandtwo.com:8443/shop' ) );
	}
}
