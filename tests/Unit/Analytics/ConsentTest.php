<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\Consent;

#[CoversClass( Consent::class )]
class ConsentTest extends TestCase {

	/**
	 * The analytics gate name is published API that predates the registry —
	 * a rename here silently unsubscribes every consent platform wired to it.
	 */
	public function test_analytics_keeps_the_existing_gate_name(): void {
		$this->assertSame( 'taseo_analytics_should_print', Consent::Analytics->gate() );
	}

	public function test_marketing_gates_on_the_category_not_a_vendor(): void {
		$this->assertSame( 'taseo_marketing_should_print', Consent::Marketing->gate() );
	}

	public function test_each_category_has_a_stable_slug(): void {
		$this->assertSame( 'analytics', Consent::Analytics->slug() );
		$this->assertSame( 'marketing', Consent::Marketing->slug() );
	}
}
