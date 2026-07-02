<?php
/**
 * Template Resolver
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Class TemplateResolver
 *
 * Expands %%variable%% tokens against a context array in a single
 * non-recursive pass. Unknown/empty variables vanish (with their
 * surrounding whitespace collapsed) rather than leaking literal tokens.
 */
class TemplateResolver {

	/**
	 * Resolve a template against a context.
	 *
	 * @param string                $template Template with %%tokens%%.
	 * @param array<string, string> $context  Token => replacement value.
	 * @return string Resolved string.
	 */
	public function resolve( string $template, array $context ): string {
		$resolved = preg_replace_callback(
			'/%%([a-z0-9_]+)%%/i',
			static function ( array $matches ) use ( $context ): string {
				return (string) ( $context[ strtolower( $matches[1] ) ] ?? '' );
			},
			$template
		);

		$resolved = preg_replace( '/\s+/', ' ', (string) $resolved );

		return trim( (string) $resolved );
	}
}
