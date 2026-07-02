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
}
