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
 * Renders one chunk's <urlset> XML from the member query and hands it to
 * SitemapStorage for the actual filesystem write. A file is always a pure
 * rendering of "indexable rows currently pointing at this chunk", so
 * rebuilds are idempotent — two processes racing on the same chunk produce a
 * redundant write, never corruption. The dirty flag is only cleared after a
 * successful write, so failures self-heal on the next sweep.
 */
class SitemapFileWriter {

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files   Registry repository.
	 * @param SitemapStorage        $storage Storage seam.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapStorage $storage
	) {
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
				"SELECT permalink, last_modified, sitemap_images FROM {$indexables} WHERE sitemap_file_id = %d AND is_indexable = 1 ORDER BY id ASC",
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

		if ( array() === $rows ) {
			// Nothing to render: never write an empty urlset (the Apache path
			// would serve it as a live 200). Remove the file and tombstone the
			// row when it is genuinely empty; the fallback covers the racy
			// case of a chunk whose slots are claimed but whose member rows
			// are not yet visible — cleared of its dirty flag, it waits for
			// the next real edit instead of hot-looping through the sweep.
			$this->storage->delete( $chunk );

			if ( ! $this->files->tombstone_chunk( (int) $chunk['id'] ) ) {
				$this->files->update_after_rebuild( (int) $chunk['id'], null );
			}

			return true;
		}

		if ( ! $this->storage->write( $chunk, $this->render_urlset( $rows ) ) ) {
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
	 * Render the <urlset> document: <loc>, <lastmod>, and <image:image> entries.
	 *
	 * The image namespace is declared unconditionally — an unused namespace
	 * is valid XML, and declaring it only when a row has images would make
	 * one chunk's header depend on the content of sibling rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Member rows (permalink, last_modified, sitemap_images).
	 * @return string XML document.
	 */
	private function render_urlset( array $rows ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

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

			foreach ( $this->decode_images( $row ) as $image_url ) {
				$xml .= "\t\t<image:image><image:loc>" . esc_url( $image_url ) . "</image:loc></image:image>\n";
			}

			$xml .= "\t</url>\n";
		}

		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Stored JSON to a list of image URLs; malformed data renders as none,
	 * never as an error.
	 *
	 * @param array<string, mixed> $row Member row.
	 * @return array<int, string> URLs.
	 */
	private function decode_images( array $row ): array {
		if ( empty( $row['sitemap_images'] ) || ! is_string( $row['sitemap_images'] ) ) {
			return array();
		}

		$decoded = json_decode( $row['sitemap_images'], true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( $decoded, 'is_string' ) );
	}
}
