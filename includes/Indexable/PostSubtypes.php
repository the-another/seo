<?php
/**
 * Post Subtype Registry
 *
 * @package TheAnotherSEO
 * @since 0.4.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Indexable;

use WP_Post;

/**
 * Class PostSubtypes
 *
 * Splits one post type into several SEO subtypes. A marketplace stores
 * auctions, catalogue items, and ordinary merchandise in the same `product`
 * post type, separated only by a meta field — to this plugin they are one
 * bucket with one set of templates, one schema type, and one sitemap family.
 * Registering subtypes gives each its own.
 *
 * Both the settings screen and the sync pipeline read the list through this
 * class rather than each calling apply_filters() themselves, matching
 * SitemapFamilies and CustomPages: two call sites would be two places for
 * key sanitization to drift, and a post synced under one key while the
 * settings screen offers another produces a row whose templates never render.
 */
class PostSubtypes {

	/**
	 * Characters allowed in a subtype key.
	 *
	 * A key becomes a settings array entry (post:aucteeno_auction), part of
	 * an HTML id, and the literal file-name prefix matched by
	 * SitemapServer::PATTERN_CHUNK, so it is restricted to what is safe in
	 * all three.
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z0-9_-]+$/';

	/**
	 * Declared subtypes, keyed by owning post type.
	 *
	 * The owning post type's own key is NOT included here — see
	 * for_post_type(), which appends it as the fallback bucket.
	 *
	 * @return array<string, array<string, string>> Post type => (key => label).
	 */
	public function all(): array {
		/**
		 * Filters the SEO subtypes a post type splits into.
		 *
		 * Declaring subtypes gives each one its own row on the Titles &
		 * Templates tab, its own schema type, and its own sitemap family.
		 * It does NOT route any post into them — the plugin must also
		 * resolve individual posts through `taseo_post_subtype`.
		 *
		 * Keys must match /^[a-z0-9_-]+$/ and must not collide with a
		 * registered post type or taxonomy: sitemap chunk files and the
		 * file registry are keyed by subtype alone, so a colliding key
		 * would share another subtype's chunk namespace.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string, array<string, string>> $map Post type => (subtype key => label).
		 */
		$map = apply_filters( 'taseo_post_subtypes', array() );

		// Nothing declared is the overwhelmingly common case, and this runs on
		// every post sync. Bail before the two registry reads below rather
		// than paying for them to validate an empty list.
		if ( ! is_array( $map ) || array() === $map ) {
			return array();
		}

		$post_types = get_post_types();
		$taxonomies = get_taxonomies();
		$clean      = array();

		foreach ( $map as $post_type => $subtypes ) {
			$post_type = (string) $post_type;

			if ( ! isset( $post_types[ $post_type ] ) || ! is_array( $subtypes ) ) {
				continue;
			}

			$clean_subtypes = array();

			foreach ( $subtypes as $key => $label ) {
				$key = (string) $key;

				if ( 1 !== preg_match( self::KEY_PATTERN, $key ) || ! is_scalar( $label ) ) {
					continue;
				}

				if ( isset( $post_types[ $key ] ) || isset( $taxonomies[ $key ] ) ) {
					// Skipped, not rewritten — a silently renamed key would
					// strand the declaring plugin's own resolution calls.
					_doing_it_wrong(
						__METHOD__,
						sprintf( 'Post subtype key "%s" collides with a registered post type or taxonomy and was ignored.', esc_html( $key ) ),
						'0.4.0'
					);
					continue;
				}

				$label = (string) $label;

				$clean_subtypes[ $key ] = '' !== $label ? $label : $key;
			}

			if ( array() !== $clean_subtypes ) {
				$clean[ $post_type ] = $clean_subtypes;
			}
		}

		return $clean;
	}

	/**
	 * Every subtype a post of this type can resolve to.
	 *
	 * The post type's own key is always present as the last entry. It is
	 * appended rather than declared so that a filter naming only auctions
	 * and items still leaves ordinary products a home: anything the
	 * resolver does not claim lands in the fallback bucket instead of
	 * dropping out of the index.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, string> Key => label.
	 */
	public function for_post_type( string $post_type ): array {
		$declared = $this->all()[ $post_type ] ?? array();

		return $declared + array( $post_type => $this->post_type_label( $post_type ) );
	}

	/**
	 * Subtype keys a post of this type can resolve to.
	 *
	 * Same set as for_post_type() without the label lookups, which cost a
	 * get_post_type_object() call the resolver has no use for.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, string> Keys.
	 */
	public function keys_for_post_type( string $post_type ): array {
		return array_merge( array_keys( $this->all()[ $post_type ] ?? array() ), array( $post_type ) );
	}

	/**
	 * Owning post type for a subtype key.
	 *
	 * An undeclared key is its own post type: that is exactly what a subtype
	 * is on a site where nothing has been split, and it keeps callers from
	 * having to special-case the unsplit shape.
	 *
	 * @param string $subtype Subtype key.
	 * @return string Post type.
	 */
	public function post_type_for( string $subtype ): string {
		foreach ( $this->all() as $post_type => $subtypes ) {
			if ( isset( $subtypes[ $subtype ] ) ) {
				return $post_type;
			}
		}

		return $subtype;
	}

	/**
	 * Resolve one post to its subtype.
	 *
	 * @param WP_Post $post Post.
	 * @return string Subtype key; the post type itself when unclaimed.
	 */
	public function resolve( WP_Post $post ): string {
		/**
		 * Filters the SEO subtype one post resolves to.
		 *
		 * Return a key declared for this post's type through
		 * `taseo_post_subtypes`. Anything else — an unregistered key, a key
		 * belonging to a different post type — is ignored and the post
		 * stays in its post type's own bucket, so a typo degrades to the
		 * pre-subtype behaviour rather than stranding the post in a
		 * subtype with no settings row and no sitemap family.
		 *
		 * @since 0.4.0
		 *
		 * @param string  $subtype Resolved subtype; defaults to the post type.
		 * @param WP_Post $post    Post being resolved.
		 */
		$subtype = apply_filters( 'taseo_post_subtype', $post->post_type, $post );

		if ( ! is_string( $subtype ) || ! in_array( $subtype, $this->keys_for_post_type( $post->post_type ), true ) ) {
			return $post->post_type;
		}

		return $subtype;
	}

	/**
	 * Whether a key is a declared subtype of some post type.
	 *
	 * @param string $subtype Subtype key.
	 * @return bool True when declared.
	 */
	public function has( string $subtype ): bool {
		foreach ( $this->all() as $subtypes ) {
			if ( isset( $subtypes[ $subtype ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flat subtype list for the given post types, in post-type order.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<string, string> Key => label.
	 */
	public function flatten( array $post_types ): array {
		$flat = array();

		foreach ( $post_types as $post_type ) {
			foreach ( $this->for_post_type( (string) $post_type ) as $key => $label ) {
				$flat[ $key ] = $label;
			}
		}

		return $flat;
	}

	/**
	 * Human-readable label for a post type, falling back to its name.
	 *
	 * @param string $post_type Post type.
	 * @return string Label.
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return isset( $object->labels->name ) ? (string) $object->labels->name : $post_type;
	}
}
