<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;

#[CoversClass( SitemapFamilies::class )]
class SitemapFamiliesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'    => 'post',
				'page'    => 'page',
				'product' => 'product',
			) 
		);
		Monkey\Functions\when( 'get_taxonomies' )->justReturn(
			array(
				'category' => 'category',
				'post_tag' => 'post_tag',
			) 
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_registered_families(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_families' )
			->once()
			->andReturn( array( 'vendor_store' => 'Vendor stores' ) );

		$this->assertSame( array( 'vendor_store' => 'Vendor stores' ), ( new SitemapFamilies() )->all() );
	}

	public function test_skips_keys_with_invalid_characters(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_families' )
			->once()
			->andReturn(
				array(
					'Vendor Store' => 'Bad',
					'ok_key-1'     => 'Good',
				) 
			);

		$this->assertSame( array( 'ok_key-1' => 'Good' ), ( new SitemapFamilies() )->all() );
	}

	public function test_skips_keys_colliding_with_post_types_and_taxonomies(): void {
		Monkey\Functions\expect( '_doing_it_wrong' )
			->twice()
			->andReturn();
		Monkey\Filters\expectApplied( 'taseo_sitemap_families' )
			->once()
			->andReturn(
				array(
					'product'      => 'Collides',
					'category'     => 'Collides',
					'vendor_store' => 'Fine',
				) 
			);

		$this->assertSame( array( 'vendor_store' => 'Fine' ), ( new SitemapFamilies() )->all() );
	}

	public function test_empty_label_falls_back_to_key_and_non_scalar_is_skipped(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_families' )
			->once()
			->andReturn(
				array(
					'vendor_store' => '',
					'bad'          => array( 'x' ),
				) 
			);

		$this->assertSame( array( 'vendor_store' => 'vendor_store' ), ( new SitemapFamilies() )->all() );
	}

	public function test_non_array_filter_return_is_empty(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_families' )->once()->andReturn( 'nope' );

		$this->assertSame( array(), ( new SitemapFamilies() )->all() );
	}

	public function test_has_checks_sanitized_registry(): void {
		Monkey\Filters\expectApplied( 'taseo_sitemap_families' )
			->andReturn( array( 'vendor_store' => 'Vendor stores' ) );

		$families = new SitemapFamilies();

		$this->assertTrue( $families->has( 'vendor_store' ) );
		$this->assertFalse( $families->has( 'unknown' ) );
	}
}
