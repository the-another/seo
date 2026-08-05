<?php
/**
 * Sitemap Assignment
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SitemapAssignment
 *
 * Keeps chunk membership true to the indexable table. Membership is stored
 * (the indexable row's sitemap_file_id pointer), never computed from
 * position, so an add/remove only ever touches the one chunk involved —
 * no cascade. Objects are never moved between chunks after assignment.
 *
 * Listens on the module-boundary actions IndexableRepository fires, which
 * means the initial backfill assigns chunks inline with zero extra code:
 * every row it syncs lands here.
 */
class SitemapAssignment {

	/**
	 * Bounded retries for the claim/create race loop. In practice one retry
	 * suffices (a lost race means someone else just made room-accounting
	 * progress); the bound only guards against pathological contention.
	 *
	 * @var int
	 */
	private const CLAIM_RETRIES = 5;

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapStorage        $storage  Storage seam (for immediate unlink at zero links).
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
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'taseo_indexable_synced', array( $this, 'handle_indexable_synced' ), 10, 3 );
		$hook_manager->register_action( 'taseo_indexable_deleting', array( $this, 'handle_indexable_deleting' ), 10, 3 );
	}

	/**
	 * Reconcile chunk membership after a sync write.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return void
	 */
	public function handle_indexable_synced( string $object_type, string $object_subtype, int $object_id ): void {
		if ( ! in_array( $object_type, array( 'post', 'term' ), true ) ) {
			return;
		}

		$row = $this->find_indexable( $object_type, $object_subtype, $object_id );

		if ( null === $row ) {
			return;
		}

		$chunk_id = null !== $row['sitemap_file_id'] ? (int) $row['sitemap_file_id'] : null;

		if ( ! (bool) (int) $row['is_indexable'] ) {
			// Releases are never gated on the enabled toggle: counters must
			// stay true even while sitemap output is switched off.
			if ( null !== $chunk_id ) {
				$this->release( (int) $row['id'], $chunk_id );
			}

			return;
		}

		if ( null === $chunk_id ) {
			// New assignment is the only path gated on the toggle: releases
			// and dirty-marking keep running while output is disabled, so
			// re-enabling self-heals via the accumulated dirty flags.
			if ( $this->settings->is_sitemap_enabled() ) {
				$this->assign( (int) $row['id'], $object_subtype );
			}

			return;
		}

		// Already assigned and staying indexable: an edit. Flag the chunk so
		// the next sweep re-renders it with fresh <loc>/<lastmod> values.
		$this->files->mark_dirty( $chunk_id );
	}

	/**
	 * Release the slot before the indexable row (and its pointer) disappears.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return void
	 */
	public function handle_indexable_deleting( string $object_type, string $object_subtype, int $object_id ): void {
		if ( ! in_array( $object_type, array( 'post', 'term' ), true ) ) {
			return;
		}

		$row = $this->find_indexable( $object_type, $object_subtype, $object_id );

		if ( null === $row || null === $row['sitemap_file_id'] ) {
			return;
		}

		$this->release( (int) $row['id'], (int) $row['sitemap_file_id'] );
	}

	/**
	 * Claim a slot: lowest open chunk first, new chunk as fallback.
	 *
	 * Both the claim and the create can lose a concurrency race (conditional
	 * UPDATE affecting zero rows / unique-key violation); either way the
	 * loop re-runs the search, which now sees the winner's state.
	 *
	 * @param int    $indexable_id   Indexable row ID.
	 * @param string $object_subtype Object subtype.
	 * @return void
	 */
	private function assign( int $indexable_id, string $object_subtype ): void {
		$cap = $this->settings->get_sitemap_max_links();

		for ( $attempt = 0; $attempt < self::CLAIM_RETRIES; $attempt++ ) {
			$chunk = $this->files->find_lowest_open_chunk( $object_subtype, $cap );

			if ( null === $chunk ) {
				$chunk = $this->files->create_chunk( $object_subtype );

				if ( null === $chunk ) {
					continue; // Lost the creation race; search again.
				}

				$this->set_pointer( $indexable_id, (int) $chunk['id'] );

				return;
			}

			if ( $this->files->claim_slot( (int) $chunk['id'], $cap ) ) {
				$this->set_pointer( $indexable_id, (int) $chunk['id'] );

				return;
			}
		}
	}

	/**
	 * Give a slot back; delete the chunk (row + physical file) at zero links.
	 *
	 * @param int $indexable_id Indexable row ID.
	 * @param int $chunk_id     Chunk row ID.
	 * @return void
	 */
	private function release( int $indexable_id, int $chunk_id ): void {
		$this->set_pointer( $indexable_id, null );

		if ( 0 !== $this->files->release_slot( $chunk_id ) ) {
			return;
		}

		$chunk = $this->files->get( $chunk_id );

		if ( null === $chunk ) {
			return;
		}

		// Deletion is a cheap unlink — no need to wait for the sweep. The row
		// delete is conditioned on link_count = 0, so a concurrent assign()
		// that reclaimed this chunk between our zero-link read and now makes
		// delete_chunk() return false; skip the unlink so a live chunk's file
		// is never removed out from under it.
		if ( $this->files->delete_chunk( $chunk_id ) ) {
			$this->storage->delete( $chunk );
		}
	}

	/**
	 * Write (or clear) the stored pointer on the indexable row.
	 *
	 * @param int      $indexable_id Indexable row ID.
	 * @param int|null $chunk_id     Chunk row ID or null to clear.
	 * @return void
	 */
	private function set_pointer( int $indexable_id, ?int $chunk_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			IndexablesTable::get_table_name(),
			array( 'sitemap_file_id' => $chunk_id ),
			array( 'id' => $indexable_id )
		);
	}

	/**
	 * Read the columns reconciliation needs from the indexable row.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	private function find_indexable( string $object_type, string $object_subtype, int $object_id ): ?array {
		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, is_indexable, sitemap_file_id FROM {$table} WHERE object_type = %s AND object_subtype = %s AND object_id = %d",
				$object_type,
				$object_subtype,
				$object_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}
}
