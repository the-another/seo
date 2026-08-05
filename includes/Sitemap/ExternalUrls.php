<?php
/**
 * External Sitemap URLs
 *
 * @package TheAnotherSEO
 * @since 0.3.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;

/**
 * Class ExternalUrls
 *
 * The push API's engine: validates pushes from other plugins and delegates
 * to the indexable repository, whose taseo_indexable_synced /
 * taseo_indexable_deleting actions carry chunk assignment exactly as the
 * post/term pipeline's writes do. Rows are stored as
 * custom_page:{family}:{provider id}; object_id 0 is reserved for the
 * custom page's single template row and is rejected here.
 */
class ExternalUrls {

	/**
	 * Constructor.
	 *
	 * @param SitemapFamilies       $families   Family registry.
	 * @param IndexableRepository   $repository Indexable repository.
	 * @param SitemapFileRepository $files      Chunk registry repository.
	 * @param SitemapStorage        $storage    Storage seam.
	 */
	public function __construct(
		private readonly SitemapFamilies $families,
		private readonly IndexableRepository $repository,
		private readonly SitemapFileRepository $files,
		private readonly SitemapStorage $storage
	) {
	}

	/**
	 * Insert or update one URL in a registered family.
	 *
	 * @param string $family Family key registered via taseo_sitemap_families.
	 * @param int    $id     Provider-chosen stable identifier, unique within
	 *                       the family, > 0.
	 * @param array  $args   permalink (required, absolute, this site's host),
	 *                       last_modified? (GMT Y-m-d H:i:s), images?
	 *                       (absolute URLs), is_indexable? (default true).
	 * @return bool True when the row was written.
	 */
	public function sync_url( string $family, int $id, array $args ): bool {
		if ( $id < 1 || ! $this->families->has( $family ) ) {
			return false;
		}

		$permalink = isset( $args['permalink'] ) && is_string( $args['permalink'] ) ? $args['permalink'] : '';

		if ( ! $this->is_same_host_url( $permalink ) ) {
			// A cross-host URL would be invalid in the file (sitemaps.org
			// same-host rule) — rejected at the door, not at render time.
			return false;
		}

		$fields = array(
			'permalink'    => esc_url_raw( $permalink, array( 'http', 'https' ) ),
			'is_indexable' => ! isset( $args['is_indexable'] ) || (bool) $args['is_indexable'],
			'images'       => isset( $args['images'] ) && is_array( $args['images'] ) ? $args['images'] : array(),
		);

		if ( isset( $args['last_modified'] ) && is_string( $args['last_modified'] ) && '' !== $args['last_modified'] ) {
			$fields['last_modified'] = $args['last_modified'];
		}

		$this->repository->upsert_synced_fields( 'custom_page', $family, $id, $fields );

		return true;
	}

	/**
	 * Delete one URL (its chunk slot is released via the repository's
	 * taseo_indexable_deleting action while the pointer is still readable).
	 *
	 * @param string $family Family key.
	 * @param int    $id     Provider identifier.
	 * @return void
	 */
	public function delete_url( string $family, int $id ): void {
		if ( $id < 1 ) {
			return;
		}

		$this->repository->delete( 'custom_page', $family, $id );
	}

	/**
	 * Bulk-remove a whole family: pointers, chunk registry rows, physical
	 * files, indexable rows — in that order, so no row ever references a
	 * deleted chunk.
	 *
	 * Deliberately does not require the family to still be registered: this
	 * runs from provider deactivation hooks, after the provider's filter may
	 * be gone. Rows are deleted in bulk without per-row actions — the slots
	 * are already released wholesale, and thousands of actions in one
	 * deactivation request is exactly the mass-operation shape the per-row
	 * path exists to avoid.
	 *
	 * @param string $family Family key.
	 * @return void
	 */
	public function delete_family( string $family ): void {
		global $wpdb;

		$chunks = $this->files->get_chunks_for_subtype( $family );

		$indexables = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$indexables} SET sitemap_file_id = NULL WHERE object_type = 'custom_page' AND object_subtype = %s",
				$family
			)
		);
		// phpcs:enable

		$this->files->delete_chunks_for_subtype( $family );

		foreach ( $chunks as $chunk ) {
			$this->storage->delete( $chunk );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$indexables} WHERE object_type = 'custom_page' AND object_subtype = %s",
				$family
			)
		);
		// phpcs:enable
	}

	/**
	 * Absolute http(s) URL on this site's host.
	 *
	 * @param string $url Candidate permalink.
	 * @return bool Valid.
	 */
	private function is_same_host_url( string $url ): bool {
		if ( '' === $url || ( ! str_starts_with( $url, 'http://' ) && ! str_starts_with( $url, 'https://' ) ) ) {
			return false;
		}

		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $url_host ) && is_string( $home_host )
			&& strtolower( $url_host ) === strtolower( $home_host );
	}
}
