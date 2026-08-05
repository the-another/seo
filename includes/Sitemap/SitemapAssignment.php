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
	 * Object types with sitemap membership. system_page is excluded on
	 * purpose — the built-in system pages do not belong in a sitemap.
	 *
	 * @var array<int, string>
	 */
	private const SITEMAP_TYPES = array( 'post', 'term', 'custom_page' );

	/**
	 * Action Scheduler hook for the family reconciliation chain.
	 *
	 * @var string
	 */
	public const ASSIGN_FAMILY_HOOK = 'taseo_sitemap_assign_family';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	public const GROUP = 'taseo';

	/**
	 * Rows assigned per reconciliation job — bounds execution time.
	 *
	 * @var int
	 */
	public const ASSIGN_BATCH_SIZE = 200;

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
		$hook_manager->register_action( self::ASSIGN_FAMILY_HOOK, array( $this, 'handle_assign_family_action' ) );
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
		if ( ! in_array( $object_type, self::SITEMAP_TYPES, true ) ) {
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
			// New assignment is the only path gated on the toggles: releases
			// and dirty-marking keep running while output is disabled, so
			// re-enabling self-heals via the accumulated dirty flags.
			if ( $this->settings->is_sitemap_enabled() && $this->is_family_allowed( $object_type, $object_subtype ) ) {
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
		if ( ! in_array( $object_type, self::SITEMAP_TYPES, true ) ) {
			return;
		}

		$row = $this->find_indexable( $object_type, $object_subtype, $object_id );

		if ( null === $row || null === $row['sitemap_file_id'] ) {
			return;
		}

		$this->release( (int) $row['id'], (int) $row['sitemap_file_id'] );
	}

	/**
	 * Family-toggle gate. Only custom_page subtypes are families; post and
	 * term subtypes are never toggleable here.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return bool Assignment allowed.
	 */
	private function is_family_allowed( string $object_type, string $object_subtype ): bool {
		return 'custom_page' !== $object_type || $this->settings->is_sitemap_family_enabled( $object_subtype );
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

	/**
	 * Family toggled off: remove its files from circulation without touching
	 * membership. generated_at = NULL hides the chunks from the root index
	 * (the guard that hides never-swept chunks); missing files 404 the chunk
	 * URLs on both serving paths. is_dirty = 1 queues the rebuild for
	 * re-enable, while the sweep skips disabled families meanwhile. Bounded
	 * by the family's chunk count, never its URL count.
	 *
	 * @param string $family Family key.
	 * @return void
	 */
	public function handle_family_disabled( string $family ): void {
		foreach ( $this->files->get_chunks_for_subtype( $family ) as $chunk ) {
			$this->storage->delete( $chunk );
		}

		$this->files->suspend_subtype_chunks( $family );
	}

	/**
	 * Family toggled on: drain the (already dirty) chunks now, and assign
	 * any rows pushed while the family was off. The reconciliation job is
	 * required for correctness, not polish — assignment normally fires only
	 * on sync events, so a low-churn family pushed once would otherwise
	 * stay unassigned forever.
	 *
	 * @param string $family Family key.
	 * @return void
	 */
	public function handle_family_enabled( string $family ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action( SitemapSweeper::HOOK, array(), self::GROUP );
		as_enqueue_async_action( self::ASSIGN_FAMILY_HOOK, array( 'family' => $family ), self::GROUP );
	}

	/**
	 * Action Scheduler entry point (named-arg binding, like
	 * IndexableBackfill::handle_batch_action).
	 *
	 * @param mixed $family Family key (named-arg binding), or legacy array args.
	 * @return void
	 */
	public function handle_assign_family_action( $family = '' ): void {
		if ( is_array( $family ) ) {
			$family = $family['family'] ?? '';
		}

		$this->assign_family_batch( (string) $family );
	}

	/**
	 * Assign one bounded batch of unassigned family rows, then self-chain
	 * while a full batch was found (the SitemapSweeper::handle_sweep
	 * pattern). No-ops if the family or the feature was disabled meanwhile.
	 *
	 * @param string $family Family key.
	 * @return void
	 */
	public function assign_family_batch( string $family ): void {
		if ( '' === $family || ! $this->settings->is_sitemap_enabled() || ! $this->settings->is_sitemap_family_enabled( $family ) ) {
			return;
		}

		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE object_type = 'custom_page' AND object_subtype = %s AND is_indexable = 1 AND sitemap_file_id IS NULL
				ORDER BY id ASC
				LIMIT %d",
				$family,
				self::ASSIGN_BATCH_SIZE
			),
			ARRAY_A
		);
		// phpcs:enable

		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			$this->assign( (int) $row['id'], $family );
		}

		if ( self::ASSIGN_BATCH_SIZE === count( $rows ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ASSIGN_FAMILY_HOOK, array( 'family' => $family ), self::GROUP );
		}
	}
}
