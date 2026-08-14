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

		if ( null === $only || self::ONLY_DUPLICATES === $only ) {
			$result['duplicates'] = $this->clean_duplicates( $dry_run );
		}

		if ( null === $only || self::ONLY_FILES === $only ) {
			$result['files'] = $this->clean_files( $dry_run, $result['skipped'] );
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

	/**
	 * Collapse objects holding rows under more than one subtype.
	 *
	 * Posts only. A term cannot change taxonomy, and custom_page IDs collide
	 * across families by design — vendor_store:42 and vendor_items:42 are one
	 * vendor's two URLs, so grouping by object_id there would delete one of
	 * every pair. IndexableRepository::purge_stale_subtypes() documents the
	 * same scoping.
	 *
	 * A post that no longer exists is skipped: clean_rows() owns that case,
	 * which is why the categories run in that order.
	 *
	 * Counted per object collapsed, not per row deleted, so the number
	 * matches the number of URLs that stopped being published twice.
	 *
	 * @param bool $dry_run Count without purging.
	 * @return int Objects collapsed.
	 */
	private function clean_duplicates( bool $dry_run ): int {
		global $wpdb;

		$table      = IndexablesTable::get_table_name();
		$last_id    = 0;
		$collapsed  = 0;
		$batch_size = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$table}
					WHERE object_type = 'post' AND object_id > %d
					GROUP BY object_id
					HAVING COUNT(DISTINCT object_subtype) > 1
					ORDER BY object_id ASC
					LIMIT %d",
					$last_id,
					self::BATCH_SIZE
				)
			);
			// phpcs:enable

			$ids        = is_array( $ids ) ? $ids : array();
			$batch_size = count( $ids );

			foreach ( $ids as $object_id ) {
				$object_id = (int) $object_id;
				$last_id   = $object_id;
				$post      = get_post( $object_id );

				if ( ! $post ) {
					continue;
				}

				++$collapsed;

				if ( ! $dry_run ) {
					$this->repository->purge_stale_subtypes( 'post', $this->subtypes->resolve( $post ), $object_id );
				}
			}
		} while ( self::BATCH_SIZE === $batch_size );

		return $collapsed;
	}

	/**
	 * Delete XML files with no live chunk behind them.
	 *
	 * Three cases, all of which serve a stale 200 to crawlers: no registry
	 * row at all; a tombstoned row (link_count 0) whose unlink failed, e.g.
	 * storage was unwritable at the time; and a suspended family's leftover,
	 * where suspend_subtype_chunks() nulled generated_at to hide the chunk
	 * from the root index and expected the file gone.
	 *
	 * Names that do not parse are left alone. The directory belongs to this
	 * plugin, but deleting a file it did not write is not this command's
	 * business.
	 *
	 * @param bool               $dry_run Count without deleting.
	 * @param array<int, string> $skipped Skip reasons, appended to by reference.
	 * @return int Files removed.
	 */
	private function clean_files( bool $dry_run, array &$skipped ): int {
		$names = $this->storage->list_files();

		if ( array() === $names ) {
			if ( $this->storage->is_stream_wrapped() ) {
				// An object store that cannot be listed is indistinguishable
				// from an empty directory, so say so rather than reporting a
				// clean scan that never happened.
				$skipped[] = 'Filesystem scan skipped: uploads are stream-wrapped and the sitemap directory returned no listing.';
			}

			return 0;
		}

		$removed = 0;

		foreach ( $names as $name ) {
			$chunk = $this->storage->parse_file_name( $name );

			if ( null === $chunk ) {
				continue;
			}

			$row = $this->files->get_by_subtype_and_number( $chunk['object_subtype'], $chunk['chunk_number'] );

			if ( null !== $row && 0 < (int) $row['link_count'] && ! empty( $row['generated_at'] ) ) {
				continue;
			}

			++$removed;

			if ( ! $dry_run ) {
				$this->storage->delete( $chunk );
			}
		}

		return $removed;
	}
}
