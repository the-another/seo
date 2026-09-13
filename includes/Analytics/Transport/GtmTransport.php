<?php
/**
 * Google Tag Manager Transport
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

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
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => validated IDs.
	 * @return void
	 */
	public function emit_primary( array $tags ): void {
		foreach ( $this->ids( $tags ) as $id ) {
			wp_print_inline_script_tag(
				"(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n"
				. "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n"
				. "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n"
				. "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n"
				. "})(window,document,'script','dataLayer','" . $id . "');"
			);
		}
	}

	/**
	 * Print the no-JS fallback iframe.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => validated IDs.
	 * @return void
	 */
	public function emit_noscript( array $tags ): void {
		foreach ( $this->ids( $tags ) as $id ) {
			printf(
				'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
				esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $id ) )
			);
		}
	}
}
