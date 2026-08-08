<?php
/**
 * Current Request Context
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;
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
	 * @param IndexableRepository $repository   Repository.
	 * @param Settings            $settings     Settings.
	 * @param CustomPages         $custom_pages Custom page registry.
	 * @param PostSubtypes        $subtypes     Post subtype registry.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings,
		private readonly CustomPages $custom_pages,
		private readonly PostSubtypes $subtypes
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
		$custom_page = $this->resolve_custom_page();

		if ( null !== $custom_page ) {
			return $custom_page;
		}

		if ( is_singular() ) {
			$post = get_queried_object();

			if ( ! $post instanceof WP_Post
				|| ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
				return null;
			}

			return $this->build(
				'post',
				$this->subtypes->resolve( $post ),
				(int) $post->ID,
				$this->post_vars( $post ),
				(string) get_permalink( $post ),
				$post->post_type
			);
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
	 * Context for a plugin-registered custom page claiming this request.
	 *
	 * Applied before every built-in branch, not at the fallthrough. A virtual
	 * page is usually a real WordPress page — WooCommerce's checkout is both
	 * is_checkout() and is_singular() — so a fallthrough filter would never
	 * see it: is_singular() resolves it as post:page first, and when `page`
	 * is not an enabled post type that branch returns null early, so the
	 * fallthrough is never reached either.
	 *
	 * What keeps that override power safe is that claiming a request takes
	 * two deliberate acts in two filters: the subtype must be registered
	 * through taseo_custom_pages as well as declared here. Anything
	 * malformed or unregistered is ignored and resolution continues into the
	 * built-in branches unchanged.
	 *
	 * The filter returns a declaration rather than a context array, so the
	 * shape build() produces stays ours to change.
	 *
	 * @return array<string, mixed>|null Context, or null to continue resolving.
	 */
	private function resolve_custom_page(): ?array {
		/**
		 * Filters in a context for a plugin-registered custom page.
		 *
		 * Return null to leave the request alone, or an array of:
		 *   'subtype'   string, required, must be registered via taseo_custom_pages.
		 *   'vars'      array,  optional, merged over the site-level variables.
		 *   'permalink' string, optional.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed>|null $declaration Declaration, or null.
		 */
		$declaration = apply_filters( 'taseo_custom_page_context', null );

		if ( ! is_array( $declaration ) || ! isset( $declaration['subtype'] ) || ! is_scalar( $declaration['subtype'] ) ) {
			return null;
		}

		$subtype = (string) $declaration['subtype'];

		if ( ! $this->custom_pages->has( $subtype ) ) {
			return null;
		}

		$vars = isset( $declaration['vars'] ) && is_array( $declaration['vars'] )
			? $declaration['vars']
			: array();

		$permalink = isset( $declaration['permalink'] ) ? (string) $declaration['permalink'] : '';

		return $this->build(
			'custom_page',
			$subtype,
			0,
			array_merge( $this->site_vars(), $vars ),
			$permalink
		);
	}

	/**
	 * Assemble the context array shape shared by all consumers.
	 *
	 * @param string                $object_type    Object type.
	 * @param string                $object_subtype Object subtype.
	 * @param int                   $object_id      Object ID.
	 * @param array<string, string> $vars           Template variables.
	 * @param string                $permalink      Live permalink.
	 * @param string                $post_type      Owning post type; '' for non-post contexts.
	 * @return array<string, mixed> Context.
	 */
	private function build( string $object_type, string $object_subtype, int $object_id, array $vars, string $permalink, string $post_type = '' ): array {
		return array(
			'object_type'          => $object_type,
			'object_subtype'       => $object_subtype,
			'object_id'            => $object_id,
			// The subtype no longer implies the post type — a `product` post
			// can resolve to `aucteeno_auction`. Consumers probing for
			// WooCommerce (or any post-type-specific source) must read this
			// rather than infer it from the subtype.
			'post_type'            => $post_type,
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
