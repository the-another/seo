<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Verification\MethodMigration;

#[CoversClass( MethodMigration::class )]
class MethodMigrationTest extends TestCase {

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
}
