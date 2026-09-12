<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Analytics\Placement;

#[CoversClass( Placement::class )]
class PlacementTest extends TestCase {

	public function test_each_placement_names_its_wordpress_hook(): void {
		$this->assertSame( 'wp_enqueue_scripts', Placement::ScriptQueue->hook() );
		$this->assertSame( 'wp_head', Placement::Head->hook() );
		$this->assertSame( 'wp_body_open', Placement::BodyOpen->hook() );
	}
}
