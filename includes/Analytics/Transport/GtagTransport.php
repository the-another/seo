<?php
/**
 * Google gtag.js Transport
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Analytics\TagSlice;

/**
 * Class GtagTransport
 *
 * The gtag.js loader, shared by GA4 and Google Ads. One vendor lineage with one
 * dataLayer: a second loader would be a second copy of the same library, and
 * the config call is per ID regardless of which product the ID belongs to. The
 * two stay separate registry entries — separate keys, patterns and consent
 * categories — so a marketing denial drops the AW- IDs and leaves the G- IDs
 * rendering.
 *
 * Goes through the script queue rather than printing a tag, so WordPress owns
 * the <script src> and the inline configuration can be attached to it.
 *
 * @since 1.5.0
 */
class GtagTransport implements TagTransport {

	use FlattensTags;

	/**
	 * Enqueue gtag.js and its configuration.
	 *
	 * @since 1.5.0
	 * @since 1.6.0 Takes slices and the request's consent mode rather than a
	 *              key => IDs map.
	 *
	 * @param array<int, TagSlice> $slices Slices, in registry order.
	 * @param ConsentMode          $consent Consent mode for this request.
	 * @return void
	 */
	public function emit_primary( array $slices, ConsentMode $consent ): void {
		$ids = $this->ids( $slices );

		if ( array() === $ids ) {
			return;
		}

		wp_enqueue_script(
			'taseo-gtag',
			'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ids[0] ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's endpoint is versionless; a ?ver= query would be sent upstream verbatim.
			false
		);

		/**
		 * Filters per-property gtag configuration parameters.
		 *
		 * Keyed by ID, so it covers Google Ads conversion IDs as well as GA4
		 * measurement IDs.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $config Measurement ID => parameters.
		 */
		$config = apply_filters( 'taseo_analytics_gtag_config', array() );
		$config = is_array( $config ) ? $config : array();

		$inline = "window.dataLayer = window.dataLayer || [];\n"
			. "function gtag(){dataLayer.push(arguments);}\n"
			. "gtag('js', new Date());\n";

		foreach ( $ids as $id ) {
			$params  = isset( $config[ $id ] ) && is_array( $config[ $id ] ) ? $config[ $id ] : array();
			$encoded = array() === $params ? false : wp_json_encode( $params );

			// wp_json_encode() returns false for a non-UTF-8 string, INF/NAN,
			// or a resource — any of which a filter can hand us. Falling
			// back to the no-parameters form keeps this one line valid
			// JavaScript instead of a dangling comma that would throw a
			// SyntaxError and kill the whole inline block, including every
			// other property's config line and the dataLayer bootstrap.
			$inline .= is_string( $encoded )
				? "gtag('config', '" . $id . "', " . $encoded . ");\n"
				: "gtag('config', '" . $id . "');\n";
		}

		wp_add_inline_script( 'taseo-gtag', $inline, 'after' );
	}

	/**
	 * No no-JS half: gtag.js has none to emit.
	 *
	 * @since 1.5.0
	 * @since 1.6.0 Takes slices and the request's consent mode rather than a
	 *              key => IDs map.
	 *
	 * @param array<int, TagSlice> $slices Slices, in registry order.
	 * @param ConsentMode          $consent Consent mode for this request.
	 * @return void
	 */
	public function emit_noscript( array $slices, ConsentMode $consent ): void {
	}
}
