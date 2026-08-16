<?php
/**
 * Schema Graph Builder
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Schema;

use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\ImageResolver;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SchemaGraph
 *
 * Builds one interlinked @graph per page: WebSite, Organization/Person,
 * WebPage, BreadcrumbList (from the SAME trail the visible breadcrumbs
 * render), and an Article/Product main entity per the configured type.
 */
class SchemaGraph {

	/**
	 * Constructor.
	 *
	 * @param CurrentContext  $context     Request context.
	 * @param MetaOutput      $meta_output Resolved title/description source.
	 * @param BreadcrumbTrail $trail       Trail builder.
	 * @param Settings        $settings    Settings.
	 */
	/**
	 * Memoized node list (null = not yet built).
	 *
	 * The graph is printed in wp_head, but consumers that reconcile against
	 * it — notably the WooCommerce de-duplication, which runs in the footer —
	 * need to know what was emitted without paying to build it twice.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $built = null;

	/**
	 * Constructor.
	 *
	 * @param CurrentContext  $context     Request context.
	 * @param MetaOutput      $meta_output Resolved title/description source.
	 * @param BreadcrumbTrail $trail       Trail builder.
	 * @param Settings        $settings    Settings.
	 */
	public function __construct(
		private readonly CurrentContext $context,
		private readonly MetaOutput $meta_output,
		private readonly BreadcrumbTrail $trail,
		private readonly Settings $settings
	) {
	}

	/**
	 * Build the @graph node list.
	 *
	 * @return array<int, array<string, mixed>> Nodes; empty when disabled/unmanaged.
	 */
	public function build(): array {
		if ( null === $this->built ) {
			$this->built = $this->do_build();
		}

		return $this->built;
	}

