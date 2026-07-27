<?php
/**
 * Verification File Server
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Verification;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class VerificationFileServer
 *
 * Answers requests for webmaster-tools verification files without writing
 * anything to disk. No rewrite rules: there are at most three of these, at
 * fixed paths, and registering rules would force a rewrite flush (and an
 * .htaccess rebuild) on every site that updates. The request arrives here
 * anyway — no such file exists, so the webserver hands it to WordPress,
 * which 404s and fires template_redirect.
 *
 * Bodies are byte-exact. Google, Bing, and Yandex all fail verification if
 * the CMS injects extra whitespace, a BOM, or markup, so nothing is printed
 * before or after the payload.
 */
class VerificationFileServer {

	/**
	 * Bing's filename is fixed; only its token is configurable.
	 *
	 * @var string
	 */
	public const BING_FILENAME = 'BingSiteAuth.xml';

	/**
	 * Content types a verification file may be served as.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_CONTENT_TYPES = array( 'text/plain', 'text/html', 'application/xml' );

	/**
	 * Per-service validation patterns for stored verification-file values.
	 *
	 * Re-applied here at output time so this class never trusts a distant
	 * caller for its own safety: SettingsPage::sanitize_settings() anchors
	 * these same patterns on save, but options are writable outside that
	 * sanitizer (WP-CLI, a migration, this branch's own e2e harness), and a
	 * stored value here becomes both the response body and the $files array
	 * key that an incoming request path is matched against. Must be kept in
	 * agreement with SettingsPage::VERIFICATION_FILE_PATTERNS.
	 *
	 * @var array<string, string>
	 */
	private const FILE_VALUE_PATTERNS = array(
		'verify_google_file' => '/^google[a-z0-9]+\.html$/',
		'verify_bing_file'   => '/^[A-Za-z0-9]+$/',
		'verify_yandex_file' => '/^yandex_[a-z0-9]+\.html$/',
	);

	/**
	 * General safe-filename shape for filter-supplied file keys.
	 *
	 * Deliberately looser than FILE_VALUE_PATTERNS above: the
	 * taseo_verification_files filter is the escape hatch for services with
	 * no dedicated field (Ahrefs, Pinterest, Meta's own file), so it only
	 * guards against path traversal and extension smuggling, not per-vendor
	 * filename shape.
	 *
	 * @var string
	 */
	private const FILTER_KEY_PATTERN = '/^[A-Za-z0-9_-]+\.(html|xml|txt)$/';

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
	 * Priority 0 puts this ahead of the theme and of MetaOutput, so nothing
	 * has printed when the body is emitted. 0 accepted args for the same
	 * reason documented in SettingsPage::init().
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'template_redirect', array( $this, 'maybe_serve' ), 0, 0 );
	}

	/**
	 * Serve a verification file when the request path matches one.
	 *
	 * @param bool $do_exit Exit after output (false in tests).
	 * @return void
	 */
	public function maybe_serve( bool $do_exit = true ): void {
		$path = $this->request_path();

		if ( '' === $path ) {
			return;
		}

		$files = $this->build_files();

		/**
		 * Filters the verification files this site serves, keyed by filename.
		 *
		 * Each value is an array with 'content_type' and 'body'. The body is
		 * emitted verbatim — byte-exactness is the whole point of the method.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{content_type: string, body: string}> $files Files.
		 */
		$files = apply_filters( 'taseo_verification_files', $files );

		if ( ! is_array( $files ) ) {
			return;
		}

		// The design spec promises filtered keys are run through the same
		// filename regex shape as everything else this method serves; only
		// the content-type allow-list below was actually wired up. This
		// method runs on every frontend request, so an unvalidated filter
		// key could shadow any real URL on the site.
		foreach ( array_keys( $files ) as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( self::FILTER_KEY_PATTERN, $key ) ) {
				unset( $files[ $key ] );
			}
		}

		if ( ! isset( $files[ $path ] ) || ! is_array( $files[ $path ] ) ) {
			return;
		}

		$file = $files[ $path ];

		if ( ! isset( $file['content_type'], $file['body'] ) || ! is_string( $file['body'] ) ) {
			return;
		}

		$content_type = (string) $file['content_type'];

		if ( ! in_array( $content_type, self::ALLOWED_CONTENT_TYPES, true ) ) {
			return;
		}

		status_header( 200 );
		$this->send_headers( $content_type );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- byte-exact verification payload; any escaping fails verification.
		echo $file['body'];

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Send the response headers. Mirrors SitemapServer::send_xml_headers().
	 *
	 * @param string $content_type Content type without charset.
	 * @return void
	 */
	private function send_headers( string $content_type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * Request path relative to the site root, without query string.
	 *
	 * @return string Path, '' for the site root itself.
	 */
	private function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $uri ) {
			return '';
		}

		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( (string) home_url(), PHP_URL_PATH ), '/' );

		if ( '' === $home ) {
			return $path;
		}

		if ( $path === $home ) {
			return '';
		}

		return str_starts_with( $path, $home . '/' )
			? substr( $path, strlen( $home ) + 1 )
			: $path;
	}

	/**
	 * Build the configured files, keyed by filename.
	 *
	 * @return array<string, array{content_type: string, body: string}> Files.
	 */
	private function build_files(): array {
		$files = array();

		$google = $this->settings->get_verification_file( 'google' );

		if ( '' !== $google && 1 === preg_match( self::FILE_VALUE_PATTERNS['verify_google_file'], $google ) ) {
			$files[ $google ] = array(
				'content_type' => 'text/html',
				'body'         => 'google-site-verification: ' . $google,
			);
		}

		$bing = $this->settings->get_verification_file( 'bing' );

		if ( '' !== $bing && 1 === preg_match( self::FILE_VALUE_PATTERNS['verify_bing_file'], $bing ) ) {
			$files[ self::BING_FILENAME ] = array(
				'content_type' => 'application/xml',
				'body'         => "<?xml version=\"1.0\"?>\n<users>\n  <user>" . $bing . "</user>\n</users>",
			);
		}

		$yandex = $this->settings->get_verification_file( 'yandex' );

		if ( '' !== $yandex && 1 === preg_match( self::FILE_VALUE_PATTERNS['verify_yandex_file'], $yandex ) ) {
			$token = substr( $yandex, strlen( 'yandex_' ), -strlen( '.html' ) );

			$files[ $yandex ] = array(
				'content_type' => 'text/html',
				'body'         => "<html>\n<head>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n</head>\n<body>Verification: " . $token . "</body>\n</html>",
			);
		}

		return $files;
	}
}
