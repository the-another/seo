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
use TheAnother\Plugin\SEO\Analytics\TagOutput;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagResolver;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * The registry, resolver, transports and output wired together, exercised the
 * way a request does.
 *
 * This is the guard on the one requirement no single unit test can hold: with
 * nothing subscribed to the new filter, every byte this plugin emits for GA4,
 * Tag Manager and Meta Pixel is what it emitted before the registry existed.
 * The two literal <noscript> assertions below are the same strings the e2e
 * suite pins in tests/e2e/functional/specs/webmaster.spec.ts.
 */
#[CoversClass( TagOutput::class )]
#[CoversClass( TagResolver::class )]
class TagPipelineTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private TagOutput $output;
	private HookManager $hooks;

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
		$this->settings->shouldReceive( 'get_tracking_id' )->andReturn( '' )->byDefault();

		$domains = Mockery::mock( DomainRegistry::class );
		$domains->shouldReceive( 'get_current_host' )->andReturn( 'example.com' )->byDefault();

		$registry     = new TagRegistry();
		$this->output = new TagOutput( $registry, new TagResolver( $registry, $this->settings, $domains ) );
		$this->hooks  = new HookManager();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
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

		$this->output->init( $this->hooks );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Seed one vendor's stored ID.
	 *
	 * @param string $settings_key Settings key.
	 * @param string $value        Stored ID.
	 * @return void
	 */
	private function store( string $settings_key, string $value ): void {
		$this->settings->shouldReceive( 'get_tracking_id' )
			->with( $settings_key, 'example.com' )
			->andReturn( $value );
	}

	/**
	 * Fire one hook at one priority and return what it printed.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Priority.
	 * @return string Output.
	 */
	private function fire( string $hook, int $priority ): string {
		ob_start();

		foreach ( $this->hooks->get_registered_hooks() as $registered ) {
			if ( $registered['hook'] === $hook && $registered['priority'] === $priority ) {
				call_user_func( $registered['callback'] );
			}
		}

		return (string) ob_get_clean();
	}

	/**
	 * Fire every registered hook, in registration order, and return the
	 * concatenated output.
	 *
	 * @return string Output.
	 */
	private function fire_all(): string {
		ob_start();

		foreach ( $this->hooks->get_registered_hooks() as $registered ) {
			call_user_func( $registered['callback'] );
		}

		return (string) ob_get_clean();
	}

	public function test_ga4_enqueues_the_loader_and_config_exactly_as_before(): void {
		$this->store( 'analytics_ga4_id', 'G-E2E12345' );

		$this->fire( 'wp_enqueue_scripts', 10 );

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-E2E12345',
			$this->enqueued['taseo-gtag']
		);
		$this->assertSame(
			"window.dataLayer = window.dataLayer || [];\n"
			. "function gtag(){dataLayer.push(arguments);}\n"
			. "gtag('js', new Date());\n"
			. "gtag('config', 'G-E2E12345');\n",
			$this->inline['taseo-gtag']
		);
	}

	public function test_gtm_emits_the_pinned_noscript_iframe(): void {
		$this->store( 'analytics_gtm_id', 'GTM-E2E1234' );

		$this->assertStringContainsString(
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-E2E1234" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
			$this->fire( 'wp_body_open', 10 )
		);
	}

	public function test_meta_pixel_emits_the_pinned_noscript_image(): void {
		$this->store( 'meta_pixel_id', '123456789012345' );

		$this->assertStringContainsString(
			'<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1" /></noscript>',
			$this->fire( 'wp_body_open', 10 )
		);
	}

	public function test_a_fully_configured_site_emits_every_vendor_once(): void {
		$this->store( 'analytics_ga4_id', 'G-E2E12345' );
		$this->store( 'analytics_gtm_id', 'GTM-E2E1234' );
		$this->store( 'meta_pixel_id', '123456789012345' );
		$this->store( 'google_ads_id', 'AW-123456789' );
		$this->store( 'bing_uet_id', '12345678' );

		$html = $this->fire_all();

		$this->assertSame( 1, substr_count( $html, 'gtm.js?id=' ) );
		$this->assertSame( 1, substr_count( $html, 'fbevents.js' ) );
		$this->assertSame( 1, substr_count( $html, 'bat.bing.com/bat.js' ) );
		$this->assertSame( 1, substr_count( $html, 'googletagmanager.com/ns.html' ) );
		$this->assertSame( 1, substr_count( $html, 'facebook.com/tr' ) );

		// One loader for two gtag vendors, and a config line for each.
		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( 2, substr_count( $this->inline['taseo-gtag'], "gtag('config'" ) );
	}

	public function test_the_override_filter_can_silence_a_fully_configured_site(): void {
		$this->store( 'analytics_ga4_id', 'G-E2E12345' );
		$this->store( 'analytics_gtm_id', 'GTM-E2E1234' );
		$this->store( 'meta_pixel_id', '123456789012345' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn( array() );

		$this->assertSame( '', $this->fire_all() );
		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->inline );
	}

	public function test_the_override_filter_replaces_the_whole_set(): void {
		$this->store( 'analytics_ga4_id', 'G-E2E12345' );
		$this->store( 'meta_pixel_id', '123456789012345' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array( 'ga4' => array( 'G-PARTNER1' ) )
		);

		$html = $this->fire_all();

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-PARTNER1',
			$this->enqueued['taseo-gtag']
		);
		$this->assertStringNotContainsString( 'facebook.com', $html );
		$this->assertStringNotContainsString( 'G-E2E12345', $this->inline['taseo-gtag'] );
	}

	/**
	 * One resolution per request, shared by every hook: the head snippet and its
	 * <noscript> half can never disagree, however non-deterministic a
	 * subscriber is.
	 */
	public function test_the_whole_request_resolves_once(): void {
		$this->store( 'analytics_gtm_id', 'GTM-E2E1234' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once();
		Filters\expectApplied( 'taseo_analytics_gtm_ids' )->once();
		Filters\expectApplied( 'taseo_tracking_should_print' )->once();

		$this->fire_all();
	}
}
