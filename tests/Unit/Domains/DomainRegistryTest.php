<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Domains;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;

#[CoversClass( DomainRegistry::class )]
class DomainRegistryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private DomainRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->registry = new DomainRegistry();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_normalize_host_lowercases_and_strips_scheme_port_path_and_www(): void {
		$this->assertSame( 'brandtwo.com', DomainRegistry::normalize_host( 'HTTPS://WWW.BrandTwo.com:8443/shop' ) );
		$this->assertSame( 'brandtwo.com', DomainRegistry::normalize_host( 'brandtwo.com' ) );
		$this->assertSame( 'brandtwo.com', DomainRegistry::normalize_host( '  brandtwo.com  ' ) );
		$this->assertSame( 'xn--mnchen-3ya.de', DomainRegistry::normalize_host( 'xn--mnchen-3ya.de' ) );
	}

	public function test_normalize_host_rejects_junk(): void {
		$this->assertSame( '', DomainRegistry::normalize_host( '' ) );
		$this->assertSame( '', DomainRegistry::normalize_host( 'not a host' ) );
		$this->assertSame( '', DomainRegistry::normalize_host( 'brand_two.com' ) );
		$this->assertSame( '', DomainRegistry::normalize_host( 'münchen.de' ) );
		$this->assertSame( '', DomainRegistry::normalize_host( '  brandtwo.com/a/b  ' ) );
		$this->assertSame( '', DomainRegistry::normalize_host( 'brandtwo.com:8443/a/b' ) );
	}

	public function test_default_host_comes_from_home_url(): void {
		$this->assertSame( 'example.com', DomainRegistry::default_host() );
	}

	public function test_get_hosts_returns_only_the_default_with_no_subscribers(): void {
		$this->assertSame( array( 'example.com' ), $this->registry->get_hosts() );
	}

	public function test_get_hosts_appends_filtered_hosts_normalized_and_deduped(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->once()
			->andReturn( array( 'example.com', 'WWW.BrandTwo.com', 'brandtwo.com', 'https://brandthree.co/x' ) );

		$this->assertSame(
			array( 'example.com', 'brandtwo.com', 'brandthree.co' ),
			$this->registry->get_hosts()
		);
	}

	public function test_get_hosts_pins_the_default_first_even_when_the_filter_drops_or_reorders_it(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->once()
			->andReturn( array( 'brandtwo.com', 'brandthree.co' ) );

		$this->assertSame(
			array( 'example.com', 'brandtwo.com', 'brandthree.co' ),
			$this->registry->get_hosts()
		);
	}

	public function test_get_hosts_survives_a_filter_returning_junk(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->once()
			->andReturn( 'not an array' );

		$this->assertSame( array( 'example.com' ), $this->registry->get_hosts() );
	}

	public function test_get_hosts_drops_non_string_and_unusable_entries(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->once()
			->andReturn( array( 'example.com', 42, null, 'not a host', 'brandtwo.com' ) );

		$this->assertSame( array( 'example.com', 'brandtwo.com' ), $this->registry->get_hosts() );
	}

	public function test_get_hosts_memoizes_the_filtered_list(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->once()
			->andReturn( array( 'example.com', 'brandtwo.com' ) );

		$first  = $this->registry->get_hosts();
		$second = $this->registry->get_hosts();

		$this->assertSame( $first, $second );
	}

	public function test_get_current_host_returns_a_known_host(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->andReturn( array( 'example.com', 'brandtwo.com' ) );

		$_SERVER['HTTP_HOST'] = 'www.BrandTwo.com';

		$this->assertSame( 'brandtwo.com', $this->registry->get_current_host() );
	}

	public function test_get_current_host_falls_back_to_the_default_for_an_unknown_host(): void {
		Filters\expectApplied( 'taseo_verification_domains' )
			->andReturn( array( 'example.com', 'brandtwo.com' ) );

		$_SERVER['HTTP_HOST'] = 'staging.internal';

		$this->assertSame( 'example.com', $this->registry->get_current_host() );
	}

	public function test_get_current_host_falls_back_to_the_default_when_the_header_is_absent(): void {
		$this->assertSame( 'example.com', $this->registry->get_current_host() );
	}
}
