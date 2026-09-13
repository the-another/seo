<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\Consent;
use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagResolver;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( ConsentMode::class )]
class ConsentModeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, array<int, string>> $resolved Resolver return value.
	 */
	private function mode( bool $enabled, array $resolved ): ConsentMode {
		$settings = Mockery::mock( Settings::class );
		$settings->shouldReceive( 'is_consent_enabled' )->andReturn( $enabled );

		$resolver = Mockery::mock( TagResolver::class );
		$resolver->shouldReceive( 'resolve' )->andReturn( $resolved );

		return new ConsentMode( $settings, $resolver, new TagRegistry() );
	}

	public function test_inactive_when_the_setting_is_off(): void {
		$this->assertFalse( $this->mode( false, array( 'ga4' => array( 'G-ABCD1234' ) ) )->is_active() );
	}

	public function test_inactive_when_nothing_survives_site_policy(): void {
		$this->assertFalse( $this->mode( true, array() )->is_active() );
	}

	public function test_active_when_enabled_and_a_vendor_resolves(): void {
		$this->assertTrue( $this->mode( true, array( 'ga4' => array( 'G-ABCD1234' ) ) )->is_active() );
	}

	public function test_a_filter_can_turn_consent_off_for_one_request(): void {
		Filters\expectApplied( 'taseo_consent_enabled' )->once()->andReturn( false );

		$this->assertFalse( $this->mode( true, array( 'ga4' => array( 'G-ABCD1234' ) ) )->is_active() );
	}

	public function test_attributes_are_empty_while_inactive(): void {
		$this->assertSame(
			array(),
			$this->mode( false, array( 'ga4' => array( 'G-ABCD1234' ) ) )->attributes( array( Consent::Analytics ) )
		);
	}

	public function test_attributes_block_the_script_and_name_the_categories(): void {
		$mode = $this->mode( true, array( 'ga4' => array( 'G-ABCD1234' ) ) );

		$this->assertSame(
			array(
				'type'               => 'text/plain',
				'data-taseo-consent' => 'analytics marketing',
			),
			$mode->attributes( array( Consent::Analytics, Consent::Marketing ) )
		);
	}

	public function test_a_group_is_named_when_given(): void {
		$mode = $this->mode( true, array( 'ga4' => array( 'G-ABCD1234' ) ) );

		$this->assertSame(
			'gtag',
			$mode->attributes( array( Consent::Analytics ), 'gtag' )['data-taseo-consent-group']
		);
	}

	public function test_categories_carry_only_what_resolved(): void {
		$mode = $this->mode( true, array( 'ga4' => array( 'G-ABCD1234' ) ) );

		$this->assertSame( array( 'analytics' ), $mode->categories() );
	}

	public function test_categories_are_in_registry_order_without_duplicates(): void {
		$mode = $this->mode(
			true,
			array(
				'meta_pixel' => array( '123456789012345' ),
				'ga4'        => array( 'G-ABCD1234' ),
				'google_ads' => array( 'AW-123456789' ),
			)
		);

		$this->assertSame( array( 'analytics', 'marketing' ), $mode->categories() );
	}
}
