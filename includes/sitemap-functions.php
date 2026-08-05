<?php
/**
 * Public sitemap push API.
 *
 * Deliberately namespace-free: these functions are the cross-plugin
 * contract and must be callable as plain taseo_sitemap_*() from any
 * plugin or theme. Loaded from the main plugin file (PSR-4 only
 * autoloads classes).
 *
 * @package TheAnotherSEO
 * @since 0.3.0
 */

use TheAnother\Plugin\SEO\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'taseo_sitemap_sync_url' ) ) {
	/**
	 * Insert or update one URL in a registered sitemap family.
	 *
	 * Contract for integrating plugins:
	 * - Register the family first via the `taseo_sitemap_families` filter.
	 * - $id is any stable positive integer, unique within the family
	 *   (0 is reserved).
	 * - The permalink must be absolute and on this site's host.
	 * - Permalinks are provider-owned: if your URLs depend on WordPress
	 *   structures (permalink bases, a store base), listen to
	 *   `taseo_permalinks_rebuilt` and re-push.
	 * - Guard every call with function_exists(): these functions only
	 *   exist while The Another SEO is active.
	 * - Call taseo_sitemap_delete_family() from your deactivation hook.
	 *
	 * @since 0.3.0
	 *
	 * @param string $family Family key.
	 * @param int    $id     Provider-chosen stable identifier, > 0.
	 * @param array  $args   permalink (required), last_modified? (exact
	 *                       GMT 'Y-m-d H:i:s'; a malformed value is
	 *                       ignored rather than rejecting the whole push),
	 *                       images? (absolute URLs, max 50), is_indexable?
	 *                       (default true).
	 * @return bool True when the row was written.
	 */
	function taseo_sitemap_sync_url( string $family, int $id, array $args ): bool {
		return Container::get_instance()->get( 'sitemap_external_urls' )->sync_url( $family, $id, $args );
	}
}

if ( ! function_exists( 'taseo_sitemap_delete_url' ) ) {
	/**
	 * Delete one URL from a family (releases its sitemap slot).
	 *
	 * @since 0.3.0
	 *
	 * @param string $family Family key.
	 * @param int    $id     Provider identifier.
	 * @return void
	 */
	function taseo_sitemap_delete_url( string $family, int $id ): void {
		Container::get_instance()->get( 'sitemap_external_urls' )->delete_url( $family, $id );
	}
}

if ( ! function_exists( 'taseo_sitemap_delete_family' ) ) {
	/**
	 * Bulk-remove a whole family: rows, chunk registry rows, files. For
	 * provider deactivation hooks; does not require the family to still be
	 * registered.
	 *
	 * @since 0.3.0
	 *
	 * @param string $family Family key.
	 * @return void
	 */
	function taseo_sitemap_delete_family( string $family ): void {
		Container::get_instance()->get( 'sitemap_external_urls' )->delete_family( $family );
	}
}