	/**
	 * Whether the graph carries a node of one of the given types.
	 *
	 * @param array<int, string> $types Schema.org types.
	 * @return bool True when at least one node matches.
	 */
	public function has_node_of_type( array $types ): bool {
		foreach ( $this->build() as $node ) {
			if ( isset( $node['@type'] ) && in_array( $node['@type'], $types, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Uncached build.
	 *
	 * @return array<int, array<string, mixed>> Nodes.
	 */
	private function do_build(): array {
		$ctx = $this->context->resolve();

		if ( null === $ctx || ! empty( $ctx['row']['schema_disabled'] ) ) {
			return array();
		}

		$home        = (string) home_url( '/' );
		$website_id  = $home . '#website';
		$identity_id = $home . '#identity';
		$permalink   = '' !== (string) $ctx['permalink'] ? (string) $ctx['permalink'] : $home;
		$webpage_id  = $permalink . '#webpage';

		$graph   = array();
		$graph[] = $this->identity_node( $identity_id );
		$graph[] = array(
			'@type'     => 'WebSite',
			'@id'       => $website_id,
			'url'       => $home,
			'name'      => (string) get_bloginfo( 'name' ),
			'publisher' => array( '@id' => $identity_id ),
		);

		$webpage = array(
			'@type'      => 'WebPage',
			'@id'        => $webpage_id,
			'url'        => $permalink,
			'name'       => $this->meta_output->resolve_title( $ctx ),
			'isPartOf'   => array( '@id' => $website_id ),
			'breadcrumb' => array( '@id' => $permalink . '#breadcrumb' ),
		);

		$description = $this->meta_output->resolve_description( $ctx );

		if ( '' !== $description ) {
			$webpage['description'] = $description;
		}

		$main_entity = $this->main_entity_node( $ctx, $webpage_id );

		if ( null !== $main_entity ) {
			$webpage['mainEntity'] = array( '@id' => $main_entity['@id'] );
		}

		$graph[] = $webpage;
		$graph[] = $this->breadcrumb_node( $permalink );

		if ( null !== $main_entity ) {
			$graph[] = $main_entity;
		}

		/**
		 * Filters the finished @graph node list.
		 *
		 * Applied last, so this is the final word on what the page emits.
		 * One graph-level hook rather than one per node: an integration
		 * adding an image to the main entity, an Organization node for a
		 * vendor page, and an extra property elsewhere needs one filter
		 * rather than three, and the individual node shapes stay internal.
		 *
		 * Returning a non-array leaves the graph untouched; returning an
		 * empty array suppresses the JSON-LD block entirely.
		 *
		 * @since 0.4.0
		 *
		 * @param array<int, array<string, mixed>> $graph Nodes.
		 * @param array<string, mixed>             $ctx   Resolved request context.
		 */
		$filtered = apply_filters( 'taseo_schema_graph', $graph, $ctx );

		return $this->decode_entities( is_array( $filtered ) ? $filtered : $graph );
	}

	/**
	 * Turn HTML entities back into the characters they stand for.
	 *
	 * JSON-LD values are text, not markup. WordPress hands us markup: the
	 * `the_title` filter runs wptexturize(), so get_the_title() returns
	 * "Jack Daniel&#8217;s" — correct inside <title> and a meta attribute,
	 * and wrong here, where a consumer has no reason to HTML-decode and will
	 * render the entity literally.
	 *
	 * Applied after the filter, so the "text, not markup" invariant holds for
	 * whatever an integration contributed as well. Values are escaped for
	 * JSON, not HTML, by wp_json_encode() at print time, and the payload is
	 * emitted with JSON_HEX_TAG — so decoding here cannot let markup out.
	 *
	 * @param array<mixed> $value Graph or subtree.
	 * @return array<mixed> Decoded subtree.
	 */
	private function decode_entities( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->decode_entities( $item );
				continue;
			}

			if ( is_string( $item ) ) {
				$value[ $key ] = html_entity_decode( $item, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $value;
	}

	/**
	 * Organization or Person node.
	 *
	 * @param string $identity_id Node @id.
	 * @return array<string, mixed> Node.
	 */
	private function identity_node( string $identity_id ): array {
		$node = array(
			'@type' => 'person' === $this->settings->get_site_represents() ? 'Person' : 'Organization',
			'@id'   => $identity_id,
			'name'  => $this->settings->get_site_represents_name(),
		);

		$logo_url = ImageResolver::first(
			array(
				$this->settings->get_site_logo_url(),
				ImageResolver::attachment_url( $this->settings->get_site_logo_id() ),
			)
		);

		$logo_url = (string) apply_filters( 'taseo_logo_url', $logo_url );

		if ( '' !== $logo_url ) {
			$node['logo'] = $logo_url;
		}

		$same_as = $this->settings->get_same_as_urls();

		if ( array() !== $same_as ) {
			$node['sameAs'] = $same_as;
		}

		return $node;
	}

	/**
	 * BreadcrumbList node built from the shared trail.
	 *
	 * @param string $permalink Current permalink.
	 * @return array<string, mixed> Node.
	 */
	private function breadcrumb_node( string $permalink ): array {
		$items = array();

		foreach ( $this->trail->build() as $index => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $crumb['title'],
				'item'     => $crumb['url'],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $permalink . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Article/Product main entity per the configured schema type.
	 *
	 * @param array<string, mixed> $ctx        Context.
	 * @param string               $webpage_id WebPage node @id.
	 * @return array<string, mixed>|null Node or null for plain WebPage types.
	 */
	private function main_entity_node( array $ctx, string $webpage_id ): ?array {
		if ( 'post' !== $ctx['object_type'] ) {
			return null;
		}

		$type      = $this->settings->get_schema_type( (string) $ctx['object_subtype'], (string) ( $ctx['post_type'] ?? '' ) );
		$permalink = (string) $ctx['permalink'];

		if ( 'Article' === $type ) {
			$node = array(
				'@type'            => 'Article',
				'@id'              => $permalink . '#article',
				'headline'         => (string) ( $ctx['vars']['title'] ?? '' ),
				'datePublished'    => (string) get_the_date( 'c', (int) $ctx['object_id'] ),
				'dateModified'     => (string) get_the_modified_date( 'c', (int) $ctx['object_id'] ),
				'mainEntityOfPage' => array( '@id' => $webpage_id ),
			);

			$author_id = (int) get_post_field( 'post_author', (int) $ctx['object_id'] );

			if ( $author_id > 0 ) {
				$node['author'] = array(
					'@type' => 'Person',
					'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
				);
			}

			return $node;
		}

		// Keyed off the post type, not the subtype: a marketplace can split
		// `product` into auction/item subtypes, each free to select the
		// Product schema type, and all still resolvable through WooCommerce.
		if ( 'Product' === $type && 'product' === ( $ctx['post_type'] ?? '' ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $ctx['object_id'] );

			if ( ! $product ) {
				return null;
			}

			$node = array(
				'@type' => 'Product',
				'@id'   => $permalink . '#product',
				'name'  => (string) ( $ctx['vars']['title'] ?? '' ),
				'sku'   => (string) $product->get_sku(),
			);

			$price = trim( (string) $product->get_price() );

			// An Offer is only as good as the price it states. get_price() is
			// '' for anything with neither a regular nor a sale price, and an
			// empty price is not read as "no price known" — a search engine
			// reads it as a malformed one and reports the page. A currency and
			// a stock status with nothing to buy at are not an offer either,
			// so the whole key goes rather than the page carrying an error.
			if ( '' !== $price ) {
				$node['offers'] = array(
					'@type'         => 'Offer',
					'url'           => $permalink,
					'price'         => $price,
					'priceCurrency' => (string) get_woocommerce_currency(),
					'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				);
			}

			return $node;
		}

		return null;
	}
}
