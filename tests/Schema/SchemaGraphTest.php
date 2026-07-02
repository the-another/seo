<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Schema\SchemaGraph;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( SchemaGraph::class )]
class SchemaGraphTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $context;
	private $trail;
	private $settings;
	private SchemaGraph $graph;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->context  = Mockery::mock( CurrentContext::class );
		$this->trail    = Mockery::mock( BreadcrumbTrail::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'get_site_represents' )->andReturn( 'organization' )->byDefault();
		$this->settings->shouldReceive( 'get_site_represents_name' )->andReturn( 'Acme Auctions' )->byDefault();
		$this->settings->shouldReceive( 'get_site_logo_id' )->andReturn( 0 )->byDefault();
		$this->settings->shouldReceive( 'get_same_as_urls' )->andReturn( array() )->byDefault();
		$this->settings->shouldReceive( 'get_schema_type' )->andReturn( 'WebPage' )->byDefault();

		$this->trail->shouldReceive( 'build' )->andReturn(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'About', 'url' => 'https://example.com/about/' ),
			)
		)->byDefault();

		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Auctions' );

		$meta_output = new MetaOutput( $this->context, new TemplateResolver() );
		$this->graph = new SchemaGraph( $this->context, $meta_output, $this->trail, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function page_context( ?array $row = null ): array {
		return array(
			'object_type'          => 'post',
			'object_subtype'       => 'page',
			'object_id'            => 512,
			'row'                  => $row,
			'vars'                 => array( 'title' => 'About Us', 'sitename' => 'Acme Auctions', 'sep' => '–', 'excerpt' => 'Who we are.' ),
			'permalink'            => 'https://example.com/about/',
			'title_template'       => '%%title%%',
			'description_template' => '%%excerpt%%',
		);
	}

	public function test_graph_contains_website_identity_webpage_breadcrumb_nodes(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		$graph = $this->graph->build();
		$types = array_column( $graph, '@type' );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'Organization', $types );
		$this->assertContains( 'WebPage', $types );
		$this->assertContains( 'BreadcrumbList', $types );
	}

	public function test_breadcrumb_list_mirrors_trail_positions(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		$graph      = $this->graph->build();
		$breadcrumb = null;

		foreach ( $graph as $node ) {
			if ( 'BreadcrumbList' === $node['@type'] ) {
				$breadcrumb = $node;
			}
		}

		$this->assertNotNull( $breadcrumb );
		$this->assertCount( 2, $breadcrumb['itemListElement'] );
		$this->assertSame( 1, $breadcrumb['itemListElement'][0]['position'] );
		$this->assertSame( 'Home', $breadcrumb['itemListElement'][0]['name'] );
		$this->assertSame( 'https://example.com/about/', $breadcrumb['itemListElement'][1]['item'] );
	}

	public function test_schema_disabled_row_yields_empty_graph(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->page_context( array( 'schema_disabled' => '1' ) )
		);

		$this->assertSame( array(), $this->graph->build() );
	}

	public function test_unmanaged_context_yields_empty_graph(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		$this->assertSame( array(), $this->graph->build() );
	}

	public function test_empty_permalink_falls_back_to_home_for_ids(): void {
		$ctx               = $this->page_context();
		$ctx['permalink']  = '';
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		$graph      = $this->graph->build();
		$webpage    = null;
		$breadcrumb = null;

		foreach ( $graph as $node ) {
			if ( 'WebPage' === $node['@type'] ) {
				$webpage = $node;
			}
			if ( 'BreadcrumbList' === $node['@type'] ) {
				$breadcrumb = $node;
			}
		}

		$this->assertNotNull( $webpage );
		$this->assertNotNull( $breadcrumb );
		$this->assertSame( 'https://example.com/#webpage', $webpage['@id'] );
		$this->assertSame( 'https://example.com/#breadcrumb', $webpage['breadcrumb']['@id'] );
		$this->assertSame( 'https://example.com/#breadcrumb', $breadcrumb['@id'] );
	}

	public function test_article_type_adds_article_node_as_main_entity(): void {
		$this->settings->shouldReceive( 'get_schema_type' )->with( 'post' )->andReturn( 'Article' );

		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'post';
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		Functions\when( 'get_the_date' )->justReturn( '2026-07-01' );
		Functions\when( 'get_the_modified_date' )->justReturn( '2026-07-02' );
		Functions\when( 'get_post_field' )->justReturn( '3' );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Editor' );

		$graph = $this->graph->build();
		$types = array_column( $graph, '@type' );

		$this->assertContains( 'Article', $types );

		$article = null;
		$webpage = null;

		foreach ( $graph as $node ) {
			if ( 'Article' === $node['@type'] ) {
				$article = $node;
				$this->assertSame( 'About Us', $node['headline'] );
				$this->assertSame( '2026-07-01', $node['datePublished'] );
				$this->assertSame( '2026-07-02', $node['dateModified'] );
				$this->assertSame( array( '@type' => 'Person', 'name' => 'Jane Editor' ), $node['author'] );
			}
			if ( 'WebPage' === $node['@type'] ) {
				$webpage = $node;
				$this->assertArrayHasKey( 'mainEntity', $node );
			}
		}

		$this->assertNotNull( $article );
		$this->assertNotNull( $webpage );
		$this->assertSame( $article['@id'], $webpage['mainEntity']['@id'] );
	}

	public function test_product_type_adds_product_node_with_offer(): void {
		$this->settings->shouldReceive( 'get_schema_type' )->with( 'product' )->andReturn( 'Product' );

		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'product';
		$ctx['object_id']      = 88123;
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_sku' )->andReturn( 'VW-1' );
		$product->shouldReceive( 'get_price' )->andReturn( '129.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$graph = $this->graph->build();

		foreach ( $graph as $node ) {
			if ( 'Product' === $node['@type'] ) {
				$this->assertSame( 'About Us', $node['name'] );
				$this->assertSame( 'VW-1', $node['sku'] );
				$this->assertSame( '129.00', $node['offers']['price'] );
				$this->assertSame( 'USD', $node['offers']['priceCurrency'] );
				$this->assertSame( 'https://example.com/about/', $node['offers']['url'] );
				$this->assertSame( 'https://schema.org/InStock', $node['offers']['availability'] );
				return;
			}
		}

		$this->fail( 'No Product node found.' );
	}
}
