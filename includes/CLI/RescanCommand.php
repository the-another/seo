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

		// get_flag_value(), not isset(): --no-wait sets the key to false, and
		// isset() would read that as a request to wait.
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'wait', false ) ) {
			return;
		}

		$this->waiter->wait(
			IndexableBackfill::GROUP,
			fn(): int => (int) $this->backfill->get_progress()['percentage']
		);

		// The queue going quiet is not the work finishing. A failed backfill
		// action ends the chain with progress short, and wait() returns as
		// soon as nothing is due — so the only honest completion signal is
		// the progress figure itself.
		$percentage = (int) $this->backfill->get_progress()['percentage'];

		if ( $percentage < 100 ) {
			WP_CLI::warning(
				sprintf(
					'The queue drained at %d%%: the chain stopped before finishing, most likely on a failed action. Check the Action Scheduler log and run again.',
					$percentage
				)
			);

			return;
		}

		WP_CLI::success( 'Rescan complete.' );
	}
}
