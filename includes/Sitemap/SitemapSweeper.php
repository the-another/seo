<?php
/**
 * Sitemap Sweeper
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SitemapSweeper
 *
 * The asynchronous half of dirty-flag regeneration: a recurring Action
 * Scheduler action rebuilds a bounded batch of dirty chunks per run, and
 * self-chains an immediate follow-up while a backlog remains (e.g. after a
 * permalink rebuild marks every chunk dirty) — the backlog drains as a chain
 * of short jobs instead of waiting one recurring tick per batch.
 *
 * Concurrent sweeps racing on the same chunk are harmless: rebuild always
 * renders current DB state, so a race is a redundant write, not corruption.
 */
class SitemapSweeper {

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	public const HOOK = 'taseo_sitemap_sweep';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	public const GROUP = 'taseo';

	/**
	 * Dirty chunks rebuilt per run — bounds execution time per job
	 * regardless of how much churn happened.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 20;

	/**
	 * Recurring interval in seconds.
	 *
	 * @var int
	 */
	public const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapFileWriter     $writer   File writer.
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapFileWriter $writer,
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
		$hook_manager->register_action( self::HOOK, array( $this, 'handle_sweep' ) );
		$hook_manager->register_action( 'init', array( $this, 'ensure_recurring' ), 20 );

		// The one legitimate "everything regenerates" event (spec: "Error
		// handling"): module 1 fires this after re-caching every permalink.
		$hook_manager->register_action( 'taseo_permalinks_rebuilt', array( $this, 'dispatch_full_regeneration' ) );
	}

	/**
	 * Keep the recurring sweep scheduled.
	 *
	 * Runs on every 'init', which includes every frontend page view —
	 * as_has_scheduled_action() is a real SQL query, so the bulk of traffic
	 * must never reach it. Re-arming (or tearing down) from an admin or cron
	 * request is sufficient; frontend requests pay nothing.
	 *
	 * @return void
	 */
	public function ensure_recurring(): void {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! $this->settings->is_sitemap_enabled() ) {
			// The feature was toggled off: stop the recurring action instead
			// of merely skipping re-arming, or it keeps firing forever.
			if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
				as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			}

			return;
		}

		if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), self::GROUP );
	}

	/**
	 * Action Scheduler entry point: rebuild one bounded batch.
	 *
	 * @return void
	 */
	public function handle_sweep(): void {
		if ( ! $this->settings->is_sitemap_enabled() ) {
			return;
		}

		if ( ! $this->writer->is_writable() ) {
			// Environment problem (surfaced as an admin notice by the
			// settings page) — bail without fataling or partially writing.
			return;
		}

		$dirty   = $this->files->get_dirty_chunks( self::BATCH_SIZE );
		$rebuilt = 0;

		foreach ( $dirty as $chunk ) {
			if ( $this->writer->rebuild( $chunk ) ) {
				++$rebuilt;
			}
		}

		// Chain immediately only on a full, fully-successful batch with
		// backlog left. Failed rebuilds keep their dirty flag and wait for
		// the recurring tick — chaining on failure would spin a hot loop
		// against a broken filesystem.
		if ( self::BATCH_SIZE === count( $dirty ) && self::BATCH_SIZE === $rebuilt && 0 < $this->files->count_dirty() ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Mark every chunk dirty and start draining right away.
	 *
	 * Used by the taseo_permalinks_rebuilt listener and the admin
	 * "Regenerate now" action.
	 *
	 * @return void
	 */
	public function dispatch_full_regeneration(): void {
		$this->files->mark_all_dirty();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		}
	}
}
