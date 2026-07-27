<?php
/**
 * One image field: attachment picker plus URL override.
 *
 * @package TheAnother\SEO
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Admin;

/**
 * Renders an image field.
 *
 * The attachment ID travels in a hidden input under the field name it always
 * had, so the submitted form is unchanged and every existing sanitizer keeps
 * working. The picker script fills that input; with JavaScript off the input
 * still submits whatever was stored, and the URL override beside it is an
 * ordinary text field — so the field degrades to something usable rather than
 * silently discarding what was saved.
 *
 * Core classes only: `button` and `large-text`. No stylesheet ships with this
 * plugin.
 */
final class ImageField {

	/**
	 * Render one image field.
	 *
	 * @param string $id_name   Form name for the attachment ID input.
	 * @param int    $id_value  Current attachment ID, 0 when unset.
	 * @param string $url_name  Form name for the URL override input.
	 * @param string $url_value Current URL override, '' when unset.
	 * @param string $html_id   Prefix for this field's HTML ids.
	 * @return void
	 */
	public static function render(
		string $id_name,
		int $id_value,
		string $url_name,
		string $url_value,
		string $html_id
	): void {
		printf(
			'<div data-taseo-image-field><input type="hidden" name="%1$s" value="%2$d" data-taseo-image-id />',
			esc_attr( $id_name ),
			(int) $id_value
		);

		if ( $id_value > 0 ) {
			$preview = wp_get_attachment_image_url( $id_value, 'thumbnail' );

			if ( is_string( $preview ) ) {
				printf(
					'<img src="%s" alt="" width="80" height="80" data-taseo-image-preview /><br />',
					esc_url( $preview )
				);
			}
		}

		printf(
			'<button type="button" class="button" data-taseo-image-select>%1$s</button> <button type="button" class="button" data-taseo-image-remove>%2$s</button>',
			esc_html__( 'Select image', 'the-another-seo' ),
			esc_html__( 'Remove', 'the-another-seo' )
		);

		printf(
			'<p><label for="%1$s-url">%2$s</label><br /><input type="url" id="%1$s-url" name="%3$s" value="%4$s" class="large-text" placeholder="https://…" /></p>',
			esc_attr( $html_id ),
			esc_html__( 'Image URL (overrides the selected image)', 'the-another-seo' ),
			esc_attr( $url_name ),
			esc_attr( $url_value )
		);

		echo '</div>';
	}
}
