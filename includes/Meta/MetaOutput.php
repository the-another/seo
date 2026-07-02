<?php
/**
 * Meta Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

use TheAnother\Plugin\SEO\HookManager;

/**
 * Class MetaOutput
 *
 * Emits <title>, meta description, canonical, and robots for the current
 * request from its resolved context. Canonical always uses the live
 * permalink (or the admin's canonical_url override) — never the cached
 * permalink column, which exists for bulk consumers only.
 */
class MetaOutput {

	/**
	 * Constructor.
	 *
	 * @param CurrentContext   $context  Current request context.
	 * @param TemplateResolver $resolver Template resolver.
	 */
	public function __construct(
		private readonly CurrentContext $context,
		private readonly TemplateResolver $resolver
	) {
	}

	/**
	 * Register hooks and unhook core's canonical output.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ) );
		$hook_manager->register_action( 'wp_head', array( $this, 'print_head_tags' ), 1 );

		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Resolve the document title.
	 *
	 * @param string $title Incoming title.
	 * @return string Resolved title.
	 */
	public function filter_document_title( string $title ): string {
		$ctx = $this->context->resolve();

		if ( null === $ctx ) {
			return $title;
		}

		return esc_html( $this->resolve_title( $ctx ) );
	}

	/**
	 * Print description, canonical, and robots tags.
	 *
	 * @return void
	 */
	public function print_head_tags(): void {
		$ctx = $this->context->resolve();

		if ( null === $ctx ) {
			return;
		}

		$description = $this->resolve_description( $ctx );

		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}

		$canonical = ! empty( $ctx['row']['canonical_url'] ) ? (string) $ctx['row']['canonical_url'] : (string) $ctx['permalink'];

		if ( '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}

		$robots = array();

		if ( ! empty( $ctx['row']['robots_noindex'] ) ) {
			$robots[] = 'noindex';
		}
		if ( ! empty( $ctx['row']['robots_nofollow'] ) ) {
			$robots[] = 'nofollow';
		}
		if ( ! empty( $ctx['row']['robots_noarchive'] ) ) {
			$robots[] = 'noarchive';
		}

		if ( array() !== $robots ) {
			echo '<meta name="robots" content="' . esc_attr( implode( ', ', $robots ) ) . '" />' . "\n";
		}
	}

	/**
	 * Title: override → template.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string Title.
	 */
	public function resolve_title( array $ctx ): string {
		if ( ! empty( $ctx['row']['title'] ) ) {
			return (string) $ctx['row']['title'];
		}

		return $this->resolver->resolve( (string) $ctx['title_template'], $ctx['vars'] );
	}

	/**
	 * Description: override → template.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string Description.
	 */
	public function resolve_description( array $ctx ): string {
		if ( ! empty( $ctx['row']['description'] ) ) {
			return (string) $ctx['row']['description'];
		}

		return $this->resolver->resolve( (string) $ctx['description_template'], $ctx['vars'] );
	}
}
