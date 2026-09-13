<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\TagOutput;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagResolver;
use TheAnother\Plugin\SEO\HookManager;

#[CoversClass( TagOutput::class )]
class TagOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $resolver;
	private TagOutput $output;
	private HookManager $hooks;

	/**
	 * Enqueued scripts: handle => src.
	 *
	 * @var array<string, string>
	 */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->enqueued = array();

		$this->resolver = Mockery::mock( TagResolver::class );
		$this->resolver->shouldReceive( 'resolve' )->andReturn( array() )->byDefault();

		$this->output = new TagOutput( new TagRegistry(), $this->resolver );
		$this->hooks  = new HookManager();

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
		Functions\when( 'wp_add_inline_script' )->justReturn( true );

		$this->output->init( $this->hooks );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Registered (hook, priority) pairs in registration order.
	 *
	 * @return array<int, string> e.g. 'wp_head:1'.
	 */
	private function registrations(): array {
		return array_map(
			static fn( array $hook ): string => $hook['hook'] . ':' . $hook['priority'],
			$this->hooks->get_registered_hooks()
		);
	}

	/**
	 * Run every callback registered for one hook and priority, and return what
	 * it printed.
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
	 * The hooks and priorities the plugin emits from today: gtag through the
	 * script queue, the container at wp_head:1, the pixel at wp_head:2, and
	 * both <noscript> halves at wp_body_open. Bing UET is new, at wp_head:3.
	 */
	public function test_registers_one_callback_per_transport_placement_and_priority(): void {
		$this->assertSame(
			array(
				'wp_enqueue_scripts:10',
				'wp_head:1',
				'wp_head:2',
				'wp_head:3',
				'wp_body_open:10',
				'wp_body_open:10',
			),
			$this->registrations()
		);
	}

	/**
	 * init() is called once by Plugin::start(), but HookManager's own
	 * duplicate guard cannot catch a second call here — it compares
	 * callbacks for equality, and every callback registered above is a
	 * freshly created closure, which never equals another one. TagOutput
	 * has to guard itself, or a second call would double every
	 * registration and emit every tag twice.
	 */
	public function test_calling_init_twice_still_registers_six_callbacks(): void {
		$this->output->init( $this->hooks );

		$this->assertCount( 6, $this->registrations() );
	}

	public function test_ga4_and_google_ads_register_a_single_script_queue_callback(): void {
		$queue = array_filter(
			$this->registrations(),
			static fn( string $registration ): bool => 'wp_enqueue_scripts:10' === $registration
		);

		$this->assertCount( 1, $queue );
	}

	/**
	 * Ordering at wp_body_open is by registration, and today's order comes from
	 * gtm being declared before meta_pixel in TagRegistry: the container's
	 * iframe prints before the pixel's image.
	 */
	public function test_the_body_open_halves_print_gtm_before_meta_pixel(): void {
		$this->resolver->shouldReceive( 'resolve' )->andReturn(
			array(
				'gtm'        => array( 'GTM-XYZ789' ),
				'meta_pixel' => array( '123456789012345' ),
			)
		);

		$body = $this->fire( 'wp_body_open', 10 );

		$this->assertLessThan(
			strpos( $body, 'facebook.com/tr' ),
			strpos( $body, 'googletagmanager.com/ns.html' )
		);
	}

	public function test_a_transport_receives_only_its_own_vendor_keys(): void {
		$this->resolver->shouldReceive( 'resolve' )->andReturn(
			array(
				'gtm'        => array( 'GTM-XYZ789' ),
				'meta_pixel' => array( '123456789012345' ),
				'bing_uet'   => array( '12345678' ),
			)
		);

		$head = $this->fire( 'wp_head', 1 );

		$this->assertStringContainsString( 'GTM-XYZ789', $head );
		$this->assertStringNotContainsString( '123456789012345', $head );
		$this->assertStringNotContainsString( 'bat.bing.com', $head );
	}

	/**
	 * An emptied collection emits nothing at all — no wrapper, no empty script
	 * tag, no blank line.
	 */
	public function test_an_empty_collection_emits_nothing_anywhere(): void {
		$this->assertSame( '', $this->fire( 'wp_head', 1 ) );
		$this->assertSame( '', $this->fire( 'wp_head', 2 ) );
		$this->assertSame( '', $this->fire( 'wp_head', 3 ) );
		$this->assertSame( '', $this->fire( 'wp_body_open', 10 ) );
		$this->assertSame( '', $this->fire( 'wp_enqueue_scripts', 10 ) );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_script_queue_callback_enqueues_gtag(): void {
		$this->resolver->shouldReceive( 'resolve' )->andReturn( array( 'ga4' => array( 'G-ABCD1234' ) ) );

		$this->fire( 'wp_enqueue_scripts', 10 );

		$this->assertSame(
			'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
			$this->enqueued['taseo-gtag']
		);
	}
}
