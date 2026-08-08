<?php
/**
 * Public indexable sync API.
 *
 * Deliberately namespace-free: these functions are the cross-plugin
 * contract and must be callable as plain taseo_*() from any plugin or
 * theme. Loaded from the main plugin file (PSR-4 only autoloads classes).
 *
 * @package TheAnotherSEO
 * @since 0.4.0
 */

use TheAnother\Plugin\SEO\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'taseo_sync_post' ) ) {
	/**
	 * Recompute one post's indexable row.
	 *
	 * The plugin keeps itself current through `save_post`. Integrations that
	 * write posts with direct $wpdb queries — the usual reason being to
	 * bypass cache invalidation on a high-volume import — never fire it, so
	 * their writes are invisible until the next full backfill. Calling this
	 * after such a write closes that gap.
	 *
	 * Contract for integrating plugins:
	 * - Guard every call with function_exists(): this function only exists
	 *   while The Another SEO is active.
	 * - Call it AFTER the post row and its meta are committed; resolution
	 *   reads both.
	 * - Deletions need no equivalent: wp_delete_post() fires
	 *   `before_delete_post`, which the plugin already listens to.
	 * - This runs the same code path as the `save_post` handler by design —
	 *   a pushed sync and an ordinary edit must not be able to diverge.
	 * - It writes one row synchronously. On a bulk import, call it only for
	 *   changes that can move SEO output (title, slug, status, images), not
	 *   for every field touched.
	 *
	 * @since 0.4.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	function taseo_sync_post( int $post_id ): void {
		Container::get_instance()->get( 'indexable_sync' )->sync_post( $post_id );
	}
}
