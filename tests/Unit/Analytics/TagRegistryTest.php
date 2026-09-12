<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\Consent;
use TheAnother\Plugin\SEO\Analytics\Placement;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\Transport\BingUetTransport;
use TheAnother\Plugin\SEO\Analytics\Transport\GtagTransport;
use TheAnother\Plugin\SEO\Analytics\Transport\GtmTransport;
use TheAnother\Plugin\SEO\Analytics\Transport\MetaPixelTransport;

#[CoversClass( TagRegistry::class )]
class TagRegistryTest extends TestCase {

	private TagRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->registry = new TagRegistry();
	}

	/**
	 * Order is load-bearing, not cosmetic: it decides which ID drives the gtag
	 * loader and the order the two <noscript> halves print in at wp_body_open,
	 * both of which must match what the plugin emits today.
	 */
	public function test_declares_the_five_vendors_in_render_order(): void {
		$this->assertSame(
			array( 'ga4', 'gtm', 'meta_pixel', 'google_ads', 'bing_uet' ),
			array_keys( $this->registry->all() )
		);
	}

	public function test_ga4_declares_todays_behaviour(): void {
		$ga4 = $this->registry->get( 'ga4' );

		$this->assertSame( 'analytics_ga4_id', $ga4->settings_key );
		$this->assertSame( '/^G-[A-Z0-9]{4,}$/', $ga4->pattern );
		$this->assertTrue( $ga4->uppercase );
		$this->assertSame( 'taseo_analytics_ga4_ids', $ga4->ids_filter );
		$this->assertSame( Consent::Analytics, $ga4->consent );
		$this->assertSame( Placement::ScriptQueue, $ga4->placement );
		$this->assertSame( 10, $ga4->priority );
		$this->assertFalse( $ga4->has_noscript );
		$this->assertInstanceOf( GtagTransport::class, $ga4->transport );
		$this->assertSame( '', $ga4->legacy_gate );
	}

	public function test_gtm_declares_todays_behaviour(): void {
		$gtm = $this->registry->get( 'gtm' );

		$this->assertSame( 'analytics_gtm_id', $gtm->settings_key );
		$this->assertSame( '/^GTM-[A-Z0-9]{4,}$/', $gtm->pattern );
		$this->assertTrue( $gtm->uppercase );
		$this->assertSame( 'taseo_analytics_gtm_ids', $gtm->ids_filter );
		$this->assertSame( Consent::Analytics, $gtm->consent );
		$this->assertSame( Placement::Head, $gtm->placement );
		$this->assertSame( 1, $gtm->priority );
		$this->assertTrue( $gtm->has_noscript );
		$this->assertInstanceOf( GtmTransport::class, $gtm->transport );
	}

	public function test_meta_pixel_declares_todays_behaviour(): void {
		$pixel = $this->registry->get( 'meta_pixel' );

		$this->assertSame( 'meta_pixel_id', $pixel->settings_key );
		$this->assertSame( '/^[0-9]{10,20}$/', $pixel->pattern );
		$this->assertFalse( $pixel->uppercase, 'A numeric ID has no case, and a leading zero is significant.' );
		$this->assertSame( 'taseo_meta_pixel_ids', $pixel->ids_filter );
		$this->assertSame( Consent::Marketing, $pixel->consent );
		$this->assertSame( Placement::Head, $pixel->placement );
		$this->assertSame( 2, $pixel->priority );
		$this->assertTrue( $pixel->has_noscript );
		$this->assertInstanceOf( MetaPixelTransport::class, $pixel->transport );
	}

	/**
	 * The retained per-vendor gate. Every consent platform wired to
	 * taseo_meta_pixel_should_print keeps working; no other vendor gets one,
	 * because the override filter covers per-vendor suppression.
	 */
	public function test_only_meta_pixel_declares_a_legacy_gate(): void {
		$gates = array();

		foreach ( $this->registry->all() as $key => $type ) {
			$gates[ $key ] = $type->legacy_gate;
		}

		$this->assertSame(
			array(
				'ga4'        => '',
				'gtm'        => '',
				'meta_pixel' => 'taseo_meta_pixel_should_print',
				'google_ads' => '',
				'bing_uet'   => '',
			),
			$gates
		);
	}

	/**
	 * The same transport INSTANCE, not merely the same class: one gtag.js
	 * loader and one dataLayer for both vendors is the whole point.
	 */
	public function test_google_ads_shares_ga4s_transport_instance(): void {
		$this->assertSame(
			$this->registry->get( 'ga4' )->transport,
			$this->registry->get( 'google_ads' )->transport
		);
	}

	public function test_google_ads_is_a_marketing_vendor_on_the_script_queue(): void {
		$ads = $this->registry->get( 'google_ads' );

		$this->assertSame( 'google_ads_id', $ads->settings_key );
		$this->assertSame( '/^AW-[0-9]{6,}$/', $ads->pattern );
		$this->assertTrue( $ads->uppercase );
		$this->assertSame( 'taseo_google_ads_ids', $ads->ids_filter );
		$this->assertSame( Consent::Marketing, $ads->consent );
		$this->assertSame( Placement::ScriptQueue, $ads->placement );
		$this->assertSame( 10, $ads->priority );
		$this->assertFalse( $ads->has_noscript );
	}

	public function test_bing_uet_is_a_marketing_vendor_in_head(): void {
		$uet = $this->registry->get( 'bing_uet' );

		$this->assertSame( 'bing_uet_id', $uet->settings_key );
		$this->assertSame( '/^[0-9]{6,20}$/', $uet->pattern );
		$this->assertFalse( $uet->uppercase );
		$this->assertSame( 'taseo_bing_uet_ids', $uet->ids_filter );
		$this->assertSame( Consent::Marketing, $uet->consent );
		$this->assertSame( Placement::Head, $uet->placement );
		$this->assertSame( 3, $uet->priority );
		$this->assertFalse( $uet->has_noscript );
		$this->assertInstanceOf( BingUetTransport::class, $uet->transport );
	}

	public function test_get_answers_null_for_an_unknown_key(): void {
		$this->assertNull( $this->registry->get( 'not_a_vendor' ) );
	}

	/**
	 * Each pattern accepts that vendor's real ID shape and rejects its nearest
	 * neighbours — including another vendor's prefix, which is what stops a
	 * mistyped field from quietly validating.
	 */
	public function test_each_pattern_accepts_its_own_ids_and_rejects_neighbours(): void {
		$cases = array(
			'ga4'        => array( 'G-ABCD1234', array( 'UA-12345-1', 'GTM-ABCD123', 'G-ABC' ) ),
			'gtm'        => array( 'GTM-XYZ789', array( 'G-ABCD1234', 'GTM', 'GTM-ABC' ) ),
			'meta_pixel' => array( '123456789012345', array( '12345', 'G-ABCD1234', '12345678901234567890123' ) ),
			'google_ads' => array( 'AW-123456789', array( 'AW-12345', 'AW-ABCDEFGHI', '123456789' ) ),
			'bing_uet'   => array( '12345678', array( '12345', 'UET-12345678', 'abcdefgh' ) ),
		);

		foreach ( $cases as $key => $case ) {
			list( $valid, $invalid ) = $case;
			$type                    = $this->registry->get( $key );

			$this->assertSame( array( $valid ), $type->clean( array( $valid ) ), $key . ' rejects its own ID' );

			foreach ( $invalid as $bad ) {
				$this->assertSame( array(), $type->clean( array( $bad ) ), $key . ' accepted ' . $bad );
			}
		}
	}

	public function test_all_answers_the_same_instances_every_call(): void {
		$this->assertSame( $this->registry->all(), $this->registry->all() );
	}
}
