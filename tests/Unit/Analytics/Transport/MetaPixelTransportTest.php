<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics\Transport;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagSlice;
use TheAnother\Plugin\SEO\Analytics\Transport\MetaPixelTransport;

#[CoversClass( MetaPixelTransport::class )]
class MetaPixelTransportTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private MetaPixelTransport $transport;

	private TagRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transport = new MetaPixelTransport();
		$this->registry  = new TagRegistry();

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $js ): void {
				echo '<script>' . $js . '</script>';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function inactive_consent(): ConsentMode {
		$consent = Mockery::mock( ConsentMode::class );
		$consent->shouldReceive( 'is_active' )->andReturn( false );
		$consent->shouldReceive( 'attributes' )->andReturn( array() );

		return $consent;
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

	public function test_prints_the_base_code_with_init_and_pageview(): void {
		$head = $this->emit( 'emit_primary', array( 'meta_pixel' => array( '123456789012345' ) ) );

		$this->assertStringContainsString( 'connect.facebook.net/en_US/fbevents.js', $head );
		$this->assertStringContainsString( "fbq('init', '123456789012345')", $head );
		$this->assertStringContainsString( "fbq('track', 'PageView')", $head );
	}

	public function test_prints_the_noscript_fallback_image(): void {
		$body = $this->emit( 'emit_noscript', array( 'meta_pixel' => array( '123456789012345' ) ) );

		$this->assertStringContainsString( '<noscript>', $body );
		$this->assertStringContainsString(
			'https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1',
			$body
		);
	}

	/**
	 * The literal the e2e suite pins — see GtmTransportTest for why an exact
	 * string rather than a DOM locator.
	 */
	public function test_the_noscript_image_markup_is_exact(): void {
		$this->assertSame(
			'<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1" /></noscript>' . "\n",
			$this->emit( 'emit_noscript', array( 'meta_pixel' => array( '123456789012345' ) ) )
		);
	}

	public function test_preserves_a_leading_zero_in_the_pixel_id(): void {
		$this->assertStringContainsString(
			"fbq('init', '0123456789012')",
			$this->emit( 'emit_primary', array( 'meta_pixel' => array( '0123456789012' ) ) )
		);
	}

	public function test_prints_nothing_without_a_pixel_id(): void {
		$this->assertSame( '', $this->emit( 'emit_primary', array() ) );
		$this->assertSame( '', $this->emit( 'emit_noscript', array() ) );
	}

	/**
	 * Meta's documented multi-pixel pattern: one bootstrap, one init per pixel,
	 * and a single track call that fires against every initialised pixel.
	 */
	public function test_emits_one_init_per_id_and_a_single_pageview(): void {
		$head = $this->emit(
			'emit_primary',
			array( 'meta_pixel' => array( '111111111111111', '222222222222222' ) )
		);

		$this->assertSame( 2, substr_count( $head, "fbq('init'" ) );
		$this->assertSame( 1, substr_count( $head, "fbq('track', 'PageView')" ) );
		$this->assertSame( 1, substr_count( $head, 'fbevents.js' ) );
	}
}
