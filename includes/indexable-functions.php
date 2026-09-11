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
	 * - For deletions, call taseo_delete_post_indexable() before the row
	 *   goes away. wp_delete_post() fires `before_delete_post`, which the
	 *   plugin already listens to, but a direct $wpdb delete does not.
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

if ( ! function_exists( 'taseo_delete_post_indexable' ) ) {
	/**
	 * Remove a post's indexable row and release its sitemap slot.
	 *
	 * For plugins that delete posts with raw SQL to skip the expensive
	 * WordPress/WooCommerce delete hooks — a bulk importer retiring expired
	 * listings, for instance. `before_delete_post` does not fire for those,
	 * so this plugin never learns the post is gone: the sitemap keeps
	 * publishing a URL that now 404s, and the chunk slot is never given back,
	 * so the chunk can never drain to zero and retire.
	 *
	 * Contract:
	 * - Call it while the post row is still readable, before your DELETE.
	 *   Afterwards the subtype cannot be resolved and the call is a no-op.
	 * - Guard it with function_exists(): it only exists while The Another SEO
	 *   is active.
	 * - It removes one row and one slot. Retiring a whole catalogue belongs
	 *   in a bounded job chain, not a loop.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Post ID, still present in wp_posts.
	 * @return void
	 */
	function taseo_delete_post_indexable( int $post_id ): void {
		Container::get_instance()->get( 'indexable_sync' )->delete_post( $post_id );
	}
}
