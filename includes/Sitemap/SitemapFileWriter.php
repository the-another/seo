<?php
/**
 * Sitemap File Writer
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\IndexablesTable;

/**
 * Class SitemapFileWriter
 *
 * Renders and writes one chunk's physical XML file. A file is always a pure
 * rendering of "indexable rows currently pointing at this chunk", so rebuilds
 * are idempotent — two processes racing on the same chunk produce a redundant
 * write, never corruption. The dirty flag is only cleared after a successful
 * write, so failures self-heal on the next sweep.
 */
class SitemapFileWriter {

	/**
	 * Directory under uploads/ holding all chunk files.
	 *
	 * @var string
	 */
	public const DIRECTORY = 'taseo-sitemaps';

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files Registry repository.
	 */
	public function __construct( private readonly SitemapFileRepository $files ) {
	}

	/**
	 * Absolute path of the sitemap directory.
	 *
	 * @return string Path without trailing slash.
	 */
	public function get_directory_path(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( (string) $uploads['basedir'] ) . self::DIRECTORY;
	}

	/**
	 * File name for a chunk: {subtype}-sitemap-{n}.xml.
	 *
	 * @param array<string, mixed> $chunk Registry row (object_subtype, chunk_number).
	 * @return string File name.
	 */
	public function get_file_name( array $chunk ): string {
		return sprintf( '%s-sitemap-%d.xml', (string) $chunk['object_subtype'], (int) $chunk['chunk_number'] );
	}

	/**
	 * Absolute path of a chunk's file.
	 *
	 * @param array<string, mixed> $chunk Registry row.
	 * @return string Path.
	 */
	public function get_file_path( array $chunk ): string {
		return trailingslashit( $this->get_directory_path() ) . $this->get_file_name( $chunk );
	}

	/**
	 * Whether sitemap files can be written at all.
	 *
	 * @return bool Uploads dir resolved and writable.
	 */
	public function is_writable(): bool {
		$uploads = wp_upload_dir();

		return empty( $uploads['error'] ) && wp_is_writable( (string) $uploads['basedir'] );
	}

	/**
	 * Convert a stored GMT DATETIME to the W3C datetime sitemaps use.
	 *
	 * @param string|null $datetime 'Y-m-d H:i:s' in GMT, or null.
	 * @return string|null W3C datetime or null when absent/invalid.
	 */
	public static function format_lastmod( ?string $datetime ): ?string {
		if ( null === $datetime || '' === $datetime ) {
			return null;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s+00:00', $timestamp );
	}

	/**
	 * Rebuild one chunk's physical file from current DB state.
	 *
	 * Bounded to at most the chunk cap (<=1000) member rows regardless of
	 * catalog size — that's the whole point of stored assignment.
	 *
	 * @param array<string, mixed> $chunk Registry row.
	 * @return bool True when the file was written and the dirty flag cleared.
	 */
	public function rebuild( array $chunk ): bool {
		global $wpdb;

		$indexables = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT permalink, last_modified FROM {$indexables} WHERE sitemap_file_id = %d AND is_indexable = 1 ORDER BY id ASC",
				(int) $chunk['id']
			),
			ARRAY_A
		);
		// phpcs:enable

		$rows = is_array( $rows ) ? $rows : array();

		// Rows without a permalink render nothing — drop them up front so the
		// file and the chunk's stamped last_modified agree on membership.
		$rows = array_values(
			array_filter( $rows, static fn( array $row ): bool => '' !== (string) ( $row['permalink'] ?? '' ) )
		);

		if ( ! wp_mkdir_p( $this->get_directory_path() ) ) {
			return false;
		}

		if ( ! $this->write_file( $this->get_file_path( $chunk ), $this->render_urlset( $rows ) ) ) {
			return false;
		}

		$max_modified = null;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['last_modified'] ) && ( null === $max_modified || $row['last_modified'] > $max_modified ) ) {
				$max_modified = (string) $row['last_modified'];
			}
		}

		$this->files->update_after_rebuild( (int) $chunk['id'], $max_modified );

		return true;
	}

	/**
	 * Remove a chunk's physical file (chunk emptied and deleted).
	 *
	 * @param array<string, mixed> $chunk Registry row.
	 * @return void
	 */
	public function delete_file( array $chunk ): void {
		$path = $this->get_file_path( $chunk );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Render the <urlset> document: <loc> + <lastmod> only, per spec.
	 *
	 * @param array<int, array<string, mixed>> $rows Member rows (permalink, last_modified).
	 * @return string XML document.
	 */
	private function render_urlset( array $rows ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $rows as $row ) {
			$loc = (string) ( $row['permalink'] ?? '' );

			if ( '' === $loc ) {
				continue;
			}

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";

			$lastmod = self::format_lastmod( isset( $row['last_modified'] ) ? (string) $row['last_modified'] : null );

			if ( null !== $lastmod ) {
				$xml .= "\t\t<lastmod>" . $lastmod . "</lastmod>\n";
			}

			$xml .= "\t</url>\n";
		}

		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Write via the WP Filesystem API.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents File contents.
	 * @return bool Success.
	 */
	private function write_file( string $path, string $contents ): bool {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $contents, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
	}
}
