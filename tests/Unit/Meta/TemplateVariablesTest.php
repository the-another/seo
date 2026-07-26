<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;

#[CoversClass( TemplateVariables::class )]
class TemplateVariablesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private TemplateVariables $variables;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->variables = new TemplateVariables();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_every_context_gets_the_base_variables(): void {
		foreach ( array( array( 'post', 'page' ), array( 'term', 'category' ), array( 'system_page', 'home' ) ) as $context ) {
			$slugs = array_keys( $this->variables->get_for( $context[0], $context[1] ) );

			foreach ( array( 'title', 'sitename', 'tagline', 'sep', 'page' ) as $base ) {
				$this->assertContains( $base, $slugs, "$base missing for {$context[0]}:{$context[1]}" );
			}
		}
	}

	public function test_posts_add_excerpt_date_and_primary_category(): void {
		$slugs = array_keys( $this->variables->get_for( 'post', 'page' ) );

		$this->assertContains( 'excerpt', $slugs );
		$this->assertContains( 'date', $slugs );
		$this->assertContains( 'primary_category', $slugs );
	}

	public function test_terms_add_excerpt_but_not_date(): void {
		$slugs = array_keys( $this->variables->get_for( 'term', 'category' ) );

		$this->assertContains( 'excerpt', $slugs );
		$this->assertNotContains( 'date', $slugs );
		$this->assertNotContains( 'primary_category', $slugs );
	}

	public function test_system_pages_get_only_the_base_set(): void {
		$this->assertSame(
			array( 'title', 'sitename', 'tagline', 'sep', 'page' ),
			array_keys( $this->variables->get_for( 'system_page', '404' ) )
		);
	}

	public function test_products_omit_price_and_sku_without_woocommerce(): void {
		$slugs = array_keys( $this->variables->get_for( 'post', 'product' ) );

		$this->assertNotContains( 'price', $slugs );
		$this->assertNotContains( 'sku', $slugs );
	}

	public function test_products_add_price_and_sku_with_woocommerce(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		$slugs = array_keys( $this->variables->get_for( 'post', 'product' ) );

		$this->assertContains( 'price', $slugs );
		$this->assertContains( 'sku', $slugs );
	}

	public function test_non_product_post_types_never_get_price_even_with_woocommerce(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		$this->assertNotContains( 'price', array_keys( $this->variables->get_for( 'post', 'page' ) ) );
	}

	public function test_filter_receives_the_context_and_can_add_a_variable(): void {
		Filters\expectApplied( 'taseo_template_variables' )
			->once()
			->andReturnUsing(
				static function ( array $variables, string $type, string $subtype ): array {
					if ( 'post' === $type && 'product' === $subtype ) {
						$variables['brand'] = 'Brand';
					}

					return $variables;
				}
			);

		$this->assertArrayHasKey( 'brand', $this->variables->get_for( 'post', 'product' ) );
	}

	public function test_filter_can_remove_a_variable(): void {
		Filters\expectApplied( 'taseo_template_variables' )->once()->andReturnUsing(
			static function ( array $variables ): array {
				unset( $variables['tagline'] );

				return $variables;
			}
		);

		$this->assertArrayNotHasKey( 'tagline', $this->variables->get_for( 'post', 'page' ) );
	}

	public function test_a_non_array_filter_return_is_ignored(): void {
		Filters\expectApplied( 'taseo_template_variables' )->once()->andReturn( 'nonsense' );

		$this->assertArrayHasKey( 'title', $this->variables->get_for( 'post', 'page' ) );
	}

	public function test_filter_entries_with_disallowed_slugs_are_dropped(): void {
		Filters\expectApplied( 'taseo_template_variables' )->once()->andReturnUsing(
			static function ( array $variables ): array {
				$variables['bad-slug']  = 'Dash';
				$variables['Bad Slug']  = 'Space';
				$variables['ok_slug']   = 'Fine';

				return $variables;
			}
		);

		$slugs = array_keys( $this->variables->get_for( 'post', 'page' ) );

		$this->assertNotContains( 'bad-slug', $slugs );
		$this->assertNotContains( 'Bad Slug', $slugs );
		$this->assertContains( 'ok_slug', $slugs );
	}

	public function test_is_available_matches_the_registry_case_insensitively(): void {
		$this->assertTrue( $this->variables->is_available( 'TITLE', 'post', 'page' ) );
		$this->assertFalse( $this->variables->is_available( 'discount', 'post', 'page' ) );
		$this->assertFalse( $this->variables->is_available( 'price', 'post', 'page' ) );
	}
}
