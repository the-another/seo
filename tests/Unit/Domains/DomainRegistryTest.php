<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Domains;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * The cross-plugin parity table.
	 *
	 * DomainRegistry::normalize_host() and the multi-brand plugin's
	 * UrlRuleRegistry::normalize_host() implement the same algorithm and MUST
	 * stay behaviourally identical: this plugin matches an incoming request's
	 * host against keys that plugin pushed through taseo_verification_domains,
	 * so a one-character divergence silently serves the wrong domain's
	 * verification codes.
	 *
	 * This table is the union of both repos' cases and is duplicated verbatim
	 * in the other half of the pair:
	 * the-another-multi-brand-global-styles/tests/Unit/Brand/UrlRuleRegistryTest.php
	 * (`parity_normalize_host_cases()`). Edit one, edit the other — either
	 * implementation drifting now fails its own suite.
	 *
	 * @return array<string, array{0: string, 1: string}> Input => expected.
	 */
	public static function parity_normalize_host_cases(): array {
		return array(
			'plain host'                 => array( 'example.com', 'example.com' ),
			'uppercase'                  => array( 'EXAMPLE.com', 'example.com' ),
			'leading www'                => array( 'www.example.com', 'example.com' ),
			'with port'                  => array( 'example.com:8080', 'example.com' ),
			'www and port'               => array( 'WWW.Example.com:443', 'example.com' ),
			'full https url'             => array( 'https://example.com/path', 'example.com' ),
			'full http url with www'     => array( 'http://www.example.com', 'example.com' ),
			'scheme, www, port and path' => array( 'HTTPS://WWW.BrandTwo.com:8443/shop', 'brandtwo.com' ),
			'bare brand host'            => array( 'brandtwo.com', 'brandtwo.com' ),
			'surrounding whitespace'     => array( '  example.com  ', 'example.com' ),
			'brand host padded'          => array( '  brandtwo.com  ', 'brandtwo.com' ),
			'punycode host accepted'     => array( 'xn--mnchen-3ya.de', 'xn--mnchen-3ya.de' ),
			'empty string'               => array( '', '' ),
			'scheme url with no host'    => array( 'http:///no-host-here', '' ),
			'underscore rejected'        => array( 'brand_two.com', '' ),
			'host with space rejected'   => array( 'exam ple.com', '' ),
			'not a host'                 => array( 'not a host', '' ),
			'bare host with path'        => array( 'brandtwo.com/a/b', '' ),
			'padded host with path'      => array( '  brandtwo.com/a/b  ', '' ),
			'host, port and path'        => array( 'brandtwo.com:8443/a/b', '' ),
			'unicode host rejected'      => array( 'münchen.de', '' ),
		);
	}

	#[DataProvider( 'parity_normalize_host_cases' )]
	public function test_normalize_host_matches_the_multi_brand_plugins_algorithm( string $input, string $expected ): void {
		$this->assertSame( $expected, DomainRegistry::normalize_host( $input ) );
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
