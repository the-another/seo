<?php
/**
 * Consent Boot Script
 *
 * @package TheAnotherSEO
 * @since 1.6.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class ConsentAssets
 *
 * Puts the boot script on the page. Every other piece of this feature can
 * exist without this class doing anything: PHP already emits tracking
 * snippets inert, and the boot script already knows how to activate them once
 * loaded. Nothing connects the two until this prints.
 *
 * The script is inlined, not enqueued, and printed as late as wp_head allows.
 * Consent is a synchronous gate on whether a returning visitor's tags fire
 * before the browser paints — an enqueued, deferred, or async script would
 * let the page render (and a crawler render it) with tracking still dark for
 * a visitor who decided that yesterday.
 *
 * @since 1.6.0
 */
class ConsentAssets {

	/**
	 * Memoized bundle contents for this request. Null until read, '' if
	 * unreadable.
	 *
	 * @var string|null
	 */
	private ?string $source = null;

	/**
	 * Memoized version of the built consent UI bundle for this request. Null
	 * until read, '' when the build's asset file cannot be read.
	 *
	 * @var string|null
	 */
	private ?string $ui_version = null;

	/**
	 * Constructor.
	 *
	 * @param ConsentMode    $consent   Consent mode for this request.
	 * @param Settings       $settings  Settings.
	 * @param DomainRegistry $domains   Domain registry.
	 * @param string         $boot_path Filesystem path to the built boot bundle.
	 */
	public function __construct(
		private readonly ConsentMode $consent,
		private readonly Settings $settings,
		private readonly DomainRegistry $domains,
		private readonly string $boot_path
	) {
	}

