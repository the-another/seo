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
	 * The one pattern that defines what a template variable looks like.
	 * Everything that finds tokens uses this, so the definition cannot drift.
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '/%%([a-z0-9_]+)%%/i';

	/**
	 * Resolve a template against a context.
	 *
	 * @param string                $template Template with %%tokens%%.
	 * @param array<string, string> $context  Token => replacement value.
	 * @return string Resolved string.
	 */
	public function resolve( string $template, array $context ): string {
		$resolved = preg_replace_callback(
			self::TOKEN_PATTERN,
			static function ( array $matches ) use ( $context ): string {
				return (string) ( $context[ strtolower( $matches[1] ) ] ?? '' );
			},
			$template
		);

		$resolved = preg_replace( '/\s+/', ' ', (string) $resolved );

		return trim( (string) $resolved );
	}

	/**
	 * Extract the variable slugs a template references.
	 *
	 * Uses the same pattern resolve() expands with, so validation can never
	 * disagree with what actually gets substituted at render time.
	 *
	 * @param string $template Template with %%tokens%%.
	 * @return array<int, string> Lowercased slugs, in order, without duplicates.
	 */
	public static function extract_variables( string $template ): array {
		preg_match_all( self::TOKEN_PATTERN, $template, $matches );

		return array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
	}
}
