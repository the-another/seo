<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\ImageResolver;

#[CoversClass( ImageResolver::class )]
class ImageResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_attachment_url_returns_the_resolved_url(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/img.jpg' );

		$this->assertSame( 'https://example.com/img.jpg', ImageResolver::attachment_url( 42 ) );
	}

	public function test_attachment_url_asks_for_the_full_size(): void {
		$seen = array();

		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( $id, $size ) use ( &$seen ): string {
				$seen = array( $id, $size );

				return 'https://example.com/img.jpg';
			}
		);

		ImageResolver::attachment_url( 42 );

		$this->assertSame( array( 42, 'full' ), $seen );
	}

	public function test_attachment_url_is_empty_for_a_missing_id(): void {
		$this->assertSame( '', ImageResolver::attachment_url( 0 ) );
		$this->assertSame( '', ImageResolver::attachment_url( -1 ) );
	}

	/**
	 * A deleted attachment leaves its ID behind in the row. WordPress returns
	 * false, which must become '' so the caller falls through to the next
	 * candidate rather than emitting content="".
	 */
	public function test_attachment_url_is_empty_when_the_attachment_is_gone(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		$this->assertSame( '', ImageResolver::attachment_url( 42 ) );
	}

	public function test_first_returns_the_first_non_empty_candidate(): void {
		$this->assertSame(
			'https://example.com/second.jpg',
			ImageResolver::first( array( '', 'https://example.com/second.jpg', 'https://example.com/third.jpg' ) )
		);
	}

	public function test_first_is_empty_when_every_candidate_is_empty(): void {
		$this->assertSame( '', ImageResolver::first( array( '', '', '' ) ) );
		$this->assertSame( '', ImageResolver::first( array() ) );
	}
}
