<?php
/**
 * Tracking Tag Registry
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Analytics\Transport\BingUetTransport;
use TheAnother\Plugin\SEO\Analytics\Transport\GtagTransport;
use TheAnother\Plugin\SEO\Analytics\Transport\GtmTransport;
use TheAnother\Plugin\SEO\Analytics\Transport\MetaPixelTransport;

/**
 * Class TagRegistry
 *
 * Every vendor this plugin can emit, declared once. Adding a vendor is an entry
 * here — a pattern, a consent category, a placement and a transport — plus a
 * transport only if its snippet is one nothing else emits, and a label row on
 * the settings screen.
 *
 * Declaration order is load-bearing. It decides which ID drives the shared gtag
 * loader and the order the <noscript> halves print in at wp_body_open, and both
 * must match what the plugin emitted before the registry existed: GA4 before
 * Google Ads, Tag Manager before Meta Pixel.
 *
 * GA4 and Google Ads deliberately share one GtagTransport instance: same
 * vendor lineage, same dataLayer, and a second loader would be a second copy of
 * the same library. They stay separate entries so their consent categories can
 * differ — a marketing denial drops the AW- IDs and leaves the G- IDs rendering.
 *
 * @since 1.5.0
 */
class TagRegistry {

	/**
	 * Declared vendors, keyed by stable key.
	 *
	 * @var array<string, TagType>
	 */
	private array $types;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		$gtag = new GtagTransport();

		$types = array(
			new TagType(
				key: 'ga4',
				settings_key: 'analytics_ga4_id',
				pattern: '/^G-[A-Z0-9]{4,}$/',
				uppercase: true,
				ids_filter: 'taseo_analytics_ga4_ids',
				consent: Consent::Analytics,
				placement: Placement::ScriptQueue,
				priority: 10,
				has_noscript: false,
				transport: $gtag
			),
			new TagType(
				key: 'gtm',
				settings_key: 'analytics_gtm_id',
				pattern: '/^GTM-[A-Z0-9]{4,}$/',
				uppercase: true,
				ids_filter: 'taseo_analytics_gtm_ids',
				consent: Consent::Analytics,
				placement: Placement::Head,
				priority: 1,
				has_noscript: true,
				transport: new GtmTransport()
			),
			new TagType(
				key: 'meta_pixel',
				settings_key: 'meta_pixel_id',
				pattern: '/^[0-9]{10,20}$/',
				uppercase: false,
				ids_filter: 'taseo_meta_pixel_ids',
				consent: Consent::Marketing,
				placement: Placement::Head,
				priority: 2,
				has_noscript: true,
				transport: new MetaPixelTransport(),
				legacy_gate: 'taseo_meta_pixel_should_print'
			),
			new TagType(
				key: 'google_ads',
				settings_key: 'google_ads_id',
				pattern: '/^AW-[0-9]{6,}$/',
				uppercase: true,
				ids_filter: 'taseo_google_ads_ids',
				consent: Consent::Marketing,
				placement: Placement::ScriptQueue,
				priority: 10,
				has_noscript: false,
				transport: $gtag
			),
			new TagType(
				key: 'bing_uet',
				settings_key: 'bing_uet_id',
				pattern: '/^[0-9]{6,20}$/',
				uppercase: false,
				ids_filter: 'taseo_bing_uet_ids',
				consent: Consent::Marketing,
				placement: Placement::Head,
				priority: 3,
				has_noscript: false,
				transport: new BingUetTransport()
			),
		);

		$this->types = array();

		foreach ( $types as $type ) {
			$this->types[ $type->key ] = $type;
		}
	}

	/**
	 * Every declared vendor, in declaration order.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, TagType> Key => type.
	 */
	public function all(): array {
		return $this->types;
	}

	/**
	 * One vendor by key.
	 *
	 * @since 1.5.0
	 *
	 * @param string $key Vendor key.
	 * @return TagType|null Type, or null when nothing declares that key.
	 */
	public function get( string $key ): ?TagType {
		return $this->types[ $key ] ?? null;
	}
}
