<?php
/**
 * Tracking Consent Categories
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

/**
 * Enum Consent
 *
 * The consent category a vendor belongs to. The category, not the vendor, is
 * what a visitor decides: accepting analytics but not marketing is one answer
 * covering every vendor in each group. Google Ads and Bing UET are
 * conversion/remarketing tags, so they sit with Meta Pixel rather than with GA4.
 *
 * @since 1.5.0
 */
enum Consent {

	/**
	 * Measurement: GA4, Tag Manager.
	 */
	case Analytics;

	/**
	 * Conversion and remarketing: Meta Pixel, Google Ads, Bing UET.
	 */
	case Marketing;

	/**
	 * The filter gating this category on the current request.
	 *
	 * @since 1.5.0
	 *
	 * @return string Filter name.
	 */
	public function gate(): string {
		return match ( $this ) {
			self::Analytics => 'taseo_analytics_should_print',
			self::Marketing => 'taseo_marketing_should_print',
		};
	}
}
