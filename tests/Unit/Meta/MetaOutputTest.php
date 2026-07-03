<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;

#[CoversClass( MetaOutput::class )]
#[CoversClass( CurrentContext::class )]
class MetaOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $context;
	private MetaOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->context = Mockery::mock( CurrentContext::class );
		$this->output  = new MetaOutput( $this->context, new TemplateResolver() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: a resolved context for a product with optional row overrides.
	 */
	private function product_context( ?array $row, array $vars = array() ): array {
		return array(
			'object_type'    => 'post',
			'object_subtype' => 'product',
			'object_id'      => 88123,
			'row'            => $row,
			'vars'           => array_merge(
				array(
					'title'    => 'Vintage Watch',
					'sitename' => 'Acme Auctions',
					'sep'      => '–',
					'excerpt'  => 'A rare vintage watch.',
				),
				$vars
			),
			'permalink'      => 'https://example.com/product/vintage-watch/',
			'title_template'       => '%%title%% %%sep%% %%sitename%%',
			'description_template' => '%%excerpt%%',
		);
	}

	public function test_title_uses_row_override_when_set(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->product_context( array( 'title' => 'Hand-tuned Title', 'description' => null ) )
		);

		$this->assertSame( 'Hand-tuned Title', $this->output->filter_document_title( 'WP Default' ) );
	}

	public function test_title_resolves_template_when_no_override(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->product_context( null ) );

		$this->assertSame( 'Vintage Watch – Acme Auctions', $this->output->filter_document_title( 'WP Default' ) );
	}

	public function test_title_passes_through_when_context_unmanaged(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		$this->assertSame( 'WP Default', $this->output->filter_document_title( 'WP Default' ) );
	}

	public function test_title_is_escaped(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->product_context( array( 'title' => 'A <b>bold</b> & risky title', 'description' => null ) )
		);

		$result = $this->output->filter_document_title( 'WP Default' );

		// Verify that HTML entities are escaped.
		$this->assertStringContainsString( '&lt;b&gt;', $result );
		$this->assertStringContainsString( '&lt;/b&gt;', $result );
		$this->assertStringContainsString( '&amp;', $result );
		// Raw angle brackets should be escaped, not present as-is.
		$this->assertStringNotContainsString( '<b>', $result );
		$this->assertStringNotContainsString( '</b>', $result );
	}

	public function test_head_tags_print_description_canonical_from_live_permalink(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->product_context( null ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		ob_start();
		$this->output->print_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="A rare vintage watch." />', $html );
		$this->assertStringContainsString( '<link rel="canonical" href="https://example.com/product/vintage-watch/" />', $html );
		$this->assertStringNotContainsString( 'robots', $html );
	}

	public function test_head_tags_use_canonical_override_and_robots_flags(): void {
		$row = array(
			'title'            => null,
			'description'      => null,
			'canonical_url'    => 'https://example.com/preferred/',
			'robots_noindex'   => '1',
			'robots_nofollow'  => '1',
			'robots_noarchive' => null,
		);
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->product_context( $row ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		ob_start();
		$this->output->print_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<link rel="canonical" href="https://example.com/preferred/" />', $html );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" />', $html );
	}

	public function test_head_tags_print_nothing_when_context_unmanaged(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		ob_start();
		$this->output->print_head_tags();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_description_override_wins_over_template(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->product_context( array( 'title' => null, 'description' => 'Hand-written description.' ) )
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		ob_start();
		$this->output->print_head_tags();

		$this->assertStringContainsString( 'content="Hand-written description."', ob_get_clean() );
	}
}
