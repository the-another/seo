<?php
/**
 * Google Analytics and Tag Manager Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class AnalyticsOutput
 *
 * GA4 and Google Tag Manager. One class for both because they are one
 * vendor with one lineage — the same dataLayer, the same origin, and a real
 * interaction (a container that already fires a GA4 tag double-counts).
 * Meta Pixel lives in its own class for the opposite reasons.
 */
class AnalyticsOutput {

	/**
	 * Valid GA4 measurement ID.
	 *
	 * @var string
	 */
	private const GA4_PATTERN = '/^G-[A-Z0-9]{4,}$/';

	/**
	 * Valid GTM container ID.
	 *
	 * @var string
	 */
	private const GTM_PATTERN = '/^GTM-[A-Z0-9]{4,}$/';

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
		$hook_manager->register_action( 'wp_enqueue_scripts', array( $this, 'enqueue_gtag' ) );
		$hook_manager->register_action( 'wp_head', array( $this, 'print_gtm_head' ), 1 );
		$hook_manager->register_action( 'wp_body_open', array( $this, 'print_gtm_body' ) );
	}

	/**
	 * Enqueue gtag.js and its configuration.
	 *
	 * @return void
	 */
	public function enqueue_gtag(): void {
		$ids = $this->ga4_ids();

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
	 * Print the Tag Manager container loader.
	 *
	 * @return void
	 */
	public function print_gtm_head(): void {
		foreach ( $this->gtm_ids() as $id ) {
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
	 * Print the Tag Manager no-JS fallback.
	 *
	 * @return void
	 */
	public function print_gtm_body(): void {
		foreach ( $this->gtm_ids() as $id ) {
			printf(
				'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
				esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $id ) )
			);
		}
	}

	/**
	 * GA4 measurement IDs for this request.
	 *
	 * @return array<int, string> Validated, de-duplicated IDs.
	 */
	private function ga4_ids(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$stored = $this->settings->get_ga4_id( $this->domains->get_current_host() );
		$ids    = '' === $stored ? array() : array( $stored );

		/**
		 * Filters the GA4 measurement IDs emitted on this request.
		 *
		 * Append an ID here to send a specific page to a secondary property.
		 *
		 * The stored ID is resolved for the domain the request arrived on, not
		 * for the site as a whole: on a multi-domain install this filter runs
		 * once per requesting domain and the incoming array differs between
		 * them. The filter carries no host argument, so a subscriber that needs
		 * to know which domain it is running for must resolve that itself.
		 *
		 * @since 1.0.0
		 * @since 1.0.0 Semantics changed: the value now starts from the
		 *              requesting domain's ID rather than the whole site's.
		 *
		 * @param array<int, string> $ids Measurement IDs.
		 */
		return $this->clean_ids( apply_filters( 'taseo_analytics_ga4_ids', $ids ), self::GA4_PATTERN );
	}

	/**
	 * GTM container IDs for this request.
	 *
	 * @return array<int, string> Validated, de-duplicated IDs.
	 */
	private function gtm_ids(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$stored = $this->settings->get_gtm_id( $this->domains->get_current_host() );
		$ids    = '' === $stored ? array() : array( $stored );

		/**
		 * Filters the GTM container IDs emitted on this request.
		 *
		 * The stored ID is resolved for the domain the request arrived on, not
		 * for the site as a whole: on a multi-domain install this filter runs
		 * once per requesting domain and the incoming array differs between
		 * them. The filter carries no host argument, so a subscriber that needs
		 * to know which domain it is running for must resolve that itself.
		 *
		 * @since 1.0.0
		 * @since 1.0.0 Semantics changed: the value now starts from the
		 *              requesting domain's ID rather than the whole site's.
		 *
		 * @param array<int, string> $ids Container IDs.
		 */
		return $this->clean_ids( apply_filters( 'taseo_analytics_gtm_ids', $ids ), self::GTM_PATTERN );
	}

	/**
	 * Whether Google tracking output is allowed on this request.
	 *
	 * @return bool Allowed.
	 */
	private function should_print(): bool {
		$default = ! is_admin() && ! is_customize_preview();

		/**
		 * Filters whether ANY tracking output is emitted on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $default Whether to emit.
		 */
		if ( ! (bool) apply_filters( 'taseo_tracking_should_print', $default ) ) {
			return false;
		}

		/**
		 * Filters whether Google tracking output is emitted on this request.
		 *
		 * The analytics-category consent gate.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether to emit.
		 */
		return (bool) apply_filters( 'taseo_analytics_should_print', true );
	}

	/**
	 * Validate, de-duplicate, and re-index a list of IDs.
	 *
	 * Filter return values get the same validation as stored ones: a filter
	 * is third-party code, and trusting it would hand any plugin on the site
	 * a script-injection path into <head>.
	 *
	 * @param mixed  $ids     Candidate IDs.
	 * @param string $pattern Validation pattern.
	 * @return array<int, string> Clean IDs.
	 */
	private function clean_ids( mixed $ids, string $pattern ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = strtoupper( trim( $id ) );

			if ( 1 === preg_match( $pattern, $id ) && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}
}
