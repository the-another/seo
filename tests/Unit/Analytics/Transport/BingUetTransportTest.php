<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics\Transport;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagSlice;
use TheAnother\Plugin\SEO\Analytics\Transport\BingUetTransport;

#[CoversClass( BingUetTransport::class )]
class BingUetTransportTest extends TestCase {
	use MockeryPHPUnitIntegration;
	use RendersScriptTags;

	private BingUetTransport $transport;

	private TagRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transport = new BingUetTransport();
		$this->registry  = new TagRegistry();

		$this->stub_script_tags();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Capture one emit call.
	 *
	 * @param string                            $method emit_primary|emit_noscript.
	 * @param array<string, array<int, string>> $tags   Vendor key => IDs.
	 * @return string Output.
	 */
	private function emit( string $method, array $tags ): string {
		$slices = array();

		foreach ( $tags as $key => $ids ) {
			$slices[] = new TagSlice( $this->registry->get( $key ), $ids );
		}

		ob_start();
		$this->transport->$method( $slices, $this->inactive_consent() );

		return (string) ob_get_clean();
	}

	public function test_prints_the_uet_snippet_with_the_tag_id(): void {
		$head = $this->emit( 'emit_primary', array( 'bing_uet' => array( '12345678' ) ) );

		$this->assertStringContainsString( 'ti:"12345678"', $head );
		$this->assertStringContainsString( 'new UET(o)', $head );
		$this->assertStringContainsString( '"pageLoad"', $head );
	}

	/**
	 * Microsoft publishes the loader protocol-relative. This plugin serves no
	 * HTTP pages, so the scheme is explicit — a protocol-relative URL is a
	 * downgrade path and nothing more here.
	 */
	public function test_loads_bat_js_over_https(): void {
		$head = $this->emit( 'emit_primary', array( 'bing_uet' => array( '12345678' ) ) );

		$this->assertStringContainsString( 'https://bat.bing.com/bat.js', $head );
		$this->assertStringNotContainsString( '"//bat.bing.com', $head );
	}

	public function test_prints_nothing_without_a_tag_id(): void {
		$this->assertSame( '', $this->emit( 'emit_primary', array() ) );
	}

	/**
	 * The official snippet assigns w[u] = new UET(o), so a second copy sharing
	 * the uetq variable would clobber the first tag's queue. Microsoft's own
	 * multi-tag guidance is a distinct variable name per tag.
	 */
	public function test_a_second_id_gets_its_own_queue_variable(): void {
		$head = $this->emit( 'emit_primary', array( 'bing_uet' => array( '11111111', '22222222' ) ) );

		$this->assertSame( 2, substr_count( $head, 'new UET(o)' ) );
		$this->assertStringContainsString( '"uetq"', $head );
		$this->assertStringContainsString( '"uetq_22222222"', $head );
		$this->assertStringContainsString( 'ti:"11111111"', $head );
		$this->assertStringContainsString( 'ti:"22222222"', $head );
	}

	public function test_emit_noscript_prints_nothing(): void {
		$this->assertSame(
			'',
			$this->emit( 'emit_noscript', array( 'bing_uet' => array( '12345678' ) ) )
		);
	}

	public function test_the_loader_is_inert_while_consent_mode_is_active(): void {
		$slices = array( new TagSlice( $this->registry->get( 'bing_uet' ), array( '12345678' ) ) );

		ob_start();
		$this->transport->emit_primary( $slices, $this->active_consent() );
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="text/plain"', $head );
		$this->assertStringContainsString( 'data-taseo-consent="marketing"', $head );
		$this->assertStringContainsString( '12345678', $head, 'The snippet bytes are unchanged; only the wrapper blocks them.' );
	}

	public function test_the_loader_is_live_while_consent_mode_is_inactive(): void {
		$slices = array( new TagSlice( $this->registry->get( 'bing_uet' ), array( '12345678' ) ) );

		ob_start();
		$this->transport->emit_primary( $slices, $this->inactive_consent() );
		$head = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'text/plain', $head );
		$this->assertStringNotContainsString( 'data-taseo-consent', $head );
	}
}
