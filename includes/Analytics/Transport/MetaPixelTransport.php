<?php
/**
 * Meta Pixel Transport
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Analytics\TagSlice;

/**
 * Class MetaPixelTransport
 *
 * The pixel base code and its no-JS image.
 *
 * The vendor snippet is emitted intact rather than split into an enqueued
 * fbevents.js plus an inline fbq('init'): the stub must exist before
 * fbevents.js drains its queue, and hand-splitting it fails silently.
 *
 * @since 1.5.0
 */
class MetaPixelTransport implements TagTransport {

	use FlattensTags;

	/**
	 * Print the pixel base code.
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

		$js = "!function(f,b,e,v,n,t,s)\n"
			. "{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n"
			. "n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n"
			. "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n"
			. "n.queue=[];t=b.createElement(e);t.async=!0;\n"
			. "t.src=v;s=b.getElementsByTagName(e)[0];\n"
			. "s.parentNode.insertBefore(t,s)}(window, document,'script',\n"
			. "'https://connect.facebook.net/en_US/fbevents.js');\n";

		foreach ( $ids as $id ) {
			$js .= "fbq('init', '" . $id . "');\n";
		}

		// One track call fires against every initialised pixel — Meta's
		// documented multi-pixel pattern.
		$js .= "fbq('track', 'PageView');\n";

		wp_print_inline_script_tag( $js );
	}

	/**
	 * Print the no-JS fallback image.
	 *
	 * Meta's copy-paste snippet puts this in <head>; an <img> there forces
	 * the parser out of head exactly when scripting is disabled, so it goes
	 * in the body instead. The browser requests the same URL either way.
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
				'<noscript><img height="1" width="1" style="display:none" alt="" src="%s" /></noscript>' . "\n",
				esc_url( 'https://www.facebook.com/tr?id=' . $id . '&ev=PageView&noscript=1' )
			);
		}
	}
}
