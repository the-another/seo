<?php
/**
 * Social Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Social;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SocialOutput
 *
 * Open Graph + Twitter Card tags. OG covers Facebook, LinkedIn, Pinterest,
 * and Instagram link-preview surfaces (there is no instagram:* protocol);
 * the two toggles are independent. WooCommerce products upgrade og:type
 * to product with price/currency/availability.
 */
class SocialOutput {

	/**
	 * Constructor.
	 *
	 * @param CurrentContext $context     Request context.
	 * @param MetaOutput     $meta_output Resolved title/description source.
	 * @param Settings       $settings    Settings.
	 */
	public function __construct(
		private readonly CurrentContext $context,
		private readonly MetaOutput $meta_output,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_tags' ), 2 );
	}

	/**
	 * Print all enabled social tags.
	 *
	 * @return void
	 */
	public function print_tags(): void {
		$ctx = $this->context->resolve();

		if ( null === $ctx ) {
			return;
		}

		$title       = $this->meta_output->resolve_title( $ctx );
		$description = $this->meta_output->resolve_description( $ctx );
		$image_url   = $this->resolve_image_url( $ctx );

		if ( $this->settings->is_open_graph_enabled() ) {
			$this->print_open_graph( $ctx, $title, $description, $image_url );
		}

		if ( $this->settings->is_twitter_enabled() ) {
			$this->print_twitter( $ctx, $title, $description, $image_url );
		}
	}

	/**
	 * Open Graph tags.
	 *
	 * @param array<string, mixed> $ctx         Context.
	 * @param string               $title       Resolved title.
	 * @param string               $description Resolved description.
	 * @param string               $image_url   Image URL or ''.
	 * @return void
	 */
	private function print_open_graph( array $ctx, string $title, string $description, string $image_url ): void {
		$og_title       = ! empty( $ctx['row']['og_title'] ) ? (string) $ctx['row']['og_title'] : $title;
		$og_description = ! empty( $ctx['row']['og_description'] ) ? (string) $ctx['row']['og_description'] : $description;

		$product = $this->get_product( $ctx );

		echo '<meta property="og:type" content="' . esc_attr( $product ? 'product' : 'website' ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";

		if ( '' !== $og_description ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_description ) . '" />' . "\n";
		}

		if ( ! empty( $ctx['permalink'] ) ) {
			echo '<meta property="og:url" content="' . esc_url( (string) $ctx['permalink'] ) . '" />' . "\n";
		}

		echo '<meta property="og:site_name" content="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '" />' . "\n";

		if ( '' !== $image_url ) {
			echo '<meta property="og:image" content="' . esc_url( $image_url ) . '" />' . "\n";
		}

		$app_id = $this->settings->get_facebook_app_id();

		if ( '' !== $app_id ) {
			echo '<meta property="fb:app_id" content="' . esc_attr( $app_id ) . '" />' . "\n";
		}

		if ( $product ) {
			echo '<meta property="product:price:amount" content="' . esc_attr( (string) $product->get_price() ) . '" />' . "\n";
			echo '<meta property="product:price:currency" content="' . esc_attr( (string) get_woocommerce_currency() ) . '" />' . "\n";
			echo '<meta property="og:availability" content="' . esc_attr( $product->is_in_stock() ? 'instock' : 'oos' ) . '" />' . "\n";
		}
	}

	/**
	 * Twitter Card tags.
	 *
	 * @param array<string, mixed> $ctx         Context.
	 * @param string               $title       Resolved title.
	 * @param string               $description Resolved description.
	 * @param string               $image_url   Image URL or ''.
	 * @return void
	 */
	private function print_twitter( array $ctx, string $title, string $description, string $image_url ): void {
		$tw_title       = ! empty( $ctx['row']['twitter_title'] ) ? (string) $ctx['row']['twitter_title'] : $title;
		$tw_description = ! empty( $ctx['row']['twitter_description'] ) ? (string) $ctx['row']['twitter_description'] : $description;

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $tw_title ) . '" />' . "\n";

		if ( '' !== $tw_description ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $tw_description ) . '" />' . "\n";
		}

		$tw_image = ! empty( $ctx['row']['twitter_image_id'] )
			? (string) wp_get_attachment_image_url( (int) $ctx['row']['twitter_image_id'], 'full' )
			: $image_url;

		if ( '' !== $tw_image && false !== $tw_image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $tw_image ) . '" />' . "\n";
		}

		$site = $this->settings->get_twitter_site();

		if ( '' !== $site ) {
			echo '<meta name="twitter:site" content="' . esc_attr( $site ) . '" />' . "\n";
		}
	}

	/**
	 * Image: per-object OG override → site default → ''.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string URL or ''.
	 */
	private function resolve_image_url( array $ctx ): string {
		$image_id = ! empty( $ctx['row']['og_image_id'] )
			? (int) $ctx['row']['og_image_id']
			: $this->settings->get_default_social_image_id();

		if ( 0 === $image_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'full' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * WC product for the context, when applicable.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return object|null Product or null.
	 */
	private function get_product( array $ctx ): ?object {
		if ( 'product' !== $ctx['object_subtype'] || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( (int) $ctx['object_id'] );

		return $product ? $product : null;
	}
}
