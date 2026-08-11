<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Schema;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Schema\SchemaGraph;
use TheAnother\Plugin\SEO\Schema\WooCommerceDeduplication;

#[CoversClass( WooCommerceDeduplication::class )]
class WooCommerceDeduplicationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $graph;
	private WooCommerceDeduplication $dedupe;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->graph  = Mockery::mock( SchemaGraph::class );
		$this->dedupe = new WooCommerceDeduplication( $this->graph );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function wc_product_markup(): array {
		return array( '@type' => 'Product', 'name' => 'A widget' );
	}

	public function test_woocommerce_product_is_suppressed_when_we_emit_one(): void {
		$this->graph->shouldReceive( 'has_node_of_type' )
			->with( array( 'Product', 'Article' ) )
			->andReturn( true );

		$this->assertSame( array(), $this->dedupe->filter_product( $this->wc_product_markup() ) );
	}

	/**
	 * An admin who set a subtype's schema type to Article chose that over
	 * Product; letting WooCommerce put a Product back would overturn it.
	 */
	public function test_an_article_main_entity_also_suppresses_the_product(): void {
		$this->graph->shouldReceive( 'has_node_of_type' )
			->with( Mockery::on( static fn( array $t ): bool => in_array( 'Article', $t, true ) ) )
			->andReturn( true );

		$this->assertSame( array(), $this->dedupe->filter_product( $this->wc_product_markup() ) );
	}

	/**
	 * Schema off for the subtype, or schema_disabled on the row: suppressing
	 * here would strip structured data and put nothing in its place.
	 */
	public function test_woocommerce_product_survives_when_we_emit_nothing(): void {
		$this->graph->shouldReceive( 'has_node_of_type' )->andReturn( false );

		$markup = $this->wc_product_markup();

		$this->assertSame( $markup, $this->dedupe->filter_product( $markup ) );
	}

	public function test_breadcrumbs_are_suppressed_only_when_we_emit_them(): void {
		$this->graph->shouldReceive( 'has_node_of_type' )
			->with( array( 'BreadcrumbList' ) )
			->andReturn( true, false );

		$markup = array( '@type' => 'BreadcrumbList', 'itemListElement' => array() );

		$this->assertSame( array(), $this->dedupe->filter_breadcrumblist( $markup ) );
		$this->assertSame( $markup, $this->dedupe->filter_breadcrumblist( $markup ) );
	}

	public function test_a_non_array_from_another_filter_passes_through_untouched(): void {
		$this->assertNull( $this->dedupe->filter_product( null ) );
		$this->assertSame( 'nonsense', $this->dedupe->filter_breadcrumblist( 'nonsense' ) );
	}
}
