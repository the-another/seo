<?php
/**
 * Installer Class
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use TheAnother\Plugin\SEO\Database\IndexablesTable;

/**
 * Class Installer
 *
 * Activation-time setup. The initial backfill is NOT run here — activation
 * must stay instant. A flag is set; Plugin::start() dispatches the Action
 * Scheduler chain on the next normal request (Task 7 / Task 15).
 */
class Installer {

	/**
	 * Option flag: the initial backfill chain still needs dispatching.
	 *
	 * @var string
	 */
	public const NEEDS_BACKFILL_OPTION = 'taseo_needs_backfill';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		IndexablesTable::create_table();

		update_option( self::NEEDS_BACKFILL_OPTION, '1' );
	}
}
