<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Verification\VerificationOutput;

#[CoversClass( VerificationOutput::class )]
class VerificationOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private VerificationOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->output   = new VerificationOutput( $this->settings );

		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_paged' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function codes( array $codes = array() ): void {
		$defaults = array(
			'google'   => '',
			'bing'     => '',
			'yandex'   => '',
			'yahoo'    => '',
			'facebook' => '',
		);

		foreach ( array_merge( $defaults, $codes ) as $engine => $code ) {
			$this->settings->shouldReceive( 'get_verification_code' )
				->with( $engine )
				->andReturn( $code );
		}
	}

	private function render(): string {
		ob_start();
		$this->output->print_tags();

		return (string) ob_get_clean();
	}

	public function test_prints_all_five_verification_tags_on_the_front_page(): void {
		$this->codes(
			array(
				'google'   => 'googletoken',
				'bing'     => 'BINGTOKEN',
				'yandex'   => 'yandextoken',
				'yahoo'    => 'yahootoken',
				'facebook' => 'metatoken',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '<meta name="google-site-verification" content="googletoken" />', $html );
		$this->assertStringContainsString( '<meta name="msvalidate.01" content="BINGTOKEN" />', $html );
		$this->assertStringContainsString( '<meta name="yandex-verification" content="yandextoken" />', $html );
		$this->assertStringContainsString( '<meta name="y_key" content="yahootoken" />', $html );
		$this->assertStringContainsString( '<meta name="facebook-domain-verification" content="metatoken" />', $html );
	}

	public function test_bing_meta_name_keeps_its_dot(): void {
		$this->codes( array( 'bing' => 'BINGTOKEN' ) );

		// Regression guard: sanitize_key() would rewrite this to msvalidate01.
		$this->assertStringContainsString( 'name="msvalidate.01"', $this->render() );
	}

	public function test_prints_nothing_when_not_the_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		$this->codes( array( 'google' => 'googletoken' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_on_a_paged_front_page(): void {
		Functions\when( 'is_paged' )->justReturn( true );
		$this->codes( array( 'google' => 'googletoken' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_prints_nothing_when_all_codes_are_empty(): void {
		$this->codes();

		$this->assertSame( '', $this->render() );
	}

	public function test_omits_engines_with_empty_codes(): void {
		$this->codes( array( 'google' => 'googletoken' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'google-site-verification', $html );
		$this->assertStringNotContainsString( 'msvalidate.01', $html );
		$this->assertStringNotContainsString( 'y_key', $html );
	}

	public function test_should_print_filter_can_suppress_output(): void {
		$this->codes( array( 'google' => 'googletoken' ) );
		Filters\expectApplied( 'taseo_verification_should_print' )->once()->andReturn( false );

		$this->assertSame( '', $this->render() );
	}

	public function test_tags_filter_can_add_a_service(): void {
		$this->codes( array( 'google' => 'googletoken' ) );
		Filters\expectApplied( 'taseo_verification_tags' )->once()->andReturnUsing(
			static function ( array $tags ): array {
				$tags['baidu-site-verification'] = 'baidutoken';

				return $tags;
			}
		);

		$this->assertStringContainsString(
			'<meta name="baidu-site-verification" content="baidutoken" />',
			$this->render()
		);
	}

	public function test_drops_filter_injected_values_containing_markup(): void {
		$this->codes();
		Filters\expectApplied( 'taseo_verification_tags' )->once()->andReturn(
			array( 'evil-verification' => '"><script>alert(1)</script>' )
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '"><', $html );
	}

	public function test_sanitize_code_extracts_content_from_a_pasted_meta_tag(): void {
		$this->assertSame(
			'AbC123_-xyz',
			VerificationOutput::sanitize_code( '<meta name="google-site-verification" content="AbC123_-xyz" />' )
		);
	}

	public function test_sanitize_code_strips_disallowed_characters(): void {
		$this->assertSame( 'abc123', VerificationOutput::sanitize_code( ' ab"c<1>2 3 ' ) );
	}
}
