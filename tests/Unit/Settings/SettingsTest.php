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

	public function test_verification_file_returns_stored_value_per_engine(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'verify_google_file' => 'google1a2b3c.html',
				'verify_bing_file'   => 'BINGTOKEN123',
				'verify_yandex_file' => 'yandex_9f8e7d.html',
			)
		);

		$settings = new Settings();

		$this->assertSame( 'google1a2b3c.html', $settings->get_verification_file( 'google' ) );
		$this->assertSame( 'BINGTOKEN123', $settings->get_verification_file( 'bing' ) );
		$this->assertSame( 'yandex_9f8e7d.html', $settings->get_verification_file( 'yandex' ) );
	}

	public function test_verification_file_returns_empty_string_for_engine_without_file_method(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verify_facebook' => 'metatoken' ) );

		$this->assertSame( '', ( new Settings() )->get_verification_file( 'facebook' ) );
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
}
