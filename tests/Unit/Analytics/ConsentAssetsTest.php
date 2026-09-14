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

		// Honours the flags, because what they escape is part of what these
		// tests assert. Every assertion below reads the config back through
		// printed_config() rather than matching raw JSON, so hex-escaped
		// output does not make them unreadable.
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $options = 0 ) => json_encode( $data, $options ) );
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

	/**
	 * The configuration the printed script assigned, decoded.
	 *
	 * @param string $out Printed markup.
	 * @return array<string, mixed> Configuration.
	 */
	private function printed_config( string $out ): array {
		preg_match( '/window\.taseoConsentConfig=(.+);BOOT_SOURCE/', $out, $matches );

		$decoded = json_decode( (string) ( $matches[1] ?? '' ), true );

		$this->assertIsArray( $decoded, 'No decodable config was printed.' );

		return $decoded;
	}

	/**
	 * Put a built UI asset file where ui_version() reads one.
	 *
	 * dist/ is build output, so neither its presence nor its absence can be
	 * assumed — THE_ANOTHER_SEO_PLUGIN_DIR is the throwaway scratch directory
	 * tests/Unit/bootstrap.php creates, and the file is removed in tearDown().
	 *
	 * @param string $version Version to record.
	 * @return void
	 */
	private function write_ui_asset_file( string $version ): void {
		$dir = THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/consent';

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		$file = $dir . '/index.asset.php';

		file_put_contents( $file, "<?php return array( 'dependencies' => array(), 'version' => '" . $version . "' );" );

		$this->temp[] = $file;
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

		$config = $this->printed_config( $out );

		$this->assertStringContainsString( 'id="taseo-consent-boot"', $out );
		$this->assertStringContainsString( 'BOOT_SOURCE', $out );
		$this->assertSame( array( 'analytics' ), $config['categories'] );
		$this->assertSame( 180, $config['lifetimeDays'] );
	}

	public function test_a_filter_can_replace_the_config(): void {
		Filters\expectApplied( 'taseo_consent_config' )->once()->andReturn( array( 'categories' => array( 'marketing' ) ) );

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$this->assertSame( array( 'marketing' ), $this->printed_config( (string) ob_get_clean() )['categories'] );
	}

	public function test_a_partial_filtered_config_still_carries_what_the_browser_needs(): void {
		// The natural way to write this filter, and the shape that used to
		// hand the boot script an undefined lifetime — which store.js compared
		// against NaN, making every stored decision immortal — and an
		// undefined UI URL, which became <script src="undefined">.
		Filters\expectApplied( 'taseo_consent_config' )->once()->andReturn( array( 'categories' => array( 'marketing' ) ) );

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$config = $this->printed_config( (string) ob_get_clean() );

		$this->assertSame( 180, $config['lifetimeDays'] );
		$this->assertStringEndsWith( 'dist/consent/index.js', $config['uiUrl'] );
		$this->assertSame( '', $config['crawlers'] );
	}

	public function test_a_filtered_lifetime_that_would_never_expire_is_clamped(): void {
		Filters\expectApplied( 'taseo_consent_config' )->once()->andReturn( array( 'lifetimeDays' => 0 ) );

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$this->assertSame( 1, $this->printed_config( (string) ob_get_clean() )['lifetimeDays'] );
	}

	public function test_a_filtered_config_that_is_not_an_array_is_replaced(): void {
		Filters\expectApplied( 'taseo_consent_config' )->once()->andReturn( 'nonsense' );

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$config = $this->printed_config( (string) ob_get_clean() );

		$this->assertSame( array(), $config['categories'] );
		$this->assertSame( 180, $config['lifetimeDays'] );
	}

	public function test_the_ui_url_carries_the_built_bundle_version(): void {
		$this->write_ui_asset_file( 'abc123def456' );

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$this->assertSame(
			THE_ANOTHER_SEO_PLUGIN_URL . 'dist/consent/index.js?ver=abc123def456',
			$this->printed_config( (string) ob_get_clean() )['uiUrl']
		);
	}

	public function test_an_unbuilt_ui_bundle_degrades_to_an_unversioned_url(): void {
		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();

		$this->assertSame(
			THE_ANOTHER_SEO_PLUGIN_URL . 'dist/consent/index.js',
			$this->printed_config( (string) ob_get_clean() )['uiUrl']
		);
	}

	public function test_the_inlined_config_cannot_move_the_html_tokenizer(): void {
		// Not a breakout — wp_json_encode() escapes '/' and there is no way to
		// close this tag — but '<!--<script>' moves the tokenizer into its
		// double-escaped state and changes where the parser thinks the script
		// ends. Reachable through this filter or a hostile translation.
		Filters\expectApplied( 'taseo_consent_config' )->once()->andReturn(
			array( 'copy' => array( 'title' => '<!--<script>' ) )
		);

		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<!--', $out );
		$this->assertSame( '<!--<script>', $this->printed_config( $out )['copy']['title'] );
	}

	public function test_nothing_is_printed_when_the_bundle_is_missing(): void {
		$assets = $this->assets( true, '/nonexistent/index.js' );

		ob_start();
		$assets->print_boot();

		$this->assertSame( '', (string) ob_get_clean() );
	}
	public function test_the_config_discloses_the_strictly_necessary_group(): void {
		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();
		$copy = $this->printed_config( (string) ob_get_clean() )['copy'];

		$this->assertArrayHasKey( 'necessary', $copy );
		$this->assertNotSame( '', $copy['necessary']['label'] );
		$this->assertNotSame( '', $copy['necessary']['state'] );
		$this->assertNotSame( '', $copy['necessary']['body'] );
	}

	public function test_the_config_predefines_copy_for_the_functional_group(): void {
		$assets = $this->assets( true );

		ob_start();
		$assets->print_boot();
		$copy = $this->printed_config( (string) ob_get_clean() )['copy'];

		$this->assertArrayHasKey( 'functional', $copy['categories'] );
		$this->assertNotSame( '', $copy['categories']['functional']['label'] );
	}

}
