<?php
/**
 * Shared image URL resolution.
 *
 * @package TheAnother\SEO
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Turns image candidates into a URL.
 *
 * Both the social output and the schema graph resolve an image the same way —
 * an explicit URL override beats an attachment ID, and a missing attachment
 * falls through rather than emitting an empty value. This holds that logic
 * once so the two cannot drift apart.
 */
final class ImageResolver {

	/**
	 * URL for an attachment ID.
	 *
	 * Returns '' rather than false for an attachment that no longer exists,
	 * so callers can treat "not set" and "deleted since" identically and fall
	 * through to their next candidate.
	 *
	 * @param int $id Attachment ID.
	 * @return string URL, or '' when it cannot resolve.
	 */
	public static function attachment_url( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'full' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * First non-empty candidate.
	 *
	 * @param array<int, string> $candidates Ordered candidates, most specific first.
	 * @return string First non-empty candidate, or ''.
	 */
	public static function first( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}
}
