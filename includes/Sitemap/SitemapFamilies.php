<?php
/**
 * Sitemap Families Registry
 *
 * @package TheAnotherSEO
 * @since 0.3.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Sitemap;

/**
 * Class SitemapFamilies
 *
 * URL families another plugin registers for sitemap inclusion — vendor
 * stores, location archives, any URL set this plugin cannot see through
 * core conditionals. Deliberately independent of the taseo_custom_pages
 * registry: a family does not need template fields to be in the sitemap.
 *
 * The settings screen and the push API both read the list through this
 * class rather than each calling apply_filters() themselves — two call
 * sites would be two places for key sanitization to drift.
 */
class SitemapFamilies {

	/**
	 * Characters allowed in a family key.
	 *
	 * A key becomes a settings array entry, part of an HTML id, and the
	 * literal file-name prefix matched by SitemapServer::PATTERN_CHUNK, so
	 * it is restricted to what is safe in all three.
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z0-9_-]+$/';

	/**
	 * Registered families.
	 *
	 * @return array<string, string> Key => label.
	 */
	public function all(): array {
		/**
		 * Filters the sitemap URL families other plugins register.
		 *
		 * Registering a family lists it (with a per-family include toggle)
		 * on the Sitemap settings tab and allows URLs to be pushed into it
		 * via taseo_sitemap_sync_url().
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, string> $families Key => human-readable label.
		 */
		$families = apply_filters( 'taseo_sitemap_families', array() );

		if ( ! is_array( $families ) ) {
			return array();
		}

		$post_types = get_post_types();
		$taxonomies = get_taxonomies();
		$clean      = array();

		foreach ( $families as $key => $label ) {
			$key = (string) $key;

			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
				continue;
			}

			if ( isset( $post_types[ $key ] ) || isset( $taxonomies[ $key ] ) ) {
				// Chunk files and the registry are keyed by subtype alone, so
				// a family named like a post type or taxonomy would share its
				// chunk namespace. Skipped, not rewritten — a silently renamed
				// key would strand the provider's own push calls.
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Sitemap family key "%s" collides with a registered post type or taxonomy and was ignored.', esc_html( $key ) ),
					'0.3.0'
				);
				continue;
			}

			if ( ! is_scalar( $label ) ) {
				continue;
			}

			$label = (string) $label;

			$clean[ $key ] = '' !== $label ? $label : $key;
		}

		return $clean;
	}

	/**
	 * Whether a key is registered.
	 *
	 * @param string $key Family key.
	 * @return bool True when registered.
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->all() );
	}
}
