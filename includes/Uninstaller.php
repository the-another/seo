<?php
/**
 * Uninstaller Class
 *
 * @package TheAnotherSEO
 * @since 1.4.0
 */

namespace TheAnother\Plugin\SEO;

use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Database\SitemapFilesTable;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Verification\MethodMigration;

/**
 * Class Uninstaller
 *
 * Deletion-time teardown, the inverse of Installer::activate(). Deleting the
 * plugin removes everything it ever wrote: both tables, every option, and the
 * sitemap chunk directory under uploads/.
 *
 * Deactivation is the reversible half of the lifecycle — it unschedules the
 * recurring Action Scheduler jobs and flushes the rewrite rules but keeps all
 * data, so deactivate/reactivate costs nothing. Deletion is the destructive
 * half, and nothing here is unrecoverable: a reinstall's activation recreates
 * the tables and sets taseo_needs_backfill, and the Action Scheduler chain
 * rebuilds the indexables and chunk files from the site's own content.
 *
 * Pending one-off Action Scheduler actions in the 'taseo' group are left
 * alone, deliberately. The recurring ones are already gone (WordPress always
 * deactivates before deleting, and the deactivation hook unschedules them),
 * and the stragglers self-heal: with no listener left, each fires as a no-op,
 * completes, and is removed by Action Scheduler's own retention sweep. The
 * alternative — hand-written DELETEs against a bundled library's schema, from
 * a context where that library is not even loaded — trades a self-correcting
 * leftover for a brittle one.
 *
 * @since 1.4.0
 */
class Uninstaller {

	/**
	 * Every option the plugin writes, except the two schema-version options
	 * each table class owns and deletes in its own drop_table().
	 *
	 * UninstallerTest scans includes/ for option constants and fails when one
	 * is missing from here, so a new option cannot quietly start surviving
	 * deletion.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	public const OPTIONS = array(
		Settings::OPTION_NAME,
		Installer::NEEDS_BACKFILL_OPTION,
		Installer::FLUSH_REWRITE_OPTION,
		IndexableBackfill::PROGRESS_OPTION,
		MethodMigration::VERSION_OPTION,
		MethodMigration::NOTICE_OPTION,
	);

	/**
	 * Run the teardown across the whole install.
	 *
	 * WordPress includes uninstall.php exactly once, but the tables are
	 * $wpdb->prefix-scoped and the uploads directory is per-blog, so on
	 * multisite a plugin activated site-by-site has one set of each per site.
	 * Switching through every blog is the only way a single run reaches them
	 * all.
	 *
	 * @since 1.4.0
	 * @param SitemapStorage|null $storage Storage to tear down through;
	 *                                     defaults to a plain instance.
	 * @return void
	 */
	public static function uninstall( ?SitemapStorage $storage = null ): void {
		$storage ??= new SitemapStorage();

		if ( ! is_multisite() ) {
			self::uninstall_site( $storage );

			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site( $storage );
			restore_current_blog();
		}
	}

	/**
	 * Remove every artifact belonging to the current site.
	 *
	 * Order is load-bearing. The chunk files go first because they are the one
	 * artifact that keeps *serving* after deletion: the rewrite rule and the
	 * template_redirect handler leave with the plugin, but the physical XML
	 * stays fetchable at its own uploads path, and anything that recorded
	 * those direct URLs would keep being handed stale sitemaps by the
	 * webserver with no plugin involved. The tables and options are merely
	 * invisible clutter, so they can wait; a filesystem failure must not be
	 * what leaves the database dirty too.
	 *
	 * @since 1.4.0
	 * @param SitemapStorage|null $storage Storage to tear down through;
	 *                                     defaults to a plain instance.
	 * @return void
	 */
	public static function uninstall_site( ?SitemapStorage $storage = null ): void {
		$storage ??= new SitemapStorage();

		// Best effort: an unwritable or already-gone uploads directory is not
		// a reason to leave the database behind.
		$storage->delete_directory();

		IndexablesTable::drop_table();
		SitemapFilesTable::drop_table();

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
