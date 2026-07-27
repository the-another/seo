<?php
/**
 * Custom Pages Registry
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Class CustomPages
 *
 * Pages another plugin registers for templating — a checkout screen, an
 * account area, any virtual page this plugin has no way to know about.
 *
 * Both the settings screen and CurrentContext read the list through this
 * class rather than each calling apply_filters() themselves. Two call sites
 * would be two places for the key sanitization to drift, and a page that
 * registers under one key while resolving under another produces a row that
 * silently never renders.
 */
class CustomPages {

	/**
	 * Characters allowed in a custom page key.
	 *
	 * A key becomes both a settings array key (custom_page:checkout) and part
	 * of an HTML id, so it is restricted to what is safe in both. The colon
	 * in particular is excluded because it separates object type from subtype
	 * in every stored row key.
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z0-9_-]+$/';

	/**
	 * Registered custom pages.
	 *
	 * @return array<string, string> Key => label.
	 */
	public function all(): array {
		/**
		 * Filters the custom pages offered on the Titles & Templates tab.
		 *
		 * Registering a page here gives it template fields on the settings
		 * screen. It does NOT make those templates render — the plugin must
		 * also claim the request through `taseo_custom_page_context`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $pages Key => human-readable label.
		 */
		$pages = apply_filters( 'taseo_custom_pages', array() );

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$clean = array();

		foreach ( $pages as $key => $label ) {
			$key = (string) $key;

			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
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
	 * @param string $key Custom page key.
	 * @return bool True when registered.
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->all() );
	}
}
