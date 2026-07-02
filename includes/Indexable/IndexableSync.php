<?php
/**
 * Indexable Sync
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Indexable;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;

/**
 * Class IndexableSync
 *
 * Keeps wp_taseo_indexables true to wp_posts/wp_terms. Recomputes ONLY the
 * synced columns (permalink, is_indexable, last_modified); override columns
 * belong to the admin and are never written here.
 */
class IndexableSync {

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository         Repository.
	 * @param Settings            $settings           Settings.
	 * @param callable            $rebuild_dispatcher Invoked when a permalink-structure
	 *                                                change requires a full permalink
	 *                                                rebuild (wired to IndexableBackfill
	 *                                                ::dispatch_permalink_rebuild in Task 15).
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings,
		private $rebuild_dispatcher
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'save_post', array( $this, 'handle_save_post' ), 10, 2 );
		$hook_manager->register_action( 'wp_trash_post', array( $this, 'handle_trash_post' ) );
		$hook_manager->register_action( 'before_delete_post', array( $this, 'handle_before_delete_post' ) );
		$hook_manager->register_action( 'created_term', array( $this, 'handle_created_term' ), 10, 3 );
		$hook_manager->register_action( 'edited_term', array( $this, 'handle_edited_term' ), 10, 3 );
		$hook_manager->register_action( 'delete_term', array( $this, 'handle_delete_term' ), 10, 3 );
		$hook_manager->register_action( 'permalink_structure_changed', array( $this, 'handle_permalink_structure_changed' ) );
		$hook_manager->register_action( 'update_option_woocommerce_permalinks', array( $this, 'handle_permalink_structure_changed' ) );
	}

	/**
	 * Handles the save_post action.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function handle_save_post( int $post_id, WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->sync_post( $post_id );
	}

	/**
	 * Recompute and upsert one post's synced fields.
	 *
	 * Also the unit IndexableBackfill drives during backfill/rescan.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function sync_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
			return;
		}

		$is_indexable = 'publish' === $post->post_status && is_post_type_viewable( $post->post_type );

		$this->repository->upsert_synced_fields(
			'post',
			$post->post_type,
			$post_id,
			array(
				'permalink'     => (string) get_permalink( $post_id ),
				'is_indexable'  => $is_indexable,
				'last_modified' => $post->post_modified_gmt,
			)
		);
	}

	/**
	 * Handles trashing a post, keeping the row flagged non-indexable for cheap restores.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_trash_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
			return;
		}

		$this->repository->upsert_synced_fields(
			'post',
			$post->post_type,
			$post_id,
			array(
				'permalink'     => '',
				'is_indexable'  => false,
				'last_modified' => $post->post_modified_gmt,
			)
		);
	}

	/**
	 * Handles permanent deletion by removing the row.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_before_delete_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		$this->repository->delete( 'post', $post->post_type, $post_id );
	}

	/**
	 * Handles the created_term action.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_created_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->sync_term( $term_id, $taxonomy );
	}

	/**
	 * Handles the edited_term action.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->sync_term( $term_id, $taxonomy );
	}

	/**
	 * Recompute and upsert one term's synced fields.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function sync_term( int $term_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, $this->settings->get_enabled_taxonomies(), true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$link = get_term_link( $term, $taxonomy );

		$this->repository->upsert_synced_fields(
			'term',
			$taxonomy,
			$term_id,
			array(
				'permalink'     => is_wp_error( $link ) ? '' : (string) $link,
				'is_indexable'  => is_taxonomy_viewable( $taxonomy ),
				'last_modified' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Handles term deletion by removing the row.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_delete_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->repository->delete( 'term', $taxonomy, $term_id );
	}

	/**
	 * Permalink structure changed — every cached permalink is now suspect.
	 * Dispatch the full rebuild chain (never rebuilt inline; see spec
	 * "Background jobs").
	 *
	 * @return void
	 */
	public function handle_permalink_structure_changed(): void {
		call_user_func( $this->rebuild_dispatcher );
	}
}