	/**
	 * Register hooks.
	 *
	 * Runs print_boot() on wp_head at priority 999 — after every inert block,
	 * so that activation happens synchronously while the head is still
	 * parsing and a returning visitor's tags fire exactly where they fired
	 * before this feature existed.
	 *
	 * @since 1.6.0
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_boot' ), 999 );
	}

	/**
	 * Print the inline boot script, when consent mode is active and the
	 * built bundle exists.
	 *
	 * A site with nothing to consent to, or with the gate off, gets nothing:
	 * no boot script, no banner.
	 *
	 * A missing bundle — a build that never ran, or ran into a directory this
	 * install does not have — is NOT the same as the gate being off. By the
	 * time this runs every transport has already emitted its snippet inert, so
	 * returning early leaves a page of text/plain blocks that nothing will ever
	 * activate: the site loses all tracking, silently, for every visitor. That
	 * is deliberately the failure taken. The alternative — printing nothing and
	 * hoping, or fataling on a public page — would either leak tracking past a
	 * gate the page claims to have or take the front end down with it, and no
	 * visitor is tracked without consent while this state lasts. It is a broken
	 * deploy to fix, not a state to render around.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function print_boot(): void {
		if ( ! $this->consent->is_active() ) {
			return;
		}

		$source = $this->boot_source();

		if ( '' === $source ) {
			return;
		}

		wp_print_inline_script_tag(
			// The flags are belt and braces on top of wp_json_encode()'s default
			// escaping of '/', which already stops a '</script>' inside a
			// filtered string or a translation closing this tag. They also
			// neutralize '<!--<script>', which does not close anything but does
			// move the HTML tokenizer into its double-escaped state and change
			// where the parser thinks this script ends.
			'window.taseoConsentConfig=' . wp_json_encode( $this->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';' . $source,
			array( 'id' => 'taseo-consent-boot' )
		);
	}

	/**
	 * Read the built boot bundle, once per request.
	 *
	 * @since 1.6.0
	 *
	 * @return string Bundle contents, or '' when unreadable.
	 */
	private function boot_source(): string {
		if ( null !== $this->source ) {
			return $this->source;
		}

		$this->source = '';

		if ( is_readable( $this->boot_path ) ) {
			$contents = file_get_contents( $this->boot_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- reading this plugin's own built bundle from its own directory to inline it.

			$this->source = is_string( $contents ) ? $contents : '';
		}

		return $this->source;
	}

	/**
	 * Assemble the configuration the boot script reads from
	 * window.taseoConsentConfig.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, mixed> Configuration.
	 */
	private function config(): array {
		$host = $this->domains->get_current_host();

		$config = array(
			'categories'   => $this->consent->categories(),
			'lifetimeDays' => $this->settings->get_consent_lifetime_days(),
			'policyUrl'    => $this->settings->get_consent_policy_url( $host ),
			'uiUrl'        => $this->ui_url(),
			'crawlers'     => '',
			'copy'         => array(
				'title'       => __( 'Before we load tracking', 'the-another-seo' ),
				'body'        => __( 'Analytics and marketing tags run only if you agree to them. You can change your mind at any time.', 'the-another-seo' ),
				'acceptAll'   => __( 'Accept all', 'the-another-seo' ),
				'rejectAll'   => __( 'Reject all', 'the-another-seo' ),
				'preferences' => __( 'Preferences', 'the-another-seo' ),
				'save'        => __( 'Save choices', 'the-another-seo' ),
				'manage'      => __( 'Cookie settings', 'the-another-seo' ),
				'policy'      => __( 'Privacy policy', 'the-another-seo' ),
				'categories'  => array(
					'analytics' => array(
						'label' => __( 'Analytics', 'the-another-seo' ),
						'body'  => __( 'Measuring how the site is used, so it can be improved.', 'the-another-seo' ),
					),
					'marketing' => array(
						'label' => __( 'Marketing', 'the-another-seo' ),
						'body'  => __( 'Advertising and remarketing, including measuring whether an advert worked.', 'the-another-seo' ),
					),
				),
			),
		);

		/**
		 * Filters the configuration handed to the consent boot script.
		 *
		 * Carries the categories offered, the decision lifetime, the privacy
		 * policy URL, the UI bundle URL, an optional crawler pattern and every
		 * string the banner renders. One filter rather than five, because these
		 * are answered together: a site replacing the copy usually replaces the
		 * policy link with it.
		 *
		 * An empty 'crawlers' means the boot script's own pattern is used.
		 *
		 * The answer replaces the array wholesale, but the four keys the boot
		 * script cannot run without — 'categories', 'lifetimeDays', 'uiUrl' and
		 * 'crawlers' — are restored from the values above when it omits them.
		 * Anything else it omits is simply absent.
		 *
		 * @since 1.6.0
		 *
		 * @param array<string, mixed> $config Configuration.
		 * @param string               $host   Normalized host the request arrived on.
		 */
		$config = apply_filters( 'taseo_consent_config', $config, $host );
		$config = is_array( $config ) ? $config : array();

		// The filter replaces the array wholesale, so a site answering it with
		// only the keys it cares about — the natural way to write one — hands
		// the boot script a config missing everything else. Normalising here,
		// rather than trusting the answer, is what keeps the browser's failure
		// modes closed: an absent lifetime would otherwise be compared against
		// NaN in store.js and make every stored decision immortal, an absent
		// uiUrl would become a script src of "undefined", and an absent
		// crawlers key is what already shipped as a defect once.
		$config['categories']   = isset( $config['categories'] ) && is_array( $config['categories'] ) ? array_values( $config['categories'] ) : array();
		$config['lifetimeDays'] = max( 1, (int) ( is_numeric( $config['lifetimeDays'] ?? null ) ? $config['lifetimeDays'] : $this->settings->get_consent_lifetime_days() ) );
		$config['uiUrl']        = isset( $config['uiUrl'] ) && is_string( $config['uiUrl'] ) && '' !== $config['uiUrl'] ? $config['uiUrl'] : $this->ui_url();
		$config['crawlers']     = isset( $config['crawlers'] ) && is_string( $config['crawlers'] ) ? $config['crawlers'] : '';

		return $config;
	}

	/**
	 * URL of the built consent UI bundle, carrying the build's version.
	 *
	 * The boot script loads this with a bare script.src, so nothing about
	 * wp_enqueue_script()'s cache busting applies to it: a browser or a CDN
	 * holding this path keeps serving the previous build's banner after an
	 * update, and the stale thing here is the copy a visitor is shown before
	 * agreeing to tracking. The version comes from the same
	 * dist/<name>/index.asset.php the admin bundles read.
	 *
	 * @since 1.6.0
	 *
	 * @return string URL.
	 */
	private function ui_url(): string {
		$url     = THE_ANOTHER_SEO_PLUGIN_URL . 'dist/consent/index.js';
		$version = $this->ui_version();

		return '' === $version ? $url : $url . '?ver=' . rawurlencode( $version );
	}

	/**
	 * Read the built UI bundle's version, once per request.
	 *
	 * An unreadable asset file degrades to '' — an unversioned URL that still
	 * loads — rather than a query string naming nothing.
	 *
	 * @since 1.6.0
	 *
	 * @return string Version, or '' when unknown.
	 */
	private function ui_version(): string {
		if ( null !== $this->ui_version ) {
			return $this->ui_version;
		}

		$this->ui_version = '';
		$asset_file       = THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/consent/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;

			if ( is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) ) {
				$this->ui_version = $asset['version'];
			}
		}

		return $this->ui_version;
	}
}
