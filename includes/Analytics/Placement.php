<?php
/**
 * Tracking Tag Placements
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

/**
 * Enum Placement
 *
 * Where a vendor's primary snippet goes, and the WordPress hook that puts it
 * there. ScriptQueue is not a document position but a queue: a vendor whose
 * snippet loads an external library belongs there, so WordPress owns the script
 * tag and inline configuration can be attached to it.
 *
 * @since 1.5.0
 */
enum Placement {

	/**
	 * The wp_enqueue_scripts queue.
	 */
	case ScriptQueue;

	/**
	 * Inline in <head>.
	 */
	case Head;

	/**
	 * Inline immediately after <body>.
	 */
	case BodyOpen;

	/**
	 * The hook this placement fires on.
	 *
	 * @since 1.5.0
	 *
	 * @return string Hook name.
	 */
	public function hook(): string {
		return match ( $this ) {
			self::ScriptQueue => 'wp_enqueue_scripts',
			self::Head        => 'wp_head',
			self::BodyOpen    => 'wp_body_open',
		};
	}
}
