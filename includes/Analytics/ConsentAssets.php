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
	 * no boot script, no banner. A missing bundle — a build that never ran,
	 * or ran into a directory this install does not have — is the same as
	 * consent mode being off, never a fatal error on a public page.
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
			'window.taseoConsentConfig=' . wp_json_encode( $this->config() ) . ';' . $source,
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
			'uiUrl'        => THE_ANOTHER_SEO_PLUGIN_URL . 'dist/consent/index.js',
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
		 * @since 1.6.0
		 *
		 * @param array<string, mixed> $config Configuration.
		 * @param string               $host   Normalized host the request arrived on.
		 */
		$config = apply_filters( 'taseo_consent_config', $config, $host );

		return is_array( $config ) ? $config : array();
	}
}
