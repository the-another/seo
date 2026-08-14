<?php
/**
 * Queue Waiter
 *
 * @package TheAnotherSEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\CLI;

use WP_CLI;

/**
 * Class QueueWaiter
 *
 * Drives an Action Scheduler group to empty while reporting progress. Both
 * --wait commands share this, so the loop exists once.
 *
 * Execution is delegated to Action Scheduler's own WP-CLI runner rather than
 * reimplemented: claim logic, batch sizing and memory guards belong to it and
 * are already tested there. The runner and reporter are injectable so the
 * loop can be unit-tested without WP-CLI in the process.
 *
 * @since 1.1.0
 */
class QueueWaiter {

	/**
	 * Runs one batch for a group.
	 *
	 * @var callable|null
	 */
	private $runner;

	/**
	 * Reports progress: ( int $percent, bool $done ).
	 *
	 * @var callable|null
	 */
	private $reporter;

	/**
	 * Constructor.
	 *
	 * @param callable|null $runner   Batch runner; null uses Action Scheduler's WP-CLI command.
	 * @param callable|null $reporter Progress reporter; null uses a WP-CLI progress bar.
	 */
	public function __construct( ?callable $runner = null, ?callable $reporter = null ) {
		$this->runner   = $runner;
		$this->reporter = $reporter;
	}

	/**
	 * Block until the group holds no pending actions.
	 *
	 * @param string   $group    Action Scheduler group.
	 * @param callable $progress Returns completion percentage, 0-100.
	 * @return void
	 */
	public function wait( string $group, callable $progress ): void {
		$runner   = $this->runner ?? $this->default_runner();
		$reporter = $this->reporter ?? $this->default_reporter();

		while ( $this->has_pending( $group ) ) {
			call_user_func( $runner, $group );
			call_user_func( $reporter, (int) call_user_func( $progress ), false );
		}

		call_user_func( $reporter, (int) call_user_func( $progress ), true );
	}

	/**
	 * Whether the group still holds pending actions.
	 *
	 * @param string $group Action Scheduler group.
	 * @return bool True when work remains.
	 */
	private function has_pending( string $group ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);

		return is_array( $pending ) && array() !== $pending;
	}

	/**
	 * Delegate one batch to Action Scheduler's own command, in-process.
	 *
	 * @return callable Runner.
	 */
	private function default_runner(): callable {
		return static function ( string $group ): void {
			WP_CLI::runcommand(
				'action-scheduler run --group=' . $group,
				array(
					'launch'     => false,
					'return'     => 'all',
					'exit_error' => false,
				)
			);
		};
	}

	/**
	 * Stop before a dispatch that cannot work.
	 *
	 * Static and shared: both dispatching commands need it, and they fail
	 * differently without it. IndexableBackfill::dispatch() calls
	 * as_has_scheduled_action() with no function_exists() guard of its own,
	 * so `rescan` fatals. SitemapSweeper::dispatch_full_regeneration() *is*
	 * guarded, which is worse — `regenerate` would mark every chunk dirty,
	 * enqueue nothing, and report success.
	 *
	 * Takes the answer rather than probing, because the two commands need
	 * different functions present.
	 *
	 * @param bool $available Whether the functions this caller needs exist.
	 * @return void
	 */
	public static function require_action_scheduler( bool $available ): void {
		if ( $available ) {
			return;
		}

		WP_CLI::error( 'Action Scheduler is unavailable, so no background chain can be queued. Activate WooCommerce, or any plugin bundling Action Scheduler, and try again.' );
	}

	/**
	 * Progress bar over the 0-100 percentage.
	 *
	 * @return callable Reporter.
	 */
	private function default_reporter(): callable {
		$bar  = \WP_CLI\Utils\make_progress_bar( 'Draining queue', 100 );
		$seen = 0;

		return static function ( int $percent, bool $done ) use ( $bar, &$seen ): void {
			$percent = max( 0, min( 100, $percent ) );

			if ( $percent > $seen ) {
				$bar->tick( $percent - $seen );
				$seen = $percent;
			}

			if ( $done ) {
				$bar->finish();
			}
		};
	}
}
