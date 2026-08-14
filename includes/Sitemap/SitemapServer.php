<?php
/**
 * Sitemap Server
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SitemapServer
 *
 * Serves /sitemap.xml (root index, generated live — a query over a few
 * thousand small registry rows) and the root-level chunk URLs. Chunk URLs
 * are root-level because the sitemaps.org protocol scopes a sitemap to URLs
 * at or below its own directory — a urlset served from uploads/ could not
 * legitimately list site URLs (Bing enforces this even though Google
 * relaxes it for robots.txt-submitted sitemaps).
 *
 * Chunk serving is two-tier: an Apache .htaccess block (via the
 * mod_rewrite_rules filter) serves the physical file without loading
 * WordPress; the WP rewrite fallback streams the pre-built file via
 * readfile(). Neither path ever generates content on the fly.
 *
 * Nginx equivalent of the Apache block (manual, server config):
 *
 *     location ~ ^/([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ {
 *         try_files /wp-content/uploads/taseo-sitemaps/$1-sitemap-$2.xml /index.php?taseo_sitemap=chunk&taseo_sitemap_subtype=$1&taseo_sitemap_chunk=$2;
 *     }
 */
class SitemapServer {

	/**
	 * Main query var carrying the request kind ('index' or 'chunk').
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'taseo_sitemap';

	/**
	 * Rewrite pattern for the root index.
	 *
	 * @var string
	 */
	public const PATTERN_INDEX = '^sitemap\.xml$';

