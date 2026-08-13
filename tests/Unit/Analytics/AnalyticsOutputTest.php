<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\AnalyticsOutput;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( AnalyticsOutput::class )]
class AnalyticsOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $domains;
	private AnalyticsOutput $output;

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

		$this->enqueued = array();
		$this->inline   = array();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( '' )->byDefault();
		$this->settings->shouldReceive( 'get_gtm_id' )->andReturn( '' )->byDefault();

		$this->domains = Mockery::mock( DomainRegistry::class );
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'example.com' )->byDefault();

		$this->output = new AnalyticsOutput( $this->settings, $this->domains );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $js ): void {
				echo '<script>' . $js . '</script>';
			}
		);
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueues_gtag_with_the_measurement_id(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );

		$this->output->enqueue_gtag();

		$this->assertArrayHasKey( 'taseo-gtag', $this->enqueued );
		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag']
		);
		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( 'window.dataLayer', $this->inline['taseo-gtag'] );
	}

	public function test_enqueues_nothing_without_a_measurement_id(): void {
		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_ga4_ids_filter_adds_a_secondary_property(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-PRIMARY1' );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->once()->andReturnUsing(
			static function ( array $ids ): array {
				$ids[] = 'G-SECOND22';

				return $ids;
			}
		);

		$this->output->enqueue_gtag();

		$this->assertStringContainsString( "gtag('config', 'G-PRIMARY1')", $this->inline['taseo-gtag'] );
		$this->assertStringContainsString( "gtag('config', 'G-SECOND22')", $this->inline['taseo-gtag'] );
		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-PRIMARY1',
			$this->enqueued['taseo-gtag'],
			'The loader uses the first ID; the rest are config calls.'
		);
	}

	public function test_ga4_ids_filter_values_are_revalidated_and_deduplicated(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-PRIMARY1' );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->once()->andReturn(
			array( 'G-PRIMARY1', 'G-PRIMARY1', 'not-an-id', '"><script>' )
		);

		$this->output->enqueue_gtag();

		$this->assertSame( 1, substr_count( $this->inline['taseo-gtag'], "gtag('config'" ) );
		$this->assertStringNotContainsString( 'not-an-id', $this->inline['taseo-gtag'] );
		$this->assertStringNotContainsString( '<script>', $this->inline['taseo-gtag'] );
	}

	public function test_gtag_config_filter_adds_per_property_parameters(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_analytics_gtag_config' )->once()->andReturn(
			array( 'G-ABCD1234' => array( 'send_page_view' => false ) )
		);

		$this->output->enqueue_gtag();

		$this->assertStringContainsString(
			'gtag(\'config\', \'G-ABCD1234\', {"send_page_view":false})',
			$this->inline['taseo-gtag']
		);
	}

	public function test_gtag_config_filter_returning_an_unencodable_value_falls_back_to_no_parameters(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_analytics_gtag_config' )->once()->andReturn(
			array( 'G-ABCD1234' => array( 'bad' => NAN ) )
		);

		$this->output->enqueue_gtag();

		$js = $this->inline['taseo-gtag'];

		$this->assertStringContainsString( "gtag('config', 'G-ABCD1234');\n", $js );
		$this->assertStringNotContainsString( ', )', $js );
		$this->assertDoesNotMatchRegularExpression( '/,\s*\)/', $js, 'No dangling comma before a closing paren.' );
	}

	public function test_prints_the_gtm_head_loader_and_body_noscript(): void {
		$this->settings->shouldReceive( 'get_gtm_id' )->andReturn( 'GTM-XYZ789' );

		ob_start();
		$this->output->print_gtm_head();
		$head = (string) ob_get_clean();

		ob_start();
		$this->output->print_gtm_body();
		$body = (string) ob_get_clean();

		$this->assertStringContainsString( 'GTM-XYZ789', $head );
		$this->assertStringContainsString( 'googletagmanager.com/gtm.js', $head );
		$this->assertStringContainsString( 'googletagmanager.com/ns.html?id=GTM-XYZ789', $body );
		$this->assertStringContainsString( '<noscript>', $body );
	}

	public function test_prints_no_gtm_output_without_a_container_id(): void {
		ob_start();
		$this->output->print_gtm_head();
		$this->output->print_gtm_body();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_prints_nothing_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );

		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_analytics_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_analytics_should_print' )->once()->andReturn( false );

		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_tracking_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_ga4_id' )->andReturn( 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_tracking_should_print' )->once()->andReturn( false );

		$this->output->enqueue_gtag();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_enqueues_gtag_with_the_requested_domains_measurement_id(): void {
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'brandtwo.com' );

		$this->settings->shouldReceive( 'get_ga4_id' )->with( 'brandtwo.com' )->andReturn( 'G-BRANDTWO' );

		$this->output->enqueue_gtag();

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-BRANDTWO',
			$this->enqueued['taseo-gtag']
		);
		$this->assertStringContainsString( "gtag('config', 'G-BRANDTWO')", $this->inline['taseo-gtag'] );
	}
}
