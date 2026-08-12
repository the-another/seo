<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Verification\MethodMigration;

#[CoversClass( MethodMigration::class )]
class MethodMigrationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_file_value_becomes_the_file_method_and_a_bare_token(): void {
		$result = MethodMigration::migrate(
			array(
				'verify_google'      => '',
				'verify_google_file' => 'google1a2b3c.html',
			)
		);

		$this->assertSame( '1a2b3c', $result['settings']['verify_google'] );
		$this->assertSame( 'file', $result['settings']['verify_google_method'] );
		$this->assertArrayNotHasKey( 'verify_google_file', $result['settings'] );
		$this->assertSame( array(), $result['dropped'] );
	}

	public function test_a_code_only_service_becomes_the_meta_method_untouched(): void {
		$result = MethodMigration::migrate( array( 'verify_google' => 'metatoken' ) );

		$this->assertSame( 'metatoken', $result['settings']['verify_google'] );
		$this->assertSame( 'meta', $result['settings']['verify_google_method'] );
		$this->assertArrayNotHasKey( 'verify_bing_method', $result['settings'] );
		$this->assertArrayNotHasKey( 'verify_yandex_method', $result['settings'] );
		$this->assertSame( array(), $result['dropped'] );
	}

	public function test_google_with_both_keeps_the_file_and_reports_the_dropped_code(): void {
		$result = MethodMigration::migrate(
			array(
				'verify_google'      => 'metatoken',
				'verify_google_file' => 'google1a2b3c.html',
			)
		);

		$this->assertSame( '1a2b3c', $result['settings']['verify_google'] );
		$this->assertSame( 'file', $result['settings']['verify_google_method'] );
		$this->assertSame(
			array( array( 'engine' => 'google', 'domain' => '' ) ),
			$result['dropped']
		);
	}

	public function test_bing_and_yandex_with_both_are_lossless(): void {
		$result = MethodMigration::migrate(
			array(
				'verify_bing'        => 'BINGTOKEN',
				'verify_bing_file'   => 'BINGTOKEN',
				'verify_yandex'      => 'yatoken',
				'verify_yandex_file' => 'yandex_yatoken.html',
			)
		);

		$this->assertSame( 'BINGTOKEN', $result['settings']['verify_bing'] );
		$this->assertSame( 'file', $result['settings']['verify_bing_method'] );
		$this->assertSame( 'yatoken', $result['settings']['verify_yandex'] );
		$this->assertSame( 'file', $result['settings']['verify_yandex_method'] );
		$this->assertSame( array(), $result['dropped'] );
	}

	public function test_per_domain_records_are_migrated_and_report_their_host(): void {
		$result = MethodMigration::migrate(
			array(
				'verification_domains' => array(
					'brandtwo.com' => array(
						'verify_google'      => 'metatoken',
						'verify_google_file' => 'google9z8y7x.html',
					),
				),
			)
		);

		$record = $result['settings']['verification_domains']['brandtwo.com'];

		$this->assertSame( '9z8y7x', $record['verify_google'] );
		$this->assertSame( 'file', $record['verify_google_method'] );
		$this->assertArrayNotHasKey( 'verify_google_file', $record );
		$this->assertSame(
			array( array( 'engine' => 'google', 'domain' => 'brandtwo.com' ) ),
			$result['dropped']
		);
	}

	public function test_a_malformed_file_value_falls_back_to_the_meta_method(): void {
		$result = MethodMigration::migrate(
			array(
				'verify_google'      => 'metatoken',
				'verify_google_file' => 'not-a-google-file',
			)
		);

		$this->assertSame( 'metatoken', $result['settings']['verify_google'] );
		$this->assertSame( 'meta', $result['settings']['verify_google_method'] );
		$this->assertSame( array(), $result['dropped'] );
	}

	public function test_is_idempotent(): void {
		$once  = MethodMigration::migrate(
			array(
				'verify_google'      => 'metatoken',
				'verify_google_file' => 'google1a2b3c.html',
			)
		);
		$twice = MethodMigration::migrate( $once['settings'] );

		$this->assertSame( $once['settings'], $twice['settings'] );
		$this->assertSame( array(), $twice['dropped'] );
	}

	public function test_leaves_unrelated_keys_alone(): void {
		$result = MethodMigration::migrate(
			array(
				'separator'        => '|',
				'verify_yahoo'     => 'yahootoken',
				'analytics_ga4_id' => 'G-ABC',
			)
		);

		$this->assertSame( '|', $result['settings']['separator'] );
		$this->assertSame( 'yahootoken', $result['settings']['verify_yahoo'] );
		$this->assertSame( 'G-ABC', $result['settings']['analytics_ga4_id'] );
		$this->assertArrayNotHasKey( 'verify_yahoo_method', $result['settings'] );
	}

	public function test_maybe_run_converts_the_stored_settings_and_sets_the_flag(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( MethodMigration::VERSION_OPTION, '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( Settings::OPTION_NAME, array() )
			->andReturn(
				array(
					'verify_google'      => '',
					'verify_google_file' => 'google1a2b3c.html',
				)
			);

		$written = array();

		Functions\expect( 'update_option' )
			->twice()
			->andReturnUsing(
				function ( string $option, $value ) use ( &$written ): bool {
					$written[ $option ] = $value;
					return true;
				}
			);

		( new MethodMigration() )->maybe_run();

		$this->assertSame( '1a2b3c', $written[ Settings::OPTION_NAME ]['verify_google'] );
		$this->assertSame( 'file', $written[ Settings::OPTION_NAME ]['verify_google_method'] );
		$this->assertArrayNotHasKey( 'verify_google_file', $written[ Settings::OPTION_NAME ] );
		$this->assertSame( '1', $written[ MethodMigration::VERSION_OPTION ] );
	}

	public function test_maybe_run_does_nothing_once_the_flag_is_set(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( MethodMigration::VERSION_OPTION, '' )
			->andReturn( '1' );

		Functions\expect( 'update_option' )->never();

		( new MethodMigration() )->maybe_run();
	}

	/**
	 * The flag write must survive a corrupt taseo_settings, or a site stuck
	 * with a non-array option retries the migration attempt on every single
	 * request forever instead of once. This is the one invariant that
	 * matters most about maybe_run(): the flag says the conversion attempt
	 * happened, not that it found anything to convert.
	 */
	public function test_maybe_run_sets_the_flag_even_when_the_stored_option_is_not_an_array(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( MethodMigration::VERSION_OPTION, '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( Settings::OPTION_NAME, array() )
			->andReturn( 'not-an-array' );

		$written = array();

		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				function ( string $option, $value ) use ( &$written ): bool {
					$written[ $option ] = $value;
					return true;
				}
			);

		( new MethodMigration() )->maybe_run();

		$this->assertSame( '1', $written[ MethodMigration::VERSION_OPTION ] ?? null );
		$this->assertArrayNotHasKey( Settings::OPTION_NAME, $written );
	}

	public function test_maybe_run_writes_the_dropped_notice_when_something_was_dropped(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( MethodMigration::VERSION_OPTION, '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( Settings::OPTION_NAME, array() )
			->andReturn(
				array(
					'verify_google'      => 'metatoken',
					'verify_google_file' => 'google1a2b3c.html',
				)
			);

		$written = array();

		Functions\expect( 'update_option' )
			->times( 3 )
			->andReturnUsing(
				function ( string $option, $value ) use ( &$written ): bool {
					$written[ $option ] = $value;
					return true;
				}
			);

		( new MethodMigration() )->maybe_run();

		$this->assertSame(
			array( array( 'engine' => 'google', 'domain' => '' ) ),
			$written[ MethodMigration::NOTICE_OPTION ]
		);
	}

	public function test_maybe_run_does_not_write_the_notice_when_nothing_was_dropped(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( MethodMigration::VERSION_OPTION, '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( Settings::OPTION_NAME, array() )
			->andReturn(
				array(
					'verify_google'      => '',
					'verify_google_file' => 'google1a2b3c.html',
				)
			);

		$written = array();

		Functions\expect( 'update_option' )
			->twice()
			->andReturnUsing(
				function ( string $option, $value ) use ( &$written ): bool {
					$written[ $option ] = $value;
					return true;
				}
			);

		( new MethodMigration() )->maybe_run();

		$this->assertArrayNotHasKey( MethodMigration::NOTICE_OPTION, $written );
	}
}
