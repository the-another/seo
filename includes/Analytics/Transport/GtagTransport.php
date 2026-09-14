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
	 * Group name tying the per-category loaders together.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	private const CONSENT_GROUP = 'gtag';

	/**
	 * Enqueue gtag.js and its configuration.
	 *
	 * @since 1.5.0
	 * @since 1.6.0 Takes slices and the request's consent mode rather than a
	 *              key => IDs map.
	 * @since 1.6.0 Prints a per-category blocked form while consent mode is
	 *              active.
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

		$config = $this->config();

		if ( $consent->is_active() ) {
			$this->emit_blocked( $slices, $consent, $config );

			return;
		}

		wp_enqueue_script(
			'taseo-gtag',
			'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ids[0] ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's endpoint is versionless; a ?ver= query would be sent upstream verbatim.
			false
		);

		$inline = $this->bootstrap();

		foreach ( $ids as $id ) {
			$inline .= $this->config_line( $id, $config );
		}

		wp_add_inline_script( 'taseo-gtag', $inline, 'after' );
	}

	/**
	 * Print the blocked form: a loader per category, the shared bootstrap, and
	 * each category's config lines in their own block.
	 *
	 * The loader is the reason this path exists. Its URL embeds an ID, so one
	 * loader carrying both categories would fetch a URL naming the GA4 property
	 * for a visitor who accepted only marketing — a request to Google on a
	 * refused category. One loader per category, grouped so that at most one of
	 * them ever runs, keeps a site running both products to a single copy of
	 * gtag.js while never naming a refused property.
	 *
	 * @since 1.6.0
	 *
	 * @param array<int, TagSlice>                $slices  Slices.
	 * @param ConsentMode                         $consent Consent mode.
	 * @param array<string, array<string, mixed>> $config  Per-ID gtag parameters.
	 * @return void
	 */
	private function emit_blocked( array $slices, ConsentMode $consent, array $config ): void {
		foreach ( $slices as $slice ) {
			if ( array() === $slice->ids ) {
				continue;
			}

			wp_print_script_tag(
				array_merge(
					array( 'src' => 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $slice->ids[0] ) ),
					$consent->attributes( array( $slice->type->consent ), self::CONSENT_GROUP )
				)
			);
		}

		wp_print_inline_script_tag( $this->bootstrap(), $consent->attributes( $this->categories( $slices ) ) );

		foreach ( $slices as $slice ) {
			$lines = '';

			foreach ( $slice->ids as $id ) {
				$lines .= $this->config_line( $id, $config );
			}

			if ( '' === $lines ) {
				continue;
			}

			wp_print_inline_script_tag( $lines, $consent->attributes( array( $slice->type->consent ) ) );
		}
	}

	/**
	 * The dataLayer bootstrap, identical in both paths.
	 *
	 * @since 1.6.0
	 *
	 * @return string JavaScript.
	 */
	private function bootstrap(): string {
		return "window.dataLayer = window.dataLayer || [];\n"
			. "function gtag(){dataLayer.push(arguments);}\n"
			. "gtag('js', new Date());\n";
	}

	/**
	 * One property's config call.
	 *
	 * @since 1.6.0
	 *
	 * @param string                              $id     Validated ID.
	 * @param array<string, array<string, mixed>> $config Per-ID parameters.
	 * @return string JavaScript, one line.
	 */
	private function config_line( string $id, array $config ): string {
		$params  = isset( $config[ $id ] ) && is_array( $config[ $id ] ) ? $config[ $id ] : array();
		$encoded = array() === $params ? false : wp_json_encode( $params );

		// wp_json_encode() returns false for a non-UTF-8 string, INF/NAN, or a
		// resource — any of which a filter can hand us. Falling back to the
		// no-parameters form keeps this one line valid JavaScript instead of a
		// dangling comma that would throw a SyntaxError and kill the whole
		// inline block, including every other property's config line and the
		// dataLayer bootstrap.
		return is_string( $encoded )
			? "gtag('config', '" . $id . "', " . $encoded . ");\n"
			: "gtag('config', '" . $id . "');\n";
	}

	/**
	 * Per-property parameters, filtered once per emission.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, array<string, mixed>> Measurement ID => parameters.
	 */
	private function config(): array {
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

		return is_array( $config ) ? $config : array();
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
