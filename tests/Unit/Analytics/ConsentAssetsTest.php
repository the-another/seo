<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\ConsentAssets;
use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( ConsentAssets::class )]
class ConsentAssetsTest extends TestCase {

	/**
	 * Temp bundle files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $temp = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->temp = array();

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $js, array $attributes = array() ): void {
				$rendered = '';

				foreach ( $attributes as $name => $value ) {
					$rendered .= ' ' . $name . '="' . $value . '"';
				}

				echo '<script' . $rendered . '>' . $js . '</script>';
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->temp as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function assets( bool $active, string $path = '' ): ConsentAssets {
		$consent = Mockery::mock( ConsentMode::class );
		$consent->shouldReceive( 'is_active' )->andReturn( $active );
		$consent->shouldReceive( 'categories' )->andReturn( array( 'analytics' ) );

		$settings = Mockery::mock( Settings::class );
		$settings->shouldReceive( 'get_consent_lifetime_days' )->andReturn( 180 );
		$settings->shouldReceive( 'get_consent_policy_url' )->andReturn( 'https://example.test/privacy' );

		$domains = Mockery::mock( DomainRegistry::class );
		$domains->shouldReceive( 'get_current_host' )->andReturn( 'example.test' );

		if ( '' === $path ) {
			$path = (string) tempnam( sys_get_temp_dir(), 'taseo-boot' );
			file_put_contents( $path, 'BOOT_SOURCE' );
			$this->temp[] = $path;
		}

		return new ConsentAssets( $consent, $settings, $domains, $path );
	}

	public function test_nothing_is_printed_while_consent_mode_is_inactive(): void {
		$assets = $this->assets( false );

		ob_start();
		$assets->print_boot();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_boot_script_carries_the_config_and_the_bundle(): void {
		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="taseo-consent-boot"', $out );
		$this->assertStringContainsString( '"categories":["analytics"]', $out );
		$this->assertStringContainsString( '"lifetimeDays":180', $out );
		$this->assertStringContainsString( 'BOOT_SOURCE', $out );
	}

	public function test_a_filter_can_replace_the_config(): void {
		Filters\expectApplied( 'taseo_consent_config' )->once()->andReturn( array( 'categories' => array( 'marketing' ) ) );

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$this->assertStringContainsString( '"categories":["marketing"]', (string) ob_get_clean() );
	}

	public function test_nothing_is_printed_when_the_bundle_is_missing(): void {
		$assets = $this->assets( true, '/nonexistent/index.js' );

		ob_start();
		$assets->print_boot();

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
