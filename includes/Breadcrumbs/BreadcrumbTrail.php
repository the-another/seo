<?php
/**
 * Breadcrumb Trail Builder
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Breadcrumbs;

use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;

/**
 * Class BreadcrumbTrail
 *
 * Builds the one trail array consumed by the HTML renderer, the shortcode,
 * the block, AND SchemaGraph's BreadcrumbList — the single source of truth
 * that keeps visible breadcrumbs and structured data identical.
 */
class BreadcrumbTrail {

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository Repository (breadcrumb_title overrides).
	 * @param Settings            $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Build the trail for the current request.
	 *
	 * @return array<int, array{title: string, url: string}> Trail, home first.
	 */
	public function build(): array {
		$trail = array(
			array(
				'title' => $this->settings->get_breadcrumb_home_label(),
				'url'   => (string) home_url( '/' ),
			),
		);

		if ( ! is_singular() ) {
			return $trail;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return $trail;
		}

		// Post type archive.
		$archive_link = get_post_type_archive_link( $post->post_type );

		if ( is_string( $archive_link ) && '' !== $archive_link ) {
			$post_type_object = get_post_type_object( $post->post_type );

			$trail[] = array(
				'title' => $post_type_object ? (string) $post_type_object->labels->name : $post->post_type,
				'url'   => $archive_link,
			);
		}

		// Taxonomy term ancestors (primary term's lineage).
		if ( $this->settings->breadcrumb_include_taxonomy_ancestors() ) {
			foreach ( $this->term_lineage( $post ) as $crumb ) {
				$trail[] = $crumb;
			}
		}

		// Parent page ancestors (root first).
		foreach ( array_reverse( get_ancestors( $post->ID, $post->post_type ) ) as $ancestor_id ) {
			$trail[] = array(
				'title' => (string) get_the_title( $ancestor_id ),
				'url'   => (string) get_permalink( $ancestor_id ),
			);
		}

		// Current object, with breadcrumb_title override.
		$row   = $this->repository->find_for_post( (int) $post->ID );
		$title = ! empty( $row['breadcrumb_title'] ) ? (string) $row['breadcrumb_title'] : (string) get_the_title( $post );

		$trail[] = array(
			'title' => $title,
			'url'   => (string) get_permalink( $post ),
		);

		return $trail;
	}

	/**
	 * The primary term's ancestor chain (root first), then the term itself.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{title: string, url: string}> Crumbs.
	 */
	private function term_lineage( WP_Post $post ): array {
		$taxonomy = 'product' === $post->post_type ? 'product_cat' : 'category';
		$terms    = get_the_terms( $post, $taxonomy );

		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}

		$term    = $terms[0];
		$lineage = array();

		// Walk up via parent pointers.
		$cursor = $term;

		while ( $cursor ) {
			$link = get_term_link( $cursor );

			if ( ! is_wp_error( $link ) ) {
				array_unshift(
					$lineage,
					array(
						'title' => (string) $cursor->name,
						'url'   => (string) $link,
					)
				);
			}

			$cursor = $cursor->parent ? get_term( $cursor->parent, $taxonomy ) : null;

			if ( is_wp_error( $cursor ) ) {
				$cursor = null;
			}
		}

		return $lineage;
	}
}
