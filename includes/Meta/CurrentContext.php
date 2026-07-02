<?php
/**
 * Current Request Context
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;
use WP_Term;

/**
 * Class CurrentContext
 *
 * Resolves the current main query into the one array every output class
 * consumes: object identity, its indexable row (overrides), template
 * variables, live permalink, and the applicable templates. Memoized —
 * MetaOutput, SocialOutput, and SchemaOutput all share a single resolution
 * per request.
 */
class CurrentContext {

	/**
	 * Memoized resolution (false = not yet resolved; null = unmanaged request).
	 *
	 * @var array<string, mixed>|null|false
	 */
	private array|null|false $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository Repository.
	 * @param Settings            $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Resolve the current request.
	 *
	 * @return array<string, mixed>|null Context array, or null if unmanaged.
	 */
	public function resolve(): ?array {
		if ( false !== $this->resolved ) {
			return $this->resolved;
		}

		$this->resolved = $this->do_resolve();

		return $this->resolved;
	}

	/**
	 * Uncached resolution.
	 *
	 * @return array<string, mixed>|null Context array, or null if unmanaged.
	 */
	private function do_resolve(): ?array {
		if ( is_singular() ) {
			$post = get_queried_object();

			if ( ! $post instanceof WP_Post
				|| ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
				return null;
			}

			return $this->build( 'post', $post->post_type, (int) $post->ID, $this->post_vars( $post ), (string) get_permalink( $post ) );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			if ( ! $term instanceof WP_Term
				|| ! in_array( $term->taxonomy, $this->settings->get_enabled_taxonomies(), true ) ) {
				return null;
			}

			$link = get_term_link( $term );

			return $this->build( 'term', $term->taxonomy, (int) $term->term_id, $this->term_vars( $term ), is_wp_error( $link ) ? '' : (string) $link );
		}

		if ( is_front_page() || is_home() ) {
			$permalink = (string) home_url( '/' );

			// Static-front-page + posts-page setup: is_home() is the blog page
			// (e.g. /blog/), which must not canonicalize to the site root.
			if ( is_home() && ! is_front_page() ) {
				$page_for_posts = (int) get_option( 'page_for_posts' );

				if ( $page_for_posts > 0 ) {
					$permalink = (string) get_permalink( $page_for_posts );
				}
			}

			return $this->build( 'system_page', 'home', 0, $this->site_vars(), $permalink );
		}

		if ( is_search() ) {
			return $this->build( 'system_page', 'search', 0, array_merge( $this->site_vars(), array( 'title' => (string) get_search_query() ) ), '' );
		}

		if ( is_404() ) {
			return $this->build( 'system_page', '404', 0, $this->site_vars(), '' );
		}

		if ( is_post_type_archive() ) {
			$post_type = (string) get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : $post_type;

			$archive_link = get_post_type_archive_link( $post_type );

			return $this->build(
				'system_page',
				'archive:' . $post_type,
				0,
				array_merge( $this->site_vars(), array( 'title' => (string) post_type_archive_title( '', false ) ) ),
				is_string( $archive_link ) ? $archive_link : ''
			);
		}

		return null;
	}

	/**
	 * Assemble the context array shape shared by all consumers.
	 *
	 * @param string                $object_type    Object type.
	 * @param string                $object_subtype Object subtype.
	 * @param int                   $object_id      Object ID.
	 * @param array<string, string> $vars           Template variables.
	 * @param string                $permalink      Live permalink.
	 * @return array<string, mixed> Context.
	 */
	private function build( string $object_type, string $object_subtype, int $object_id, array $vars, string $permalink ): array {
		return array(
			'object_type'          => $object_type,
			'object_subtype'       => $object_subtype,
			'object_id'            => $object_id,
			'row'                  => $this->repository->find( $object_type, $object_subtype, $object_id ),
			'vars'                 => $vars,
			'permalink'            => $permalink,
			'title_template'       => $this->settings->get_title_template( $object_type, $object_subtype ),
			'description_template' => $this->settings->get_description_template( $object_type, $object_subtype ),
		);
	}

	/**
	 * Site-level variables present in every context.
	 *
	 * @return array<string, string> Variables.
	 */
	private function site_vars(): array {
		return array(
			'title'    => (string) get_bloginfo( 'name' ),
			'sitename' => (string) get_bloginfo( 'name' ),
			'tagline'  => (string) get_bloginfo( 'description' ),
			'sep'      => $this->settings->get_separator(),
			'page'     => (string) ( max( 1, (int) get_query_var( 'paged' ) ) > 1 ? 'Page ' . (int) get_query_var( 'paged' ) : '' ),
		);
	}

	/**
	 * Variables for a post context.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, string> Variables.
	 */
	private function post_vars( WP_Post $post ): array {
		$vars            = $this->site_vars();
		$vars['title']   = (string) get_the_title( $post );
		$vars['excerpt'] = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		$vars['date']    = (string) get_the_date( '', $post );

		$taxonomy = 'product' === $post->post_type ? 'product_cat' : 'category';
		$terms    = get_the_terms( $post, $taxonomy );

		if ( is_array( $terms ) && array() !== $terms ) {
			$vars['primary_category'] = (string) $terms[0]->name;
		}

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$vars['price'] = (string) $product->get_price();
				$vars['sku']   = (string) $product->get_sku();
			}
		}

		return $vars;
	}

	/**
	 * Variables for a term context.
	 *
	 * @param WP_Term $term Term.
	 * @return array<string, string> Variables.
	 */
	private function term_vars( WP_Term $term ): array {
		$vars            = $this->site_vars();
		$vars['title']   = (string) $term->name;
		$vars['excerpt'] = wp_strip_all_tags( (string) $term->description );

		return $vars;
	}
}
