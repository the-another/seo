<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\CustomPages;

#[CoversClass( CustomPages::class )]
class CustomPagesTest extends TestCase {

	private CustomPages $pages;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pages = new CustomPages();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_returns_the_registered_pages(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn(
			array(
				'checkout' => 'Checkout',
				'account'  => 'My account',
			)
		);

		$this->assertSame(
			array(
				'checkout' => 'Checkout',
				'account'  => 'My account',
			),
			$this->pages->all()
		);
	}

	public function test_all_is_empty_when_nothing_is_registered(): void {
		$this->assertSame( array(), $this->pages->all() );
	}

	/**
	 * A key becomes a settings array key and part of an HTML id, so it is
	 * restricted. It is SKIPPED rather than rewritten: a rewritten key would
	 * leave the registering plugin's taseo_custom_page_context filter naming
	 * a key that no longer exists, which fails silently instead of visibly.
	 */
	public function test_a_key_with_invalid_characters_is_skipped_not_rewritten(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn(
			array(
				'checkout'   => 'Checkout',
				'Check Out'  => 'Spaces and capitals',
				'has:colon'  => 'Colon collides with the row key separator',
				'has/slash'  => 'Slash',
			)
		);

		$all = $this->pages->all();

		$this->assertSame( array( 'checkout' => 'Checkout' ), $all );
		$this->assertArrayNotHasKey( 'check-out', $all );
		$this->assertArrayNotHasKey( 'checkout2', $all );
	}

	public function test_a_non_array_return_yields_no_pages(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn( 'nonsense' );

		$this->assertSame( array(), $this->pages->all() );
	}

	public function test_an_empty_label_falls_back_to_the_key(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn( array( 'checkout' => '' ) );

		$this->assertSame( array( 'checkout' => 'checkout' ), $this->pages->all() );
	}

	/**
	 * A label is cast to string for display on the settings screen. Without
	 * an is_scalar() guard, an object label fatals that screen instead of
	 * being skipped like an entry with an invalid key already is.
	 */
	public function test_a_non_scalar_label_is_skipped_not_cast(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn(
			array(
				'checkout' => 'Checkout',
				'object'   => new \stdClass(),
				'array'    => array( 'nested' => true ),
			)
		);

		$this->assertSame( array( 'checkout' => 'Checkout' ), $this->pages->all() );
	}

	public function test_has_is_true_only_for_a_registered_key(): void {
		Filters\expectApplied( 'taseo_custom_pages' )->andReturn( array( 'checkout' => 'Checkout' ) );

		$this->assertTrue( $this->pages->has( 'checkout' ) );
		$this->assertFalse( $this->pages->has( 'account' ) );
	}
}
