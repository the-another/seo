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
			// The index is the one response with no subtype of its own, so
			// it takes the global value rather than a per-type override.
			$this->send_cache_control( $this->settings->get_sitemap_cache_ttl() );
			echo $this->filter_xml( $this->render_root_index() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; every value escaped during rendering.
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
			if ( ! $this->files->is_listable( $chunk ) ) {
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
	 * - Live row, and the request's If-Modified-Since covers the time the
	 *   file was written: 304, no storage touch at all.
	 * - Physical file exists: 200, XML headers, Last-Modified, streamed body.
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

		// The registry row is read up front, before any storage touch. Where
		// uploads are offloaded to a stream wrapper (s3://…) every exists(),
		// read() and stream() call is a network round trip to the bucket,
		// while this is one indexed lookup on a table that stays in the low
		// thousands of rows at any catalog size. It answers both of the
		// questions that do not need the file — "unchanged since the crawler
		// last saw it" and "gone" — and the row was already being read on the
		// miss path anyway, so the hit path is the only one paying for it.
		$row          = $this->files->get_by_subtype_and_number( $subtype, $number );
		$generated_at = $this->files->is_listable( $row ) ? (string) $row['generated_at'] : null;
		$ttl          = $this->settings->get_sitemap_cache_ttl_for( $subtype );

		if ( null !== $generated_at && $this->is_unmodified_since( $generated_at ) ) {
			// The crawler already holds exactly this file. Answering from the
			// row alone is the whole point: no bucket round trip, and none of
			// the up-to-a-megabyte body. Chunk files settle under append-only
			// packing, so this is the common case for everything but the tail.
			status_header( 304 );
			$this->send_last_modified( $generated_at );
			// RFC 9110 asks a 304 to carry the headers the 200 would have.
			// Without the freshness, a cache that revalidated once would
			// revalidate again on the very next request.
			$this->send_cache_control( $ttl );

			return;
		}

		if ( has_filter( 'taseo_sitemap_xml' ) ) {
			// A subscriber may need to transform the XML per request (a
			// multi-domain plugin rewriting hosts), so the file is read into
			// memory instead of streamed. read() doubling as the existence
			// check keeps this path's miss handling identical to stream()'s.
			$xml = $this->storage->read( $chunk );

			if ( null !== $xml ) {
				status_header( 200 );
				$this->send_xml_headers();
				$this->send_last_modified( $generated_at );
				$this->send_cache_control( $ttl );
				echo $this->filter_xml( $xml ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; every value escaped when the file was rendered.

				return;
			}
		} elseif ( $this->storage->exists( $chunk ) ) {
			status_header( 200 );
			$this->send_xml_headers();
			$this->send_last_modified( $generated_at );
			$this->send_cache_control( $ttl );
			$this->storage->stream( $chunk );

			return;
		}

		if ( null !== $row && 0 === (int) $row['link_count'] ) {
			// A tombstoned chunk: existed, was emptied, is not a document.
			status_header( 410 );

			return;
		}

		status_header( 404 );
	}

	/**
	 * Whether the request already holds the version of a chunk written at
	 * this timestamp.
	 *
	 * Compared against generated_at — when the sweep last wrote the file —
	 * rather than the chunk's last_modified, which is the newest member's
	 * modification time and says nothing about when the bytes were produced.
	 *
	 * @since 1.3.0
	 * @param string $generated_at Chunk generated_at, GMT 'Y-m-d H:i:s'.
	 * @return bool True when a 304 is the correct answer.
	 */
	private function is_unmodified_since( string $generated_at ): bool {
		if ( ! isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a conditional GET of a public document carries no nonce.
		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );
		$since  = '' === $header ? false : strtotime( $header );

		if ( false === $since ) {
			return false;
		}

		$generated = strtotime( $generated_at . ' UTC' );

		return false !== $generated && $since >= $generated;
	}

	/**
	 * Send the Cache-Control header for a sitemap response.
	 *
	 * `public` is stated explicitly: a sitemap is a public document by
	 * definition, and without it a shared cache in front of WordPress is free
	 * to treat the response as private and store nothing. That matters most
	 * where the Apache static-serve block cannot apply — offloaded uploads —
	 * because there every miss is a WordPress boot plus a bucket round trip.
	 *
	 * A TTL of zero renders as `max-age=0`, i.e. revalidate every time, which
	 * the conditional-request path above answers cheaply.
	 *
	 * @since 1.3.0
	 * @param int $ttl Freshness lifetime in seconds.
	 * @return void
	 */
	private function send_cache_control( int $ttl ): void {
		header( 'Cache-Control: public, max-age=' . max( 0, $ttl ) );
	}

	/**
	 * Send the Last-Modified header a conditional request can come back with.
	 *
	 * Sent on the 304 as well as the 200: RFC 9110 asks a 304 to carry the
	 * validators the 200 would have.
	 *
	 * @since 1.3.0
	 * @param string|null $generated_at Chunk generated_at, or null when the
	 *                                  chunk has no written-file timestamp.
	 * @return void
	 */
	private function send_last_modified( ?string $generated_at ): void {
		if ( null === $generated_at ) {
			return;
		}

		$timestamp = strtotime( $generated_at . ' UTC' );

		if ( false === $timestamp ) {
			return;
		}

		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $timestamp ) . ' GMT' );
	}

	/**
	 * Pass a served XML document through the taseo_sitemap_xml filter.
	 *
	 * Both egresses (the live root index and the chunk fallback) route through
	 * here, so a subscriber sees every sitemap byte this plugin serves via
	 * PHP. Fails open: a subscriber returning a non-string is ignored rather
	 * than corrupting the document.
	 *
	 * @since 1.2.0
	 * @param string $xml XML document about to be served.
	 * @return string XML document, possibly transformed.
	 */
	private function filter_xml( string $xml ): string {
		/**
		 * Filters a sitemap XML document as it is served.
		 *
		 * Runs on the root index and on every chunk served through the WP
		 * fallback. This plugin itself always renders canonical-host URLs;
		 * a multi-domain plugin can subscribe to rewrite them to the host
		 * actually being browsed. The Apache/LiteSpeed static-serve rules are
		 * host-scoped to the canonical host so any other host reaches this
		 * filter instead of the raw file.
		 *
		 * @since 1.2.0
		 *
		 * @param string $xml XML document about to be served.
		 */
		$filtered = apply_filters( 'taseo_sitemap_xml', $xml );

		return is_string( $filtered ) ? $filtered : $xml;
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

		$host = strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );

		if ( '' !== $host ) {
			// Static serving is host-scoped to the canonical home host (www
			// and apex forms): the raw file always carries canonical-host
			// URLs, so a request on any other domain this install answers on
			// (a multi-domain/Brand host) must fall through to the WP
			// fallback, where the taseo_sitemap_xml filter can rewrite them.
			$snippet .= 'RewriteCond %{HTTP_HOST} ^(?:www\.)?' . preg_quote( $host, '#' ) . '(?::\d+)?$ [NC]' . "\n";
		}

		$snippet .= 'RewriteCond %{DOCUMENT_ROOT}' . $directory . '/$1-sitemap-$2.xml -f' . "\n";
		$snippet .= 'RewriteRule ' . self::PATTERN_CHUNK . ' ' . $directory . '/$1-sitemap-$2.xml [L]' . "\n";
		$snippet .= "</IfModule>\n";
		$snippet .= "# END The Another SEO sitemap files\n\n";

		return $snippet . $rules;
	}
}
