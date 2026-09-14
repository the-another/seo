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
use TheAnother\Plugin\SEO\Settings\Settings;

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
		// Read before create_table() writes it. The schema version is the only
		// honest record of "this site has had the plugin installed"; the
		// settings row is not, because a site can resolve its tracking IDs
		// entirely through taseo_tracking_tag_ids or the per-vendor ID filters
		// and never save settings at all. Keying off the settings row would
		// have turned the gate on for such a site on its next reactivation —
		// silently stopping tracking that had been running, which is the exact
		// thing the false default exists to prevent.
		$fresh_install = false === get_option( IndexablesTable::DB_VERSION_OPTION, false );

		IndexablesTable::create_table();
		SitemapFilesTable::create_table();

		update_option( self::NEEDS_BACKFILL_OPTION, '1' );
		update_option( self::FLUSH_REWRITE_OPTION, '1' );

		// A fresh install has no established tracking behaviour to preserve,
		// so it starts gated. An existing site keeps the stored option and
		// therefore the false default, and an administrator turns consent on
		// deliberately from the Consent tab.
		if ( $fresh_install ) {
			$settings = get_option( Settings::OPTION_NAME, array() );
			$settings = is_array( $settings ) ? $settings : array();

			$settings['consent_enabled'] = true;

			update_option( Settings::OPTION_NAME, $settings );
		}
	}
}
