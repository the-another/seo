<?php
/**
 * Google Tag Manager Transport
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Analytics\TagSlice;

/**
 * Class GtmTransport
 *
 * The container loader and its no-JS iframe. Prints inline rather than
 * enqueueing: the container must be in <head> before the rest of the document,
 * and the snippet injects its own script element.
 *
 * @since 1.5.0
 */
class GtmTransport implements TagTransport {

	use FlattensTags;

	/**
	 * Print the container loader.
	 *
	 * @since 1.5.0
	 * @since 1.6.0 Takes slices and the request's consent mode rather than a
	 *              key => IDs map.
	 * @since 1.6.0 Emits inert, with the consent attributes, while consent mode
	 *              is active.
	 *
	 * @param array<int, TagSlice> $slices Slices, in registry order.
	 * @param ConsentMode          $consent Consent mode for this request.
	 * @return void
	 */
	public function emit_primary( array $slices, ConsentMode $consent ): void {
		foreach ( $slices as $slice ) {
			foreach ( $slice->ids as $id ) {
				wp_print_inline_script_tag(
					"(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n"
					. "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n"
					. "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n"
					. "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n"
					. "})(window,document,'script','dataLayer','" . $id . "');",
					$consent->attributes( array( $slice->type->consent ) )
				);
			}
		}
	}

	/**
	 * Print the no-JS fallback iframe.
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
		foreach ( $this->ids( $slices ) as $id ) {
			printf(
				'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
				esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $id ) )
			);
		}
	}
}
