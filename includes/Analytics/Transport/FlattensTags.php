<?php
/**
 * Tag Map Flattening
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

use TheAnother\Plugin\SEO\Analytics\TagSlice;

/**
 * Trait FlattensTags
 *
 * Collapses a list of slices into one ordered list of IDs. The incoming slices
 * are built in registry order, so a transport serving two vendors emits the
 * first vendor's IDs first.
 *
 * @since 1.5.0
 */
trait FlattensTags {

	/**
	 * Every ID across the slices, in registry order.
	 *
	 * @since 1.5.0
	 * @since 1.6.0 Takes slices rather than a key => IDs map.
	 *
	 * @param array<int, TagSlice> $slices Slices.
	 * @return array<int, string> Flat list.
	 */
	private function ids( array $slices ): array {
		$ids = array();

		foreach ( $slices as $slice ) {
			foreach ( $slice->ids as $id ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
