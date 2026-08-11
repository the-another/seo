<?php
/**
 * WooCommerce Structured Data De-duplication
 *
 * @package TheAnotherSEO
 * @since 0.4.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Schema;

use TheAnother\Plugin\SEO\HookManager;

/**
 * Class WooCommerceDeduplication
 *
 * WooCommerce emits its own JSON-LD from `wp_footer`, so a product page with
 * this plugin active carries two graphs describing the same thing: two Product
 * nodes with different @ids, and two BreadcrumbLists. Search engines are left
 * to guess which is authoritative, and the two can disagree — WooCommerce
 * reads its own product object while this plugin reads the indexable row and
 * whatever `taseo_schema_graph` contributed.
 *
 * Worse, WooCommerce accumulates rather than replaces: a theme that renders
 * the product summary twice produces two IDENTICAL Product nodes in one
 * script, which is what a real catalogue page here was doing.
 *
 * So this suppresses WooCommerce's copy — but only for the nodes this plugin
 * actually emitted on that request. If schema is switched off for a subtype,
 * or the row has schema_disabled, WooCommerce's markup is left alone rather
 * than stripping structured data and putting nothing in its place.
 *
 * Suppression uses WooCommerce's own filters, not remove_action(), so a site
 * that has customised those filters keeps its customisation and any other
 * WooCommerce structured data (reviews, Organization) is untouched.
 */
class WooCommerceDeduplication {

	/**
	 * Main-entity types whose presence means this plugin owns the product
	 * description for the page. Article counts: an admin who set the subtype
	 * to Article has chosen that over Product, and letting WooCommerce add a
	 * Product back would silently overturn the setting.
	 *
	 * @var array<int, string>
	 */
	private const MAIN_ENTITY_TYPES = array( 'Product', 'Article' );

	/**
	 * Constructor.
	 *
	 * @param SchemaGraph $graph Graph builder.
	 */
	public function __construct( private readonly SchemaGraph $graph ) {
	}

	/**
	 * Register hooks.
	 *
	 * Both filters run while WooCommerce generates its markup, which happens
	 * after wp_head has already built and printed our graph — so asking the
	 * graph what it emitted costs nothing beyond an array scan.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_filter( 'woocommerce_structured_data_product', array( $this, 'filter_product' ) );
		$hook_manager->register_filter( 'woocommerce_structured_data_breadcrumblist', array( $this, 'filter_breadcrumblist' ) );
	}

	/**
	 * Drop WooCommerce's Product node when this plugin emitted a main entity.
	 *
	 * An empty array is how WooCommerce is told to skip: WC_Structured_Data
	 * ::set_data() rejects anything without an '@type'.
	 *
	 * @param array<string, mixed> $markup WooCommerce's product markup.
	 * @return array<string, mixed> Markup, or empty to suppress.
	 */
	public function filter_product( $markup ) {
		if ( ! is_array( $markup ) ) {
			return $markup;
		}

		return $this->graph->has_node_of_type( self::MAIN_ENTITY_TYPES ) ? array() : $markup;
	}

	/**
	 * Drop WooCommerce's BreadcrumbList when this plugin emitted one.
	 *
	 * @param array<string, mixed> $markup WooCommerce's breadcrumb markup.
	 * @return array<string, mixed> Markup, or empty to suppress.
	 */
	public function filter_breadcrumblist( $markup ) {
		if ( ! is_array( $markup ) ) {
			return $markup;
		}

		return $this->graph->has_node_of_type( array( 'BreadcrumbList' ) ) ? array() : $markup;
	}
}