	/**
	 * Rewrite pattern for root-level chunk URLs.
	 *
	 * @var string
	 */
	public const PATTERN_CHUNK = SitemapStorage::CHUNK_NAME_PATTERN;

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapStorage        $storage  Storage seam (path/name helpers, existence, streaming).
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapStorage $storage,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 *
	 * The maybe_serve method is registered with 0 accepted args on purpose: WP's
	 * do_action() passes a legacy '' argument to 1-arg callbacks on no-arg
	 * hooks, which would silently falsify the $do_exit default.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'init', array( $this, 'register_rewrites' ) );
		$hook_manager->register_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		$hook_manager->register_action( 'template_redirect', array( $this, 'maybe_serve' ), 0, 0 );
		$hook_manager->register_filter( 'robots_txt', array( $this, 'append_sitemap_line' ), 10, 2 );
		$hook_manager->register_filter( 'mod_rewrite_rules', array( $this, 'prepend_apache_static_rules' ) );
		$hook_manager->register_filter( 'wp_sitemaps_enabled', array( $this, 'filter_core_sitemaps' ) );
	}

	/**
	 * Add the rewrite rules (flushed once via Installer's flag, Task 9).
	 *
	 * @return void
	 */
	public function register_rewrites(): void {
		add_rewrite_rule( self::PATTERN_INDEX, 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule(
			self::PATTERN_CHUNK,
			'index.php?' . self::QUERY_VAR . '=chunk&taseo_sitemap_subtype=$matches[1]&taseo_sitemap_chunk=$matches[2]',
			'top'
		);
	}

	/**
	 * Whitelist the query vars.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string> Vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'taseo_sitemap_subtype';
		$vars[] = 'taseo_sitemap_chunk';

		return $vars;
	}

	/**
	 * Serve sitemap requests on template_redirect.
	 *
	 * @param bool $do_exit Exit after serving (false in tests).
	 * @return void
	 */
	public function maybe_serve( bool $do_exit = true ): void {
		$kind = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $kind ) {
			return;
		}

		if ( ! $this->settings->is_sitemap_enabled() ) {
			// The rewrite still matched, so WP would otherwise fall through
			// to its normal template (e.g. the homepage) with a 200 — wrong
			// for a URL crawlers expect to be a sitemap. Report 404 and let
			// WP continue rendering whatever template it resolves to; only
			// the status code matters here.
			status_header( 404 );

			return;
		}

		if ( 'index' === $kind ) {
			status_header( 200 );
			$this->send_xml_headers();
			echo $this->render_root_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; every value escaped during rendering.
		} elseif ( 'chunk' === $kind ) {
			$this->serve_chunk();
		} else {
			return;
		}

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Render the <sitemapindex> from current registry state.
	 *
	 * Deliberately live (no dirty-tracking, no caching lag on our side):
	 * the registry stays small at any catalog size.
	 *
	 * @return string XML document.
	 */
	public function render_root_index(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $this->files->get_all_chunks() as $chunk ) {
			if ( (int) $chunk['link_count'] < 1 || empty( $chunk['generated_at'] ) ) {
				// A chunk can be claimed (link_count > 0) before the sweep has
				// ever written its file — listing it here would 404 during
				// the initial backfill window.
				continue;
			}

			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( home_url( '/' . $this->storage->get_file_name( $chunk ) ) ) . "</loc>\n";

			$lastmod = SitemapFileWriter::format_lastmod(
				isset( $chunk['last_modified'] ) ? (string) $chunk['last_modified'] : null
			);

			if ( null !== $lastmod ) {
				$xml .= "\t\t<lastmod>" . $lastmod . "</lastmod>\n";
			}

			$xml .= "\t</sitemap>\n";
		}

		return $xml . '</sitemapindex>' . "\n";
	}

	/**
	 * WP fallback for chunk URLs: stream the pre-built physical file, or
	 * report the file's absence with the status that matches why.
	 *
	 * Response matrix (the subtype/number guard aside):
	 * - Physical file exists: 200, XML headers, streamed body.
	 * - No file, registry row exists with link_count = 0: 410 — the chunk
	 *   existed and was emptied (tombstoned).
	 * - No file, registry row exists with link_count > 0, or no row at all:
	 *   404 — either temporarily gone (disabled family, or claimed before
	 *   the first sweep writes it) or this URL never existed.
	 *
	 * @return void
	 */
	private function serve_chunk(): void {
		$subtype = sanitize_key( (string) get_query_var( 'taseo_sitemap_subtype' ) );
		$number  = (int) get_query_var( 'taseo_sitemap_chunk' );

		if ( '' === $subtype || $number < 1 ) {
			status_header( 404 );

			return;
		}

		$chunk = array(
			'object_subtype' => $subtype,
			'chunk_number'   => $number,
		);

		if ( $this->storage->exists( $chunk ) ) {
			status_header( 200 );
			$this->send_xml_headers();
			$this->storage->stream( $chunk );

			return;
		}

		$row = $this->files->get_by_subtype_and_number( $subtype, $number );

		if ( null !== $row && 0 === (int) $row['link_count'] ) {
			// A tombstoned chunk: existed, was emptied, is not a document.
			status_header( 410 );

			return;
		}

		status_header( 404 );
	}

	/**
	 * Disable core's /wp-sitemap.xml while this module serves its own tree —
	 * two competing sitemap indexes confuse crawlers. Core's stays available
	 * when the feature is toggled off.
	 *
	 * @param bool $enabled Core default.
	 * @return bool Enabled.
	 */
	public function filter_core_sitemaps( $enabled ) {
		return $this->settings->is_sitemap_enabled() ? false : (bool) $enabled;
	}

	/**
	 * Content headers shared by both serving paths.
	 *
	 * @return void
	 */
	private function send_xml_headers(): void {
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * Add the standard Sitemap: line to robots.txt.
	 *
	 * @param string $output    Robots.txt body.
	 * @param mixed  $is_public 'blog_public' option value (string '0'/'1').
	 * @return string Body.
	 */
	public function append_sitemap_line( string $output, $is_public ): string {
		if ( ! $is_public || ! $this->settings->is_sitemap_enabled() ) {
			return $output;
		}

		return rtrim( $output, "\n" ) . "\n\nSitemap: " . esc_url( home_url( '/sitemap.xml' ) ) . "\n";
	}

	/**
	 * Prepend static-serving rules to the .htaccess block WP writes.
	 *
	 * The -f condition serves the physical file directly (WordPress never
	 * loads); a missing file falls through to WP's rules and lands in the
	 * serve_chunk() fallback. Non-Apache hosts simply never apply this
	 * filter's output and always use the fallback.
	 *
	 * Stream-wrapped uploads (e.g. s3://…) suppress this block entirely: the
	 * target path cannot exist on local disk, so an -f condition against it
	 * is dead configuration. Every chunk request then falls through to the
	 * WP fallback, which streams through the wrapper via SitemapStorage —
	 * chunks always serve from the site origin regardless (the sitemaps.org
	 * host-scoping rule; Bing enforces it even though Google is lenient).
	 *
	 * @param string $rules mod_rewrite block WP is about to write.
	 * @return string Rules.
	 */
	public function prepend_apache_static_rules( string $rules ): string {
		if ( ! $this->settings->is_sitemap_enabled() || $this->storage->is_stream_wrapped() ) {
			return $rules;
		}

		$uploads = wp_upload_dir();
		$base    = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );

		if ( '' === $base ) {
			return $rules;
		}

		$directory = $base . '/' . SitemapStorage::DIRECTORY;

		$snippet  = "# BEGIN The Another SEO sitemap files\n";
		$snippet .= "<IfModule mod_rewrite.c>\n";
		$snippet .= "RewriteEngine On\n";
		$snippet .= 'RewriteCond %{DOCUMENT_ROOT}' . $directory . '/$1-sitemap-$2.xml -f' . "\n";
		$snippet .= 'RewriteRule ' . self::PATTERN_CHUNK . ' ' . $directory . '/$1-sitemap-$2.xml [L]' . "\n";
		$snippet .= "</IfModule>\n";
		$snippet .= "# END The Another SEO sitemap files\n\n";

		return $snippet . $rules;
	}
}
