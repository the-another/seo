<?php
/**
 * Tracking Tag Slice
 *
 * @package TheAnotherSEO
 * @since 1.6.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

/**
 * Class TagSlice
 *
 * One vendor's declaration paired with the IDs resolved for it on this request.
 *
 * Transports were handed a bare `key => ids` map until 1.6.0, which was enough
 * while consent was decided before they ran. It is not enough now: the shared
 * gtag loader has to know which consent category each ID belongs to, because its
 * URL embeds one of them and a loader fetched under a refused category is the
 * exact leak this feature exists to prevent.
 *
 * @since 1.6.0
 */
final readonly class TagSlice {

	/**
	 * Constructor.
	 *
	 * @param TagType            $type Vendor declaration.
	 * @param array<int, string> $ids  Validated IDs, in resolution order.
	 */
	public function __construct(
		public TagType $type,
		public array $ids
	) {
	}
}
