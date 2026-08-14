<?php
/**
 * Rescan Command
 *
 * @package TheAnotherSEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\CLI;

use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use WP_CLI;

/**
 * Class RescanCommand
 *
 * Dispatches the backfill chain that re-syncs every post and term. The work
 * itself is unchanged from the admin button; what this adds is a choice of
 * mode and the option to block until the chain drains.
 *
 * @since 1.1.0
 */
class RescanCommand {

	/**
	 * Accepted --mode values, mapping 1:1 to IndexableBackfill::dispatch().
	 *
	 * @var array<int, string>
	 */
	private const MODES = array( 'full', 'permalink' );

	/**
	 * Constructor.
	 *
	 * @param IndexableBackfill $backfill Backfill.
	 * @param QueueWaiter       $waiter   Shared queue wait loop.
	 */
	public function __construct(
		private readonly IndexableBackfill $backfill,
		private readonly QueueWaiter $waiter
	) {
	}

	/**
	 * Re-index every post and term.
	 *
	 * Re-resolves each object's subtype, so a post that changed subtype (or
	 * whose subtype only became declarable after an integration was
	 * installed) moves to the right sitemap family and its stale row is
	 * dropped.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : Which chain to run.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - permalink
	 * ---
	 *
	 * [--wait]
	 * : Drive the queue and block until the chain drains.
	 *
	 * ## EXAMPLES
	 *
	 *     # Same work the "Rescan everything" button does.
	 *     wp taseo rescan
	 *
	 *     # Also fires taseo_permalinks_rebuilt on completion, which marks
	 *     # every sitemap chunk dirty and re-triggers integrations listening
	 *     # for it. `full` fires nothing on completion.
	 *     wp taseo rescan --mode=permalink --wait
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI invokable signature.
		$mode = isset( $assoc_args['mode'] ) ? (string) $assoc_args['mode'] : 'full';

		if ( ! in_array( $mode, self::MODES, true ) ) {
			WP_CLI::error( sprintf( 'Unknown --mode "%s". Use full or permalink.', $mode ) );
		}

		QueueWaiter::require_action_scheduler(
			function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_enqueue_async_action' )
		);

		if ( as_has_scheduled_action( IndexableBackfill::HOOK, null, IndexableBackfill::GROUP ) ) {
			// dispatch() returns early in this case, so reporting a start
			// would be a lie.
			WP_CLI::warning( 'A backfill chain is already queued; nothing dispatched.' );

			return;
		}

		$this->backfill->dispatch( $mode );

		WP_CLI::success( sprintf( 'Rescan dispatched in %s mode.', $mode ) );

		if ( ! isset( $assoc_args['wait'] ) ) {
			return;
		}

		$this->waiter->wait(
			IndexableBackfill::GROUP,
			fn(): int => (int) $this->backfill->get_progress()['percentage']
		);

		WP_CLI::success( 'Rescan complete.' );
	}
}
