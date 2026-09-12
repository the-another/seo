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
use TheAnother\Plugin\SEO\Analytics\TagRegistry;
use TheAnother\Plugin\SEO\Analytics\TagResolver;
use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( TagResolver::class )]
class TagResolverTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $domains;
	private TagResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_tracking_id' )->andReturn( '' )->byDefault();

		$this->domains = Mockery::mock( DomainRegistry::class );
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'example.com' )->byDefault();

		$this->resolver = new TagResolver( new TagRegistry(), $this->settings, $this->domains );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Seed one vendor's stored ID for the default test host.
	 *
	 * @param string $settings_key Settings key.
	 * @param string $value        Stored ID.
	 * @param string $host         Host the value is stored for.
	 * @return void
	 */
	private function store( string $settings_key, string $value, string $host = 'example.com' ): void {
		$this->settings->shouldReceive( 'get_tracking_id' )
			->with( $settings_key, $host )
			->andReturn( $value );
	}

	public function test_resolves_a_stored_id(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );

		$this->assertSame( array( 'ga4' => array( 'G-ABCD1234' ) ), $this->resolver->resolve() );
	}

	public function test_an_unconfigured_site_resolves_to_nothing(): void {
		$this->assertSame( array(), $this->resolver->resolve() );
	}

	/**
	 * GA4 and Tag Manager may both be configured and both render: suppressing
	 * an ID an operator deliberately entered is more surprising than the
	 * double-count it avoids.
	 */
	public function test_ga4_and_gtm_both_resolve(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		$this->store( 'analytics_gtm_id', 'GTM-XYZ789' );

		$this->assertSame(
			array(
				'ga4' => array( 'G-ABCD1234' ),
				'gtm' => array( 'GTM-XYZ789' ),
			),
			$this->resolver->resolve()
		);
	}

	public function test_reads_the_requesting_domains_id(): void {
		$this->domains->shouldReceive( 'get_current_host' )->andReturn( 'brandtwo.com' );
		$this->store( 'analytics_ga4_id', 'G-BRANDTWO', 'brandtwo.com' );

		$this->assertSame( array( 'ga4' => array( 'G-BRANDTWO' ) ), $this->resolver->resolve() );
	}

	public function test_the_per_vendor_filter_can_append_an_id(): void {
		$this->store( 'analytics_ga4_id', 'G-PRIMARY1' );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->once()->andReturnUsing(
			static function ( array $ids ): array {
				$ids[] = 'G-SECOND22';

				return $ids;
			}
		);

		$this->assertSame(
			array( 'ga4' => array( 'G-PRIMARY1', 'G-SECOND22' ) ),
			$this->resolver->resolve()
		);
	}

	public function test_per_vendor_filter_ids_are_validated_and_deduplicated(): void {
		$this->store( 'analytics_ga4_id', 'G-PRIMARY1' );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->once()->andReturn(
			array( 'G-PRIMARY1', 'G-PRIMARY1', 'not-an-id', '"><script>' )
		);

		$this->assertSame( array( 'ga4' => array( 'G-PRIMARY1' ) ), $this->resolver->resolve() );
	}

	public function test_the_new_vendors_have_their_own_id_filters(): void {
		Filters\expectApplied( 'taseo_google_ads_ids' )->once()->andReturn( array( 'AW-123456789' ) );
		Filters\expectApplied( 'taseo_bing_uet_ids' )->once()->andReturn( array( '12345678' ) );

		$this->assertSame(
			array(
				'google_ads' => array( 'AW-123456789' ),
				'bing_uet'   => array( '12345678' ),
			),
			$this->resolver->resolve()
		);
	}

	/**
	 * The filter is handed exactly what would render — configured vendors only,
	 * never five keys with three of them empty.
	 */
	public function test_the_override_filter_receives_only_configured_vendors_and_the_host(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );

		$seen = null;
		$host = null;

		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturnUsing(
			static function ( array $tags, string $current_host ) use ( &$seen, &$host ): array {
				$seen = $tags;
				$host = $current_host;

				return $tags;
			}
		);

		$this->resolver->resolve();

		$this->assertSame( array( 'ga4' => array( 'G-ABCD1234' ) ), $seen );
		$this->assertSame( 'example.com', $host );
	}

	public function test_the_override_filter_is_handed_an_empty_array_when_nothing_is_configured(): void {
		$seen = 'untouched';

		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturnUsing(
			static function ( array $tags ) use ( &$seen ): array {
				$seen = $tags;

				return $tags;
			}
		);

		$this->resolver->resolve();

		$this->assertSame( array(), $seen );
	}

	public function test_the_override_filter_can_add_a_vendor(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturnUsing(
			static function ( array $tags ): array {
				$tags['meta_pixel'] = array( '123456789012345' );

				return $tags;
			}
		);

		$this->assertSame(
			array(
				'ga4'        => array( 'G-ABCD1234' ),
				'meta_pixel' => array( '123456789012345' ),
			),
			$this->resolver->resolve()
		);
	}

	public function test_the_override_filter_can_replace_the_collection(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array( 'ga4' => array( 'G-PARTNER1' ) )
		);

		$this->assertSame( array( 'ga4' => array( 'G-PARTNER1' ) ), $this->resolver->resolve() );
	}

	public function test_the_override_filter_can_empty_the_collection(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		$this->store( 'meta_pixel_id', '123456789012345' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn( array() );

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	/**
	 * The security property of the whole feature. A filter is third-party code:
	 * whatever it returns is validated exactly like a stored value, so no tag
	 * breakout and no script can reach the page through it.
	 */
	public function test_the_override_filter_cannot_smuggle_markup(): void {
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array(
				'ga4'        => array( '"><script>alert(1)</script>' ),
				'meta_pixel' => array( '<img src=x onerror=alert(1)>' ),
				'gtm'        => array( 'GTM-XYZ789<script>' ),
			)
		);

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	public function test_the_override_filter_cannot_add_an_undeclared_vendor(): void {
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array( 'some_other_vendor' => array( 'XYZ-123' ) )
		);

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	public function test_the_override_filter_cannot_pass_a_non_list_value(): void {
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array( 'ga4' => 'G-ABCD1234' )
		);

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	public function test_a_non_array_override_return_empties_the_collection(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn( 'nonsense' );

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	/**
	 * Rendering order is the registry's, never the filter's: an override adds,
	 * replaces or empties, but cannot reorder vendors or move a snippet.
	 */
	public function test_the_override_filter_cannot_reorder_vendors(): void {
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array(
				'bing_uet' => array( '12345678' ),
				'gtm'      => array( 'GTM-XYZ789' ),
				'ga4'      => array( 'G-ABCD1234' ),
			)
		);

		$this->assertSame(
			array( 'ga4', 'gtm', 'bing_uet' ),
			array_keys( $this->resolver->resolve() )
		);
	}

	/**
	 * The global gate means "emit nothing", so it short-circuits before any
	 * third-party code runs — no ID filters, and no override either, because
	 * nothing may add tags back through it.
	 */
	public function test_the_global_gate_suppresses_everything_and_runs_no_other_filter(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		Filters\expectApplied( 'taseo_tracking_should_print' )->once()->andReturn( false );
		Filters\expectApplied( 'taseo_analytics_ga4_ids' )->never();
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->never();
		Filters\expectApplied( 'taseo_analytics_should_print' )->never();

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	public function test_admin_requests_resolve_to_nothing(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	public function test_customize_preview_resolves_to_nothing(): void {
		Functions\when( 'is_customize_preview' )->justReturn( true );
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	public function test_the_analytics_gate_empties_analytics_and_leaves_marketing(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		$this->store( 'analytics_gtm_id', 'GTM-XYZ789' );
		$this->store( 'meta_pixel_id', '123456789012345' );
		Filters\expectApplied( 'taseo_analytics_should_print' )->once()->andReturn( false );

		$this->assertSame(
			array( 'meta_pixel' => array( '123456789012345' ) ),
			$this->resolver->resolve()
		);
	}

	public function test_the_marketing_gate_empties_marketing_and_leaves_analytics(): void {
		$this->store( 'analytics_ga4_id', 'G-ABCD1234' );
		$this->store( 'meta_pixel_id', '123456789012345' );
		$this->store( 'google_ads_id', 'AW-123456789' );
		$this->store( 'bing_uet_id', '12345678' );
		Filters\expectApplied( 'taseo_marketing_should_print' )->once()->andReturn( false );

		$this->assertSame( array( 'ga4' => array( 'G-ABCD1234' ) ), $this->resolver->resolve() );
	}

	/**
	 * A category gate is asked only when that category has something to gate,
	 * so a site running no marketing tags never calls a marketing consent
	 * callback — and vice versa.
	 */
	public function test_a_category_gate_is_not_applied_without_a_candidate(): void {
		$this->store( 'meta_pixel_id', '123456789012345' );
		Filters\expectApplied( 'taseo_analytics_should_print' )->never();
		Filters\expectApplied( 'taseo_marketing_should_print' )->once()->andReturn( true );

		$this->assertSame(
			array( 'meta_pixel' => array( '123456789012345' ) ),
			$this->resolver->resolve()
		);
	}

	public function test_one_category_gate_covers_every_vendor_in_it(): void {
		$this->store( 'meta_pixel_id', '123456789012345' );
		$this->store( 'google_ads_id', 'AW-123456789' );
		Filters\expectApplied( 'taseo_marketing_should_print' )->once()->andReturn( true );

		$this->assertSame(
			array(
				'meta_pixel' => array( '123456789012345' ),
				'google_ads' => array( 'AW-123456789' ),
			),
			$this->resolver->resolve()
		);
	}

	/**
	 * The retained per-vendor gate still suppresses the pixel — and only the
	 * pixel, not its category.
	 */
	public function test_the_meta_pixel_gate_suppresses_the_pixel_alone(): void {
		$this->store( 'meta_pixel_id', '123456789012345' );
		$this->store( 'google_ads_id', 'AW-123456789' );
		Filters\expectApplied( 'taseo_meta_pixel_should_print' )->once()->andReturn( false );

		$this->assertSame(
			array( 'google_ads' => array( 'AW-123456789' ) ),
			$this->resolver->resolve()
		);
	}

	/**
	 * Consent runs after the override, so an override cannot re-add a
	 * marketing tag into a request where marketing consent was refused.
	 */
	public function test_consent_wins_over_the_override_filter(): void {
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once()->andReturn(
			array( 'meta_pixel' => array( '123456789012345' ) )
		);
		Filters\expectApplied( 'taseo_marketing_should_print' )->once()->andReturn( false );

		$this->assertSame( array(), $this->resolver->resolve() );
	}

	/**
	 * Resolving once per request is what stops a head snippet and its
	 * <noscript> half from disagreeing when a subscriber is non-deterministic.
	 */
	public function test_resolution_is_memoized_for_the_request(): void {
		$this->store( 'analytics_gtm_id', 'GTM-XYZ789' );
		Filters\expectApplied( 'taseo_tracking_should_print' )->once();
		Filters\expectApplied( 'taseo_analytics_gtm_ids' )->once();
		Filters\expectApplied( 'taseo_tracking_tag_ids' )->once();

		$first  = $this->resolver->resolve();
		$second = $this->resolver->resolve();

		$this->assertSame( $first, $second );
		$this->assertSame( array( 'gtm' => array( 'GTM-XYZ789' ) ), $first );
	}

	public function test_an_empty_resolution_is_memoized_too(): void {
		Filters\expectApplied( 'taseo_tracking_should_print' )->once()->andReturn( false );

		$this->assertSame( array(), $this->resolver->resolve() );
		$this->assertSame( array(), $this->resolver->resolve() );
	}
}
