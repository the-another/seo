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
use TheAnother\Plugin\SEO\Analytics\Transport\GtmTransport;

#[CoversClass( GtmTransport::class )]
class GtmTransportTest extends TestCase {
	use MockeryPHPUnitIntegration;
	use RendersScriptTags;

	private GtmTransport $transport;

	private TagRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transport = new GtmTransport();
		$this->registry  = new TagRegistry();

		Functions\when( 'esc_url' )->returnArg();
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

	public function test_prints_the_head_loader_and_body_noscript(): void {
		$head = $this->emit( 'emit_primary', array( 'gtm' => array( 'GTM-XYZ789' ) ) );
		$body = $this->emit( 'emit_noscript', array( 'gtm' => array( 'GTM-XYZ789' ) ) );

		$this->assertStringContainsString( 'GTM-XYZ789', $head );
		$this->assertStringContainsString( 'googletagmanager.com/gtm.js', $head );
		$this->assertStringContainsString( 'googletagmanager.com/ns.html?id=GTM-XYZ789', $body );
		$this->assertStringContainsString( '<noscript>', $body );
	}

	/**
	 * The literal the e2e suite pins. A browser parses <noscript> content as
	 * inert raw text when scripting is enabled, so no DOM locator can see this
	 * markup — asserting the exact string is the only way to exercise it.
	 */
	public function test_the_noscript_iframe_markup_is_exact(): void {
		$this->assertSame(
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XYZ789" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
			$this->emit( 'emit_noscript', array( 'gtm' => array( 'GTM-XYZ789' ) ) )
		);
	}

	public function test_prints_nothing_without_a_container_id(): void {
		$this->assertSame( '', $this->emit( 'emit_primary', array() ) );
		$this->assertSame( '', $this->emit( 'emit_noscript', array() ) );
	}

	public function test_prints_one_loader_and_one_iframe_per_id(): void {
		$tags = array( 'gtm' => array( 'GTM-FIRST11', 'GTM-SECOND2' ) );

		$head = $this->emit( 'emit_primary', $tags );
		$body = $this->emit( 'emit_noscript', $tags );

		$this->assertSame( 2, substr_count( $head, 'gtm.js?id=' ) );
		$this->assertStringContainsString( 'GTM-FIRST11', $head );
		$this->assertStringContainsString( 'GTM-SECOND2', $head );
		$this->assertSame( 2, substr_count( $body, '<noscript>' ) );
	}

	public function test_the_loader_is_inert_while_consent_mode_is_active(): void {
		$slices = array( new TagSlice( $this->registry->get( 'gtm' ), array( 'GTM-XYZ789' ) ) );

		ob_start();
		$this->transport->emit_primary( $slices, $this->active_consent() );
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="text/plain"', $head );
		$this->assertStringContainsString( 'data-taseo-consent="analytics"', $head );
		$this->assertStringContainsString( 'GTM-XYZ789', $head, 'The snippet bytes are unchanged; only the wrapper blocks them.' );
	}

	public function test_the_loader_is_live_while_consent_mode_is_inactive(): void {
		$slices = array( new TagSlice( $this->registry->get( 'gtm' ), array( 'GTM-XYZ789' ) ) );

		ob_start();
		$this->transport->emit_primary( $slices, $this->inactive_consent() );
		$head = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'text/plain', $head );
		$this->assertStringNotContainsString( 'data-taseo-consent', $head );
	}
}
