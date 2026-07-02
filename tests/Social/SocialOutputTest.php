<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Social;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Social\SocialOutput;

#[CoversClass( SocialOutput::class )]
class SocialOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $context;
	private $settings;
	private SocialOutput $social;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->context  = Mockery::mock( CurrentContext::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'is_open_graph_enabled' )->andReturn( true )->byDefault();
		$this->settings->shouldReceive( 'is_twitter_enabled' )->andReturn( true )->byDefault();
		$this->settings->shouldReceive( 'get_default_social_image_id' )->andReturn( 0 )->byDefault();
		$this->settings->shouldReceive( 'get_facebook_app_id' )->andReturn( '' )->byDefault();
		$this->settings->shouldReceive( 'get_twitter_site' )->andReturn( '' )->byDefault();

		$meta_output = new MetaOutput( $this->context, new TemplateResolver() );

		$this->social = new SocialOutput( $this->context, $meta_output, $this->settings );

		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Auctions' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function page_context( ?array $row = null ): array {
		return array(
			'object_type'          => 'post',
			'object_subtype'       => 'page',
			'object_id'            => 512,
			'row'                  => $row,
			'vars'                 => array( 'title' => 'About Us', 'sitename' => 'Acme Auctions', 'sep' => '–', 'excerpt' => 'Who we are.' ),
			'permalink'            => 'https://example.com/about/',
			'title_template'       => '%%title%% %%sep%% %%sitename%%',
			'description_template' => '%%excerpt%%',
		);
	}

	public function test_prints_open_graph_and_twitter_tags_from_resolved_meta(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="About Us – Acme Auctions" />', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="Who we are." />', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.com/about/" />', $html );
		$this->assertStringContainsString( '<meta property="og:site_name" content="Acme Auctions" />', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $html );
		$this->assertStringContainsString( '<meta name="twitter:title" content="About Us – Acme Auctions" />', $html );
	}

	public function test_social_overrides_win_and_image_override_used(): void {
		$row = array(
			'og_title'       => 'Custom OG Title',
			'og_description' => 'Custom OG Desc',
			'og_image_id'    => '77',
		);
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context( $row ) );
		Functions\expect( 'wp_get_attachment_image_url' )->once()->with( 77, 'full' )->andReturn( 'https://example.com/img.jpg' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'og:title" content="Custom OG Title"', $html );
		$this->assertStringContainsString( 'og:description" content="Custom OG Desc"', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.com/img.jpg" />', $html );
	}

	public function test_og_disabled_suppresses_og_but_not_twitter(): void {
		$this->settings->shouldReceive( 'is_open_graph_enabled' )->andReturn( false );
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'og:', $html );
		$this->assertStringContainsString( 'twitter:card', $html );
	}

	public function test_nothing_printed_for_unmanaged_context(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		ob_start();
		$this->social->print_tags();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_product_context_upgrades_og_type_with_price(): void {
		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'product';
		$ctx['object_id']      = 88123;
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_price' )->andReturn( '129.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:type" content="product" />', $html );
		$this->assertStringContainsString( '<meta property="product:price:amount" content="129.00" />', $html );
		$this->assertStringContainsString( '<meta property="product:price:currency" content="USD" />', $html );
		$this->assertStringContainsString( '<meta property="og:availability" content="instock" />', $html );
	}
}
