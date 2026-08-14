<?php
/**
 * Orphan Cleaner
 *
 * @package TheAnotherSEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Maintenance;

use RuntimeException;
use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

/**
 * Class OrphanCleaner
 *
 * Finds indexable rows and sitemap files that no longer correspond to
 * anything and removes them. Finding is the only new logic here: every
 * removal goes through the same path the runtime uses, because the
 * "delete one row at a time so the chunk slot is released" invariant is
 * the one whose violation leaves permanently over-counted chunks.
 *
 * Scans are batched by ascending ID cursor, never OFFSET — these tables
 * are written to while the scan runs.
 *
 * @since 1.1.0
 */
class OrphanCleaner {

	/**
	 * Rows examined per batch.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Category keys accepted by clean()'s $only argument.
	 *
	 * @var string
	 */
	public const ONLY_ROWS       = 'rows';
	public const ONLY_DUPLICATES = 'duplicates';
	public const ONLY_FILES      = 'files';

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository   $repository Indexable repository.
	 * @param PostSubtypes          $subtypes   Post subtype registry.
	 * @param SitemapFamilies       $families   Family registry.
	 * @param SitemapFileRepository $files      Chunk registry repository.
	 * @param SitemapStorage        $storage    Storage seam.
	 * @param Settings              $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly PostSubtypes $subtypes,
		private readonly SitemapFamilies $families,
		private readonly SitemapFileRepository $files,
		private readonly SitemapStorage $storage,
		private readonly Settings $settings
	) {
	}

	/**
	 * Remove orphans, or report what would be removed.
	 *
	 * @param bool        $dry_run Count without deleting.
	 * @param string|null $only    One category key, or null for all.
	 * @return array{rows: int, duplicates: int, files: int, skipped: array<int, string>} Counts.
	 * @throws RuntimeException When no families are registered but pushed rows exist.
	 */
	public function clean( bool $dry_run = false, ?string $only = null ): array {
		$result = array(
			'rows'       => 0,
			'duplicates' => 0,
			'files'      => 0,
			'skipped'    => array(),
		);

		if ( null === $only || self::ONLY_ROWS === $only ) {
			$this->assert_families_available();

			$result['rows'] = $this->clean_rows( $dry_run );
		}

		return $result;
	}

	/**
	 * Refuse to run while a provider that owns pushed rows looks inactive.
	 *
	 * An empty family registry means nothing registered a family on this
	 * request. On a site holding pushed URLs that almost always means a
	 * provider plugin is deactivated, not that thousands of rows became
	 * garbage — and those rows can only be rebuilt by that provider's own
	 * backfill, which may only be reachable through its activation hook.
	 *
	 * Deliberately narrow: a registry holding *some* families still drops
	 * rows for the families missing from it, which is the case the category
	 * exists to handle.
	 *
	 * @return void
	 * @throws RuntimeException When the registry is empty and pushed rows exist.
	 */
	private function assert_families_available(): void {
		if ( array() !== $this->families->all() ) {
			return;
		}

		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pushed = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE object_type = 'custom_page' AND object_id > 0"
		);
		// phpcs:enable

		if ( 0 === $pushed ) {
			return;
		}

		throw new RuntimeException(
			esc_html(
				sprintf(
					'No sitemap families are registered, but %d pushed URL rows exist. A provider plugin is probably inactive; refusing to delete rows only that plugin can rebuild.',
					$pushed
				)
			)
		);
	}

	/**
	 * Delete rows whose object no longer exists or no longer belongs.
	 *
	 * @param bool $dry_run Count without deleting.
	 * @return int Rows removed.
	 */
	private function clean_rows( bool $dry_run ): int {
		global $wpdb;

		$table      = IndexablesTable::get_table_name();
		$post_types = $this->settings->get_enabled_post_types();
		$taxonomies = $this->settings->get_enabled_taxonomies();
		$last_id    = 0;
		$removed    = 0;
		$batch_size = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, object_type, object_subtype, object_id FROM {$table}
					WHERE id > %d
					ORDER BY id ASC
					LIMIT %d",
					$last_id,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			// phpcs:enable

			$rows       = is_array( $rows ) ? $rows : array();
			$batch_size = count( $rows );

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];

				if ( ! $this->is_orphan_row( $row, $post_types, $taxonomies ) ) {
					continue;
				}

				++$removed;

				if ( ! $dry_run ) {
					// Through the repository, never a bulk DELETE: the
					// taseo_indexable_deleting listener releases the chunk
					// slot while the row's pointer is still readable.
					$this->repository->delete(
						(string) $row['object_type'],
						(string) $row['object_subtype'],
						(int) $row['object_id']
					);
				}
			}
		} while ( self::BATCH_SIZE === $batch_size );

		return $removed;
	}

	/**
	 * Whether one row's object is gone or out of scope.
	 *
	 * @param array<string, mixed> $row        Row (object_type, object_subtype, object_id).
	 * @param array<int, string>   $post_types Enabled post types.
	 * @param array<int, string>   $taxonomies Enabled taxonomies.
	 * @return bool True when the row should go.
	 */
	private function is_orphan_row( array $row, array $post_types, array $taxonomies ): bool {
		$type    = (string) $row['object_type'];
		$subtype = (string) $row['object_subtype'];
		$id      = (int) $row['object_id'];

		if ( 'post' === $type ) {
			// The subtype is not the post type: a row can read
			// 'aucteeno_item' while its post is a 'product'. Testing the
			// subtype directly against the enabled types would delete every
			// subtype row on the site. post_type_for() returns its argument
			// unchanged for an undeclared key, which is right for the
			// unsplit case.
			if ( ! in_array( $this->subtypes->post_type_for( $subtype ), $post_types, true ) ) {
				return true;
			}

			return null === get_post( $id );
		}

		if ( 'term' === $type ) {
			if ( ! in_array( $subtype, $taxonomies, true ) ) {
				return true;
			}

			$term = get_term( $id, $subtype );

			return ! $term || is_wp_error( $term );
		}

		if ( 'custom_page' === $type && $id > 0 ) {
			return ! $this->families->has( $subtype );
		}

		// object_id 0 rows are the single per-subtype template rows the
		// meta-override path reads, and system_page rows are excluded from
		// sitemap membership entirely. Neither is an orphan.
		return false;
	}
}
