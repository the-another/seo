<?php
/**
 * Indexable Backfill
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Indexable;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class IndexableBackfill
 *
 * Mass (re)indexing as a self-chaining Action Scheduler job series. One job
 * processes one bounded ID-range slice and enqueues the next; no single
 * request ever processes the whole catalog. Used for: initial backfill on
 * activation, admin "Rescan everything", and permalink-structure rebuilds.
 */
class IndexableBackfill {

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	public const HOOK = 'taseo_backfill_batch';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	public const GROUP = 'taseo';

	/**
	 * Rows per job.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Progress option name.
	 *
	 * @var string
	 */
	public const PROGRESS_OPTION = 'taseo_backfill_progress';

	/**
	 * Constructor.
	 *
	 * @param IndexableSync $sync     Sync (provides the per-object recompute unit).
	 * @param Settings      $settings Settings.
	 */
	public function __construct(
		private readonly IndexableSync $sync,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register the Action Scheduler callback.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( self::HOOK, array( $this, 'handle_batch_action' ) );
	}

	/**
	 * Action Scheduler entry point.
	 *
	 * @param mixed $args Action args (array with 'mode', or the mode string).
	 * @return void
	 */
	public function handle_batch_action( $args = array() ): void {
		$mode = is_array( $args ) ? ( $args['mode'] ?? 'full' ) : (string) $args;

		$this->process_batch( $mode );
	}

	/**
	 * Start (or restart) a chain. No-op if one is already queued.
	 *
	 * @param string $mode 'full' or 'permalink'.
	 * @return void
	 */
	public function dispatch( string $mode = 'full' ): void {
		if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return;
		}

		update_option(
			self::PROGRESS_OPTION,
			array(
				'phase'   => 'posts',
				'last_id' => 0,
				'mode'    => $mode,
			)
		);

		as_enqueue_async_action( self::HOOK, array( 'mode' => $mode ), self::GROUP );
	}

	/**
	 * Process one slice, then re-enqueue or finish.
	 *
	 * @param string $mode 'full' or 'permalink'.
	 * @return void
	 */
	public function process_batch( string $mode ): void {
		$progress = get_option( self::PROGRESS_OPTION, false );

		if ( ! is_array( $progress ) ) {
			return;
		}

		if ( 'posts' === $progress['phase'] ) {
			$ids = $this->next_post_ids( (int) $progress['last_id'] );

			foreach ( $ids as $post_id ) {
				$this->sync->sync_post( (int) $post_id );
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				$progress['phase']   = 'terms';
				$progress['last_id'] = 0;
			} else {
				$progress['last_id'] = (int) max( $ids );
			}

			update_option( self::PROGRESS_OPTION, $progress );
			as_enqueue_async_action( self::HOOK, array( 'mode' => $mode ), self::GROUP );

			return;
		}

		// Terms phase.
		$rows = $this->next_term_rows( (int) $progress['last_id'] );

		foreach ( $rows as $row ) {
			$this->sync->sync_term( (int) $row->term_id, (string) $row->taxonomy );
		}

		if ( count( $rows ) < self::BATCH_SIZE ) {
			delete_option( self::PROGRESS_OPTION );

			if ( 'permalink' === $mode ) {
				/**
				 * Fires when a permalink rebuild chain has refreshed every row.
				 * The sitemap module marks all chunk files dirty on this.
				 *
				 * @since 1.0.0
				 */
				do_action( 'taseo_permalinks_rebuilt' );
			}

			return;
		}

		$last_row            = end( $rows );
		$progress['last_id'] = (int) $last_row->term_id;
		update_option( self::PROGRESS_OPTION, $progress );
		as_enqueue_async_action( self::HOOK, array( 'mode' => $mode ), self::GROUP );
	}

	/**
	 * Next slice of post IDs by ID range (never OFFSET pagination).
	 *
	 * @param int $last_id Last processed post ID.
	 * @return array<int, string> Post IDs.
	 */
	private function next_post_ids( int $last_id ): array {
		global $wpdb;

		$types = $this->settings->get_enabled_post_types();

		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d AND post_type IN ({$placeholders}) AND post_status NOT IN ('auto-draft', 'inherit')
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( array( $last_id ), $types, array( self::BATCH_SIZE ) )
			)
		);
		// phpcs:enable

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Next slice of term rows by ID range.
	 *
	 * @param int $last_id Last processed term ID.
	 * @return array<int, object> Rows with term_id + taxonomy.
	 */
	private function next_term_rows( int $last_id ): array {
		global $wpdb;

		$taxonomies = $this->settings->get_enabled_taxonomies();

		if ( array() === $taxonomies ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE t.term_id > %d AND tt.taxonomy IN ({$placeholders})
				ORDER BY t.term_id ASC
				LIMIT %d",
				array_merge( array( $last_id ), $taxonomies, array( self::BATCH_SIZE ) )
			)
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Progress for the settings-screen indicator.
	 *
	 * @return array{phase: string, total: int, processed: int, percentage: float} Progress.
	 */
	public function get_progress(): array {
		global $wpdb;

		$progress = get_option( self::PROGRESS_OPTION, false );

		if ( ! is_array( $progress ) ) {
			return array(
				'phase'      => 'idle',
				'total'      => 0,
				'processed'  => 0,
				'percentage' => 100.0,
			);
		}

		$types        = $this->settings->get_enabled_post_types();
		$placeholders = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders})",
				$types
			)
		);
		$done  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND ID <= %d",
				array_merge( $types, array( (int) $progress['last_id'] ) )
			)
		);
		// phpcs:enable

		return array(
			'phase'      => (string) $progress['phase'],
			'total'      => $total,
			'processed'  => 'terms' === $progress['phase'] ? $total : $done,
			'percentage' => $total > 0 ? round( ( ( 'terms' === $progress['phase'] ? $total : $done ) / $total ) * 100, 2 ) : 100.0,
		);
	}
}
