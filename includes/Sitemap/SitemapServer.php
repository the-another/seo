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
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapFileWriter     $writer   File writer (path/name helpers).
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapFileWriter $writer,
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
	}

	/**
	 * Add the rewrite rules (flushed once via Installer's flag, Task 9).
	 *
	 * @return void
	 */
	public function register_rewrites(): void {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule(
			'^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$',
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

		if ( '' === $kind || ! $this->settings->is_sitemap_enabled() ) {
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
			if ( (int) $chunk['link_count'] < 1 ) {
				continue;
			}

			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( home_url( '/' . $this->writer->get_file_name( $chunk ) ) ) . "</loc>\n";

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
	 * WP fallback for chunk URLs: stream the pre-built physical file.
	 *
	 * @return void
	 */
	private function serve_chunk(): void {
		$subtype = sanitize_key( (string) get_query_var( 'taseo_sitemap_subtype' ) );
		$number  = (int) get_query_var( 'taseo_sitemap_chunk' );

		$path = $this->writer->get_file_path(
			array(
				'object_subtype' => $subtype,
				'chunk_number'   => $number,
			)
		);

		if ( '' === $subtype || $number < 1 || ! file_exists( $path ) ) {
			status_header( 404 );

			return;
		}

		status_header( 200 );
		$this->send_xml_headers();

		// Never generated here — only reads what the sweep already built.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- streaming a plugin-generated local static file is the designed fallback path.
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
	 * @param string $rules mod_rewrite block WP is about to write.
	 * @return string Rules.
	 */
	public function prepend_apache_static_rules( string $rules ): string {
		if ( ! $this->settings->is_sitemap_enabled() ) {
			return $rules;
		}

		$uploads = wp_upload_dir();
		$base    = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );

		if ( '' === $base ) {
			return $rules;
		}

		$directory = $base . '/' . SitemapFileWriter::DIRECTORY;

		$snippet  = "# BEGIN The Another SEO sitemap files\n";
		$snippet .= "<IfModule mod_rewrite.c>\n";
		$snippet .= "RewriteEngine On\n";
		$snippet .= 'RewriteCond %{DOCUMENT_ROOT}' . $directory . '/$1-sitemap-$2.xml -f' . "\n";
		$snippet .= 'RewriteRule ^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ ' . $directory . '/$1-sitemap-$2.xml [L]' . "\n";
		$snippet .= "</IfModule>\n";
		$snippet .= "# END The Another SEO sitemap files\n\n";

		return $snippet . $rules;
	}
}
