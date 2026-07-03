<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Schema\SchemaGraph;
use TheAnother\Plugin\SEO\Schema\SchemaOutput;

#[CoversClass( SchemaOutput::class )]
class SchemaOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $graph;
	private SchemaOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->graph = Mockery::mock( SchemaGraph::class );

		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, $flags = 0 ) => json_encode( $data, $flags )
		);

		$this->output = new SchemaOutput( $this->graph );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_script_tag_in_node_name_cannot_break_out_of_script_element(): void {
		$this->graph->shouldReceive( 'build' )->andReturn(
			array(
				array(
					'@type' => 'WebPage',
					'@id'   => 'https://example.com/#webpage',
					'name'  => 'Evil</script><script>alert(1)</script>',
				),
			)
		);

		ob_start();
		$this->output->print_json_ld();
		$html = ob_get_clean();

		// Exactly one literal </script> may remain: the wrapper's own closing
		// tag. Any occurrence coming from node data must be hex-escaped, so
		// it never reaches the page as a raw angle bracket.
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertStringNotContainsString( '</script><script>', $html );

		// Evidence the angle brackets from the node's name were hex-escaped
		// by JSON_HEX_TAG into \u003C / \u003E rather than passed through raw.
		$this->assertStringContainsString( '\u003C', $html );
		$this->assertStringContainsString( '\u003E', $html );
	}

	public function test_empty_graph_prints_nothing(): void {
		$this->graph->shouldReceive( 'build' )->andReturn( array() );

		ob_start();
		$this->output->print_json_ld();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
