<?php
/**
 * Meta Pixel Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class MetaPixelOutput
 *
 * Meta Pixel base code and its no-JS fallback. Separate from
 * AnalyticsOutput: different vendor, different origin, different consent
 * category, and no interaction with the Google tags.
 *
 * The vendor snippet is emitted intact rather than split into an enqueued
 * fbevents.js plus an inline fbq('init'): the stub must exist before
 * fbevents.js drains its queue, and hand-splitting it fails silently.
 */
class MetaPixelOutput {

	/**
	 * Valid pixel ID — a bare numeric string.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[0-9]{10,20}$/';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_head' ), 2 );
		$hook_manager->register_action( 'wp_body_open', array( $this, 'print_body' ) );
	}

	/**
	 * Print the pixel base code.
	 *
	 * @return void
	 */
	public function print_head(): void {
		$ids = $this->pixel_ids();

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
	 * @return void
	 */
	public function print_body(): void {
		foreach ( $this->pixel_ids() as $id ) {
			printf(
				'<noscript><img height="1" width="1" style="display:none" alt="" src="%s" /></noscript>' . "\n",
				esc_url( 'https://www.facebook.com/tr?id=' . $id . '&ev=PageView&noscript=1' )
			);
		}
	}

	/**
	 * Pixel IDs for this request.
	 *
	 * @return array<int, string> Validated, de-duplicated IDs.
	 */
	private function pixel_ids(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$stored = $this->settings->get_meta_pixel_id();
		$ids    = '' === $stored ? array() : array( $stored );

		/**
		 * Filters the Meta Pixel IDs emitted on this request.
		 *
		 * Append an ID here to fire a secondary pixel on specific pages.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $ids Pixel IDs.
		 */
		$ids = apply_filters( 'taseo_meta_pixel_ids', $ids );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = trim( $id );

			if ( 1 === preg_match( self::ID_PATTERN, $id ) && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Whether pixel output is allowed on this request.
	 *
	 * @return bool Allowed.
	 */
	private function should_print(): bool {
		$default = ! is_admin() && ! is_customize_preview();

		/** This filter is documented in includes/Analytics/AnalyticsOutput.php */
		if ( ! (bool) apply_filters( 'taseo_tracking_should_print', $default ) ) {
			return false;
		}

		/**
		 * Filters whether Meta Pixel output is emitted on this request.
		 *
		 * The marketing-category consent gate, separate from the analytics
		 * gate so a site with analytics consent but not marketing consent
		 * can suppress one without losing the other.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether to emit.
		 */
		return (bool) apply_filters( 'taseo_meta_pixel_should_print', true );
	}
}
