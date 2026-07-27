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

		return $graph;
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

		$type      = $this->settings->get_schema_type( (string) $ctx['object_subtype'] );
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

		if ( 'Product' === $type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $ctx['object_id'] );

			if ( ! $product ) {
				return null;
			}

			return array(
				'@type'  => 'Product',
				'@id'    => $permalink . '#product',
				'name'   => (string) ( $ctx['vars']['title'] ?? '' ),
				'sku'    => (string) $product->get_sku(),
				'offers' => array(
					'@type'         => 'Offer',
					'url'           => $permalink,
					'price'         => (string) $product->get_price(),
					'priceCurrency' => (string) get_woocommerce_currency(),
					'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				),
			);
		}

		return null;
	}
}
