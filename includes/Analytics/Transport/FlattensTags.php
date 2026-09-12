<?php
/**
 * Tag Map Flattening
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

/**
 * Trait FlattensTags
 *
 * Collapses a `key => ids` map into one ordered list. The incoming map is built
 * in registry order, and array_merge() preserves it, so a transport serving two
 * vendors emits the first vendor's IDs first.
 *
 * @since 1.5.0
 */
trait FlattensTags {

	/**
	 * Every ID in the map, in registry order.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => IDs.
	 * @return array<int, string> Flat list.
	 */
	private function ids( array $tags ): array {
		return array_merge( ...array_values( $tags ) );
	}
}
