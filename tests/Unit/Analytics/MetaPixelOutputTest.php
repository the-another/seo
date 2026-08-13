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
use TheAnother\Plugin\SEO\Analytics\MetaPixelOutput;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( MetaPixelOutput::class )]
class MetaPixelOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $domains;
	private MetaPixelOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '' )->byDefault();

		$this->domains = Mockery::mock( DomainRegistry::class );
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'example.com' )->byDefault();

		$this->output = new MetaPixelOutput( $this->settings, $this->domains );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
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

	private function head(): string {
		ob_start();
		$this->output->print_head();

		return (string) ob_get_clean();
	}

	private function body(): string {
		ob_start();
		$this->output->print_body();

		return (string) ob_get_clean();
	}

	public function test_prints_the_base_code_with_init_and_pageview(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );

		$head = $this->head();

		$this->assertStringContainsString( 'connect.facebook.net/en_US/fbevents.js', $head );
		$this->assertStringContainsString( "fbq('init', '123456789012345')", $head );
		$this->assertStringContainsString( "fbq('track', 'PageView')", $head );
	}

	public function test_prints_the_noscript_fallback_image(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );

		$body = $this->body();

		$this->assertStringContainsString( '<noscript>', $body );
		$this->assertStringContainsString(
			'https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1',
			$body
		);
	}

	public function test_preserves_a_leading_zero_in_the_pixel_id(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '0123456789012' );

		$this->assertStringContainsString( "fbq('init', '0123456789012')", $this->head() );
	}

	public function test_prints_nothing_without_a_pixel_id(): void {
		$this->assertSame( '', $this->head() );
		$this->assertSame( '', $this->body() );
	}

	public function test_emits_one_init_per_id_and_a_single_pageview(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '111111111111111' );
		Filters\expectApplied( 'taseo_meta_pixel_ids' )->once()->andReturnUsing(
			static function ( array $ids ): array {
				$ids[] = '222222222222222';

				return $ids;
			}
		);

		$head = $this->head();

		$this->assertSame( 2, substr_count( $head, "fbq('init'" ) );
		$this->assertSame( 1, substr_count( $head, "fbq('track', 'PageView')" ) );
		$this->assertSame( 1, substr_count( $head, 'fbevents.js' ) );
	}

	public function test_filter_ids_are_revalidated_and_deduplicated(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '111111111111111' );
		Filters\expectApplied( 'taseo_meta_pixel_ids' )->once()->andReturn(
			array( '111111111111111', '111111111111111', 'not-numeric', '"><script>alert(1)</script>' )
		);

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, "fbq('init'" ) );
		$this->assertStringNotContainsString( 'not-numeric', $head );
		$this->assertStringNotContainsString( 'alert(1)', $head );
	}

	public function test_prints_nothing_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );

		$this->assertSame( '', $this->head() );
	}

	public function test_meta_pixel_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );
		Filters\expectApplied( 'taseo_meta_pixel_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->head() );
	}

	public function test_tracking_should_print_filter_suppresses_output(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );
		Filters\expectApplied( 'taseo_tracking_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->head() );
	}

	public function test_marketing_consent_gate_does_not_touch_the_analytics_gate(): void {
		$this->settings->shouldReceive( 'get_meta_pixel_id' )->andReturn( '123456789012345' );
		Filters\expectApplied( 'taseo_analytics_should_print' )->never();
		Filters\expectApplied( 'taseo_meta_pixel_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->head() );
	}

	public function test_uses_the_requested_domains_pixel_id(): void {
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'brandtwo.com' );

		$this->settings->shouldReceive( 'get_meta_pixel_id' )->with( 'brandtwo.com' )->andReturn( '222222222222222' );

		ob_start();
		$this->output->print_head();

		$this->assertStringContainsString( '222222222222222', (string) ob_get_clean() );
	}
}
