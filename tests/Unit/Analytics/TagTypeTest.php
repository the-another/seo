<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\Consent;
use TheAnother\Plugin\SEO\Analytics\ConsentMode;
use TheAnother\Plugin\SEO\Analytics\Placement;
use TheAnother\Plugin\SEO\Analytics\TagType;
use TheAnother\Plugin\SEO\Analytics\Transport\TagTransport;

#[CoversClass( TagType::class )]
class TagTypeTest extends TestCase {

	/**
	 * A TagType with the GA4 shape: uppercased, prefixed.
	 *
	 * @param bool $uppercase Normalize case.
	 * @param string $pattern Validation pattern.
	 * @return TagType Type.
	 */
	private function type( bool $uppercase = true, string $pattern = '/^G-[A-Z0-9]{4,}$/' ): TagType {
		$transport = new class() implements TagTransport {
			public function emit_primary( array $slices, ConsentMode $consent ): void {
			}

			public function emit_noscript( array $slices, ConsentMode $consent ): void {
			}
		};

		return new TagType(
			key: 'ga4',
			settings_key: 'analytics_ga4_id',
			pattern: $pattern,
			uppercase: $uppercase,
			ids_filter: 'taseo_analytics_ga4_ids',
			consent: Consent::Analytics,
			placement: Placement::ScriptQueue,
			priority: 10,
			has_noscript: false,
			transport: $transport
		);
	}

	public function test_clean_keeps_a_valid_id(): void {
		$this->assertSame( array( 'G-ABCD1234' ), $this->type()->clean( array( 'G-ABCD1234' ) ) );
	}

	public function test_clean_trims_and_uppercases_when_the_type_says_so(): void {
		$this->assertSame( array( 'G-ABCD1234' ), $this->type()->clean( array( ' g-abcd1234 ' ) ) );
	}

	/**
	 * A pixel ID's leading zero is significant, and uppercasing a numeric
	 * string is meaningless — so numeric vendors declare uppercase: false.
	 */
	public function test_clean_leaves_case_alone_when_the_type_says_so(): void {
		$type = $this->type( false, '/^[0-9]{10,20}$/' );

		$this->assertSame( array( '0123456789012' ), $type->clean( array( ' 0123456789012 ' ) ) );
	}

	public function test_clean_drops_values_failing_the_pattern(): void {
		$clean = $this->type()->clean( array( 'G-ABCD1234', 'UA-12345-1', 'not-an-id' ) );

		$this->assertSame( array( 'G-ABCD1234' ), $clean );
	}

	/**
	 * The security property: a filter is third-party code, and markup reaching
	 * <head> through it would be a script-injection path.
	 */
	public function test_clean_drops_markup(): void {
		$clean = $this->type()->clean( array( '"><script>alert(1)</script>', '<img src=x onerror=1>' ) );

		$this->assertSame( array(), $clean );
	}

	public function test_clean_deduplicates_and_reindexes(): void {
		$clean = $this->type()->clean( array( 'G-ABCD1234', 'g-abcd1234', 'G-SECOND22' ) );

		$this->assertSame( array( 'G-ABCD1234', 'G-SECOND22' ), $clean );
	}

	public function test_clean_skips_non_strings(): void {
		$clean = $this->type()->clean( array( 'G-ABCD1234', 123, null, array( 'G-NESTED1' ), true ) );

		$this->assertSame( array( 'G-ABCD1234' ), $clean );
	}

	public function test_clean_answers_an_empty_array_for_a_non_array(): void {
		$this->assertSame( array(), $this->type()->clean( 'G-ABCD1234' ) );
		$this->assertSame( array(), $this->type()->clean( null ) );
		$this->assertSame( array(), $this->type()->clean( false ) );
	}

	public function test_legacy_gate_defaults_to_empty(): void {
		$this->assertSame( '', $this->type()->legacy_gate );
	}
}
