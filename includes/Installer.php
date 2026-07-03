<?php
/**
 * Installer Class
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Database\SitemapFilesTable;

/**
 * Class Installer
 *
 * Activation-time setup. The initial backfill is NOT run here — activation
 * must stay instant. A flag is set; Plugin::start() dispatches the Action
 * Scheduler chain on the next normal request. The rewrite flush is likewise
 * deferred: sitemap rewrite rules only exist once SitemapServer has
 * registered them on a normal request's init.
 */
class Installer {

	/**
	 * Option flag: the initial backfill chain still needs dispatching.
	 *
	 * @var string
	 */
	public const NEEDS_BACKFILL_OPTION = 'taseo_needs_backfill';

	/**
	 * Option flag: rewrite rules need flushing on the next request.
	 *
	 * @var string
	 */
	public const FLUSH_REWRITE_OPTION = 'taseo_needs_rewrite_flush';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		IndexablesTable::create_table();
		SitemapFilesTable::create_table();

		update_option( self::NEEDS_BACKFILL_OPTION, '1' );
		update_option( self::FLUSH_REWRITE_OPTION, '1' );
	}
}
