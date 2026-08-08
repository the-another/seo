<?php
/**
 * Template Variables Registry
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

use TheAnother\Plugin\SEO\Indexable\PostSubtypes;

/**
 * Class TemplateVariables
 *
 * The single source of truth for which %%variables%% exist in which
 * context. Both the settings UI (pills, autocomplete) and save-time
 * validation read from here, so what the admin is offered, what the admin
 * may save, and what CurrentContext can actually resolve stay in agreement.
 *
 * The availability rules below are a transcription of what
 * CurrentContext::site_vars(), post_vars() and term_vars() produce —
 * tests/Unit/Meta/CurrentContextVariablesTest.php enforces that they stay
 * transcriptions rather than drifting apart again.
 */
class TemplateVariables {

	/**
	 * Constructor.
	 *
	 * @param PostSubtypes $subtypes Post subtype registry.
	 */
	public function __construct( private readonly PostSubtypes $subtypes ) {
	}

	/**
	 * Variables available in every context.
	 *
	 * A method rather than a constant: the labels are translated, and
	 * __() cannot be called in a constant expression.
	 *
	 * Labels are short names, not descriptions. They are rendered twice:
	 * as the clickable pills under each row, and as the chip the variable
	 * becomes inside the template field itself. A chip sits inline in a
	 * single-line input, so a descriptive phrase there crowds out the
	 * template it is part of.
	 *
	 * @return array<string, string> Slug => label.
	 */
	private function base_variables(): array {
		return array(
			'title'    => __( 'Title', 'the-another-seo' ),
			'sitename' => __( 'Site title', 'the-another-seo' ),
			'tagline'  => __( 'Tagline', 'the-another-seo' ),
			'sep'      => __( 'Separator', 'the-another-seo' ),
			'page'     => __( 'Page number', 'the-another-seo' ),
		);
	}

	/**
	 * Variables available for a given context.
	 *
	 * @param string $object_type    'post', 'term', 'system_page', or 'custom_page'.
	 * @param string $object_subtype Post type, taxonomy, system page key, or custom page key.
	 * @return array<string, string> Slug => label.
	 */
	public function get_for( string $object_type, string $object_subtype ): array {
		$variables = $this->base_variables();

		if ( 'post' === $object_type ) {
			$variables['excerpt'] = __( 'Excerpt', 'the-another-seo' );
			$variables['date']    = __( 'Publish date', 'the-another-seo' );

			// CurrentContext::post_vars() probes the POST TYPE, and a subtype
			// is not necessarily one — `aucteeno_auction` is a subtype of
			// `product`. Resolving back to the owner keeps both probes below
			// asking the same question the context asks.
			$post_type = $this->subtypes->post_type_for( $object_subtype );

			// Matches CurrentContext::post_vars()'s own taxonomy probe: this
			// is a property of the SUBTYPE (is a post type registered for
			// category/product_cat at all?), not of the individual object,
			// so it belongs in the registry rather than being left to
			// "the object just happens to have no terms". `page` is not
			// registered for `category` by default, so get_the_terms()
			// there can never resolve and the pill/autocomplete/validator
			// must not offer this token for it.
			if ( '' !== $post_type
				&& is_object_in_taxonomy( $post_type, 'product' === $post_type ? 'product_cat' : 'category' ) ) {
				$variables['primary_category'] = __( 'Primary category', 'the-another-seo' );
			}

			// Matches CurrentContext::post_vars()'s own WooCommerce probe: a
			// site without WooCommerce must not be offered variables that
			// could never resolve.
			if ( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
				$variables['price'] = __( 'Price', 'the-another-seo' );
				$variables['sku']   = __( 'SKU', 'the-another-seo' );
			}
		} elseif ( 'term' === $object_type ) {
			$variables['excerpt'] = __( 'Term description', 'the-another-seo' );
		}

		/**
		 * Filters the template variables available in one context.
		 *
		 * The type and subtype are passed so an extension can scope a
		 * variable to products rather than advertising it on 404 pages.
		 * Entries whose slug does not match the resolver's own character
		 * class are dropped — the registry must never offer a token
		 * TemplateResolver could not expand.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $variables      Slug => label.
		 * @param string                $object_type    'post'|'term'|'system_page'|'custom_page'.
		 * @param string                $object_subtype Post type, taxonomy, system page key, or custom page key.
		 */
		$filtered = apply_filters( 'taseo_template_variables', $variables, $object_type, $object_subtype );

		return is_array( $filtered ) ? $this->clean( $filtered ) : $variables;
	}

	/**
	 * Whether one variable is available in a context.
	 *
	 * @param string $variable       Slug, any case.
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return bool Available.
	 */
	public function is_available( string $variable, string $object_type, string $object_subtype ): bool {
		return array_key_exists(
			strtolower( $variable ),
			$this->get_for( $object_type, $object_subtype )
		);
	}

	/**
	 * Drop entries a filter added that the resolver could never expand.
	 *
	 * @param array<mixed, mixed> $variables Candidate variables.
	 * @return array<string, string> Clean variables.
	 */
	private function clean( array $variables ): array {
		$clean = array();

		foreach ( $variables as $slug => $label ) {
			if ( is_string( $slug ) && is_string( $label ) && 1 === preg_match( '/^[a-z0-9_]+$/', $slug ) ) {
				$clean[ $slug ] = $label;
			}
		}

		return $clean;
	}
}
