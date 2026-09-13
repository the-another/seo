<?php
/**
 * Tracking Tag Transport
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

/**
 * Interface TagTransport
 *
 * How one vendor family's snippet reaches the page. A transport receives only
 * the vendor keys assigned to it, and only IDs that have already passed their
 * vendor's pattern — validation belongs to TagType and TagResolver, never here.
 *
 * Both methods emit by side effect rather than returning markup: GA4's mechanism
 * IS a side effect (wp_enqueue_script() plus wp_add_inline_script()), and a
 * string-returning contract could not express it.
 *
 * @since 1.5.0
 */
interface TagTransport {

	/**
	 * Emit the primary snippet.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => validated IDs.
	 * @return void
	 */
	public function emit_primary( array $tags ): void;

	/**
	 * Emit the no-JS half, after <body>.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => validated IDs.
	 * @return void
	 */
	public function emit_noscript( array $tags ): void;
}
