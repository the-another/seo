<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics\Transport;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagSlice;
use TheAnother\Plugin\SEO\Analytics\Transport\GtagTransport;

#[CoversClass( GtagTransport::class )]
class GtagTransportTest extends TestCase {
	use MockeryPHPUnitIntegration;
	use RendersScriptTags;

	private GtagTransport $transport;

	private TagRegistry $registry;

	/**
	 * Enqueued scripts: handle => src.
	 *
	 * @var array<string, string>
	 */
	private array $enqueued = array();

	/**
	 * Inline scripts: handle => concatenated JS.
	 *
	 * @var array<string, string>
	 */
	private array $inline = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->enqueued  = array();
		$this->inline    = array();
		$this->transport = new GtagTransport();
		$this->registry  = new TagRegistry();

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '' ): void {
				$this->enqueued[ $handle ] = $src;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( string $handle, string $js ): void {
				$this->inline[ $handle ] = ( $this->inline[ $handle ] ?? '' ) . $js;
			}
		);
		$this->stub_script_tags();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build slices from a vendor key => IDs map, in the shape the tests used
	 * before slices existed.
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => IDs.
	 * @return array<int, TagSlice> Slices.
	 */
	private function slices( array $tags ): array {
		$slices = array();

		foreach ( $tags as $key => $ids ) {
			$slices[] = new TagSlice( $this->registry->get( $key ), $ids );
		}

		return $slices;
	}

	public function test_slices_carry_the_vendor_declaration_alongside_its_ids(): void {
		$registry = new TagRegistry();

		$this->transport->emit_primary(
			array( new TagSlice( $registry->get( 'ga4' ), array( 'G-ABCD1234' ) ) ),
			$this->inactive_consent()
		);

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag']
		);
	}

	public function test_enqueues_gtag_with_the_measurement_id(): void {
		$this->transport->emit_primary( $this->slices( array( 'ga4' => array( 'G-ABCD1234' ) ) ), $this->inactive_consent() );

		$this->assertArrayHasKey( 'taseo-gtag', $this->enqueued );
		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag']
		);
		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( 'window.dataLayer', $this->inline['taseo-gtag'] );
	}

	public function test_enqueues_nothing_without_an_id(): void {
		$this->transport->emit_primary( array(), $this->inactive_consent() );

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_loader_uses_the_first_id_and_the_rest_are_config_calls(): void {
		$this->transport->emit_primary(
			$this->slices( array( 'ga4' => array( 'G-PRIMARY1', 'G-SECOND22' ) ) ),
			$this->inactive_consent()
		);

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-PRIMARY1',
			$this->enqueued['taseo-gtag'],
			'The loader uses the first ID; the rest are config calls.'
		);
		$this->assertStringContainsString( "gtag('config', 'G-PRIMARY1')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( "gtag('config', 'G-SECOND22')", $this->inline['taseo-gtag'] );
	}

	/**
	 * Google Ads is gtag.js too: one loader, one dataLayer, one config line per
	 * ID. Two loaders would be two copies of the same library — the reason the
	 * two vendors share a transport rather than rendering independently.
	 */
	public function test_google_ads_shares_one_loader_and_bootstrap_with_ga4(): void {
		$this->transport->emit_primary(
			$this->slices(
				array(
					'ga4'        => array( 'G-ABCD1234' ),
					'google_ads' => array( 'AW-123456789' ),
				)
			),
			$this->inactive_consent()
		);

		$js = $this->inline['taseo-gtag'];

		$this->assertSame( 1, substr_count( $js, 'window.dataLayer = window.dataLayer || [];' ) );
		$this->assertSame( 1, substr_count( $js, "gtag('js', new Date())" ) );
		$this->assertSame( 2, substr_count( $js, "gtag('config'" ) );
		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234')", $js );
		$this->assertStringContainsString( "gtag('config', 'AW-123456789')", $js );
		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag'],
			'Registry order puts GA4 first, so the loader keeps the measurement ID it uses today.'
		);
	}

	public function test_google_ads_alone_drives_the_loader(): void {
		$this->transport->emit_primary(
			$this->slices( array( 'google_ads' => array( 'AW-123456789' ) ) ),
			$this->inactive_consent()
		);

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=AW-123456789',
			$this->enqueued['taseo-gtag']
		);
	}

	public function test_gtag_config_filter_adds_per_property_parameters(): void {
		Filters\expectApplied( 'taseo_analytics_gtag_config' )->once()->andReturn(
			array( 'G-ABCD1234' => array( 'send_page_view' => false ) )
		);

		$this->transport->emit_primary( $this->slices( array( 'ga4' => array( 'G-ABCD1234' ) ) ), $this->inactive_consent() );

		$this->assertStringContainsString(
			'gtag(\'config\', \'G-ABCD1234\', {"send_page_view":false})',
			$this->inline['taseo-gtag']
		);
	}

	public function test_gtag_config_filter_returning_an_unencodable_value_falls_back_to_no_parameters(): void {
		Filters\expectApplied( 'taseo_analytics_gtag_config' )->once()->andReturn(
			array( 'G-ABCD1234' => array( 'bad' => NAN ) )
		);

		$this->transport->emit_primary( $this->slices( array( 'ga4' => array( 'G-ABCD1234' ) ) ), $this->inactive_consent() );

		$js = $this->inline['taseo-gtag'];

		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234');\n", $js );
		$this->assertStringNotContainsString( ', )', $js );
		$this->assertDoesNotMatchRegularExpression( '/,\s*\)/', $js, 'No dangling comma before a closing paren.' );
	}

	public function test_a_non_array_config_filter_return_is_ignored(): void {
		Filters\expectApplied( 'taseo_analytics_gtag_config' )->once()->andReturn( 'not-an-array' );

		$this->transport->emit_primary( $this->slices( array( 'ga4' => array( 'G-ABCD1234' ) ) ), $this->inactive_consent() );

		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234');\n", $this->inline['taseo-gtag'] );
	}

	public function test_blocked_gtag_emits_one_grouped_loader_per_category(): void {
		$registry = new TagRegistry();
		$slices   = array(
			new TagSlice( $registry->get( 'ga4' ), array( 'G-ABCD1234' ) ),
			new TagSlice( $registry->get( 'google_ads' ), array( 'AW-123456789' ) ),
		);

		ob_start();
		$this->transport->emit_primary( $slices, $this->active_consent() );
		$head = (string) ob_get_clean();

		$this->assertSame( array(), $this->enqueued, 'Nothing goes through the script queue while blocked.' );
		$this->assertStringContainsString(
			'<script src="https://www.googletagmanager.com/gtag/js?id=G-ABCD1234" type="text/plain" data-taseo-consent="analytics" data-taseo-consent-group="gtag">',
			$head
		);
		$this->assertStringContainsString(
			'<script src="https://www.googletagmanager.com/gtag/js?id=AW-123456789" type="text/plain" data-taseo-consent="marketing" data-taseo-consent-group="gtag">',
			$head
		);
	}

	public function test_blocked_gtag_splits_the_config_lines_by_category(): void {
		$registry = new TagRegistry();
		$slices   = array(
			new TagSlice( $registry->get( 'ga4' ), array( 'G-ABCD1234' ) ),
			new TagSlice( $registry->get( 'google_ads' ), array( 'AW-123456789' ) ),
		);

		ob_start();
		$this->transport->emit_primary( $slices, $this->active_consent() );
		$head = (string) ob_get_clean();

		$this->assertStringContainsString(
			'<script type="text/plain" data-taseo-consent="analytics">' . "gtag('config', 'G-ABCD1234');\n" . '</script>',
			$head
		);
		$this->assertStringContainsString(
			'<script type="text/plain" data-taseo-consent="marketing">' . "gtag('config', 'AW-123456789');\n" . '</script>',
			$head
		);
	}

	public function test_the_blocked_bootstrap_activates_on_either_category(): void {
		$registry = new TagRegistry();
		$slices   = array(
			new TagSlice( $registry->get( 'ga4' ), array( 'G-ABCD1234' ) ),
			new TagSlice( $registry->get( 'google_ads' ), array( 'AW-123456789' ) ),
		);

		ob_start();
		$this->transport->emit_primary( $slices, $this->active_consent() );
		$head = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<script type="text\/plain" data-taseo-consent="analytics marketing">window\.dataLayer/',
			$head
		);
	}

	public function test_a_marketing_only_site_never_emits_a_loader_carrying_the_ga4_id(): void {
		$registry = new TagRegistry();
		$slices   = array( new TagSlice( $registry->get( 'google_ads' ), array( 'AW-123456789' ) ) );

		ob_start();
		$this->transport->emit_primary( $slices, $this->active_consent() );
		$head = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'id=G-', $head );
		$this->assertStringContainsString( 'id=AW-123456789', $head );
	}

	public function test_the_live_path_is_unchanged_when_consent_mode_is_inactive(): void {
		$registry = new TagRegistry();
		$slices   = array(
			new TagSlice( $registry->get( 'ga4' ), array( 'G-ABCD1234' ) ),
			new TagSlice( $registry->get( 'google_ads' ), array( 'AW-123456789' ) ),
		);

		$this->transport->emit_primary( $slices, $this->inactive_consent() );

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag'],
			'One loader, the first ID, exactly as 1.5.0 emitted it.'
		);
		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( "gtag('config', 'AW-123456789')", $this->inline['taseo-gtag'] );
	}

	public function test_emit_noscript_prints_nothing(): void {
		ob_start();
		$this->transport->emit_noscript( $this->slices( array( 'ga4' => array( 'G-ABCD1234' ) ) ), $this->inactive_consent() );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
