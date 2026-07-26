<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

		// Matches CurrentContext::post_vars()'s taxonomy check for the
		// default case; tests exercising the page/product distinction
		// override this per-test.
		Functions\when( 'is_object_in_taxonomy' )->justReturn( true );

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
		// 'page' cannot carry this: it is not registered for the category
		// taxonomy, so use a subtype that genuinely is (see
		// test_pages_never_get_primary_category() below for the contrast).
		// is_object_in_taxonomy() defaults to true in setUp().
		$slugs = array_keys( $this->variables->get_for( 'post', 'post' ) );

		$this->assertContains( 'excerpt', $slugs );
		$this->assertContains( 'date', $slugs );
		$this->assertContains( 'primary_category', $slugs );
	}

	/**
	 * Corrects a bug that shipped in this test suite: this used to assert
	 * primary_category present for 'post:page', a state stock WordPress can
	 * never produce (`page` is not registered for the `category` taxonomy),
	 * which is exactly what let TemplateVariables offer a %%primary_category%%
	 * pages could never resolve. See includes/Meta/TemplateVariables.php's
	 * is_object_in_taxonomy() gate.
	 */
	public function test_pages_never_get_primary_category(): void {
		Functions\when( 'is_object_in_taxonomy' )->justReturn( false );

		$this->assertNotContains( 'primary_category', array_keys( $this->variables->get_for( 'post', 'page' ) ) );
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

	/**
	 * Runs in its own process: Functions\when( 'wc_get_product' ) makes
	 * Patchwork define a real global function for the rest of the PHP
	 * process — it can never be undefined again. Left in-process, that
	 * would permanently flip function_exists( 'wc_get_product' ) to true
	 * for every test running afterwards, including
	 * test_products_omit_price_and_sku_without_woocommerce() above, which
	 * asserts the opposite — correctness would then rest on declaration
	 * order, and phpunit.xml.dist's executionOrder="depends,defects"
	 * reorders tests after any local failure. Isolating this test confines
	 * the definition to a process that exits immediately after it.
	 */
	#[RunInSeparateProcess]
	public function test_products_add_price_and_sku_with_woocommerce(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		$slugs = array_keys( $this->variables->get_for( 'post', 'product' ) );

		$this->assertContains( 'price', $slugs );
		$this->assertContains( 'sku', $slugs );
	}

	/**
	 * Same hazard as test_products_add_price_and_sku_with_woocommerce()
	 * above: this also defines wc_get_product() in-process.
	 */
	#[RunInSeparateProcess]
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
