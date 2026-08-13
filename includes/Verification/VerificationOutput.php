<?php
/**
 * Site Verification Meta Tags
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Verification;

use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class VerificationOutput
 *
 * Prints webmaster-tools verification meta tags. Front page only: search
 * engines read the tag at the property root, so emitting it on every URL of
 * a catalog-scale site is payload with no benefit.
 */
class VerificationOutput {

	/**
	 * Engine slug => meta name.
	 *
	 * @var array<string, string>
	 */
	private const META_NAMES = array(
		'google'   => 'google-site-verification',
		'bing'     => 'msvalidate.01',
		'yandex'   => 'yandex-verification',
		'yahoo'    => 'y_key',
		'facebook' => 'facebook-domain-verification',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param DomainRegistry $domains  Domain registry.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly DomainRegistry $domains
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_tags' ), 1 );
	}

	/**
	 * Print the verification tags.
	 *
	 * @return void
	 */
	public function print_tags(): void {
		$should_print = is_front_page() && ! is_paged();

		/**
		 * Filters whether verification tags print on this request.
		 *
		 * Widen this for a Search Console URL-prefix property registered
		 * below the site root.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $should_print Whether to print.
		 */
		if ( ! (bool) apply_filters( 'taseo_verification_should_print', $should_print ) ) {
			return;
		}

		$tags = array();
		$host = $this->domains->get_current_host();

		foreach ( self::META_NAMES as $engine => $meta_name ) {
			if ( Settings::METHOD_FILE === $this->settings->get_verification_method( $engine, $host ) ) {
				continue;
			}

			$code = $this->settings->get_verification_code( $engine, $host );

			if ( '' !== $code ) {
				$tags[ $meta_name ] = $code;
			}
		}

		/**
		 * Filters the verification tags, keyed by meta name.
		 *
		 * The tags are resolved for the domain the request arrived on, not for
		 * the site as a whole: on a multi-domain install this filter runs once
		 * per requesting domain and the incoming array differs between them.
		 * The filter carries no host argument, so a subscriber that needs to
		 * know which domain it is running for must resolve that itself.
		 *
		 * A service verifying by file contributes nothing here — the array holds
		 * only the services whose method is `meta` on the requested domain.
		 *
		 * @since 1.0.0
		 * @since 1.0.0 Semantics changed: the value is now the requesting
		 *              domain's tags rather than the whole site's.
		 * @since 1.0.0 Services on the file method are absent from the array.
		 *
		 * @param array<string, string> $tags Meta name => verification code.
		 */
		$tags = apply_filters( 'taseo_verification_tags', $tags );

		if ( ! is_array( $tags ) ) {
			return;
		}

		foreach ( $tags as $meta_name => $code ) {
			// NOT sanitize_key(): it strips dots and colons, which would
			// rewrite Bing's own msvalidate.01 and Pinterest's
			// p:domain_verify into names no service recognises.
			$meta_name = (string) preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $meta_name );
			$code      = self::sanitize_code( (string) $code );

			if ( '' === $meta_name || '' === $code ) {
				continue;
			}

			echo '<meta name="' . esc_attr( $meta_name ) . '" content="' . esc_attr( $code ) . '" />' . "\n";
		}
	}

	/**
	 * Normalize a verification code.
	 *
	 * Accepts a bare token or a whole pasted <meta> tag, and strips
	 * everything outside the token character class. That strip is the
	 * security guarantee: a stored code cannot contain a quote or angle
	 * bracket, so it cannot break out of the content="" attribute.
	 *
	 * @param string $raw Raw submitted or filtered value.
	 * @return string Clean code, '' when nothing survives.
	 */
	public static function sanitize_code( string $raw ): string {
		$value = trim( $raw );

		if ( false !== stripos( $value, '<meta' ) && 1 === preg_match( '/content=["\']([^"\']*)["\']/i', $value, $matches ) ) {
			$value = $matches[1];
		}

		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
	}
}
