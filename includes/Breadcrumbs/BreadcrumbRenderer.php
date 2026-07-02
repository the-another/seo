<?php
/**
 * Breadcrumb Renderer
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Breadcrumbs;

use TheAnother\Plugin\SEO\Container;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class BreadcrumbRenderer
 *
 * Renders the trail as an accessible <nav>. Exposed three ways — template
 * tag, shortcode, block (blocks/breadcrumbs/render.php) — all through this
 * one render() method.
 */
class BreadcrumbRenderer {

	/**
	 * Constructor.
	 *
	 * @param BreadcrumbTrail $trail    Trail builder.
	 * @param Settings        $settings Settings.
	 */
	public function __construct(
		private readonly BreadcrumbTrail $trail,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register the shortcode.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- init(HookManager) is the uniform registration signature; this class registers via add_shortcode() instead.
		add_shortcode( 'taseo_breadcrumbs', array( $this, 'render' ) );
	}

	/**
	 * Render the trail as HTML.
	 *
	 * @return string HTML, '' when the trail is empty.
	 */
	public function render(): string {
		$crumbs = $this->trail->build();

		if ( array() === $crumbs ) {
			return '';
		}

		$separator    = $this->settings->get_breadcrumb_separator();
		$link_current = $this->settings->breadcrumb_link_current();
		$last_index   = count( $crumbs ) - 1;
		$parts        = array();

		foreach ( $crumbs as $index => $crumb ) {
			$is_current = ( $index === $last_index );

			if ( $is_current && ! $link_current ) {
				$parts[] = '<span aria-current="page">' . esc_html( $crumb['title'] ) . '</span>';
			} elseif ( $is_current ) {
				$parts[] = '<a href="' . esc_url( $crumb['url'] ) . '" aria-current="page">' . esc_html( $crumb['title'] ) . '</a>';
			} else {
				$parts[] = '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['title'] ) . '</a>';
			}
		}

		return '<nav class="taseo-breadcrumbs" aria-label="Breadcrumb">'
			. implode( ' <span class="taseo-breadcrumbs__sep" aria-hidden="true">' . esc_html( $separator ) . '</span> ', $parts )
			. '</nav>';
	}
}

if ( ! function_exists( 'taseo_breadcrumbs' ) ) {
	/**
	 * Template tag: echo the breadcrumb trail.
	 *
	 * @return void
	 */
	function taseo_breadcrumbs(): void { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- global template tag function is intentionally colocated with the class it renders.
		echo Container::get_instance()->get( 'breadcrumb_renderer' )->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes internally.
	}
}
