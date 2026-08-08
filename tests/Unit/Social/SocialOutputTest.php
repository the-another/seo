<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Social;

use Brain\Monkey;
use Brain\Monkey\Filters;
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
		$this->settings->shouldReceive( 'get_default_social_image_url' )->andReturn( '' )->byDefault();
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

	private function render_social( array $row ): string {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context( $row ) );

		ob_start();
		$this->social->print_tags();

		return (string) ob_get_clean();
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
		$ctx['post_type']      = 'product';
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

	public function test_twitter_disabled_suppresses_twitter_but_not_og(): void {
		$this->settings->shouldReceive( 'is_twitter_enabled' )->andReturn( false );
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'og:title', $html );
		$this->assertStringNotContainsString( 'twitter:', $html );
	}

	public function test_out_of_stock_product_reports_oos_availability(): void {
		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'product';
		$ctx['post_type']      = 'product';
		$ctx['object_id']      = 88456;
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_price' )->andReturn( '79.99' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( false );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:availability" content="oos" />', $html );
	}

	public function test_twitter_overrides_and_twitter_image_win(): void {
		$row = array(
			'twitter_title'       => 'TW Title',
			'twitter_description' => 'TW Desc',
			'twitter_image_id'    => '88',
		);
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context( $row ) );
		Functions\expect( 'wp_get_attachment_image_url' )->once()->with( 88, 'full' )->andReturn( 'https://example.com/tw.jpg' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'twitter:title" content="TW Title"', $html );
		$this->assertStringContainsString( 'twitter:description" content="TW Desc"', $html );
		$this->assertStringContainsString( '<meta name="twitter:image" content="https://example.com/tw.jpg" />', $html );
	}

	public function test_site_default_image_used_when_no_override(): void {
		$this->settings->shouldReceive( 'get_default_social_image_id' )->andReturn( 55 );
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );
		Functions\expect( 'wp_get_attachment_image_url' )->atLeast()->once()->with( 55, 'full' )->andReturn( 'https://example.com/default.jpg' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'og:image" content="https://example.com/default.jpg"', $html );
	}

	public function test_a_row_image_url_beats_the_row_attachment(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/attachment.jpg' );

		$html = $this->render_social(
			array(
				'og_image_url' => 'https://cdn.example.com/override.jpg',
				'og_image_id'  => 42,
			)
		);

		$this->assertStringContainsString( 'https://cdn.example.com/override.jpg', $html );
		$this->assertStringNotContainsString( 'https://example.com/attachment.jpg', $html );
	}

	public function test_the_sitewide_url_is_used_when_the_row_has_nothing(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );
		$this->settings->shouldReceive( 'get_default_social_image_url' )->andReturn( 'https://cdn.example.com/site.jpg' );

		$html = $this->render_social( array() );

		$this->assertStringContainsString( 'https://cdn.example.com/site.jpg', $html );
	}

	/**
	 * The settings-level counterpart of test_a_row_image_url_beats_the_row_attachment():
	 * both default_social_image_url and default_social_image_id resolve to a
	 * real value here (the ID is not 0), so the URL only wins if resolve_image_url()
	 * checks it first — swapping those two candidates would slip past every
	 * other test in this file, since they leave the ID at its default 0.
	 */
	public function test_the_sitewide_url_beats_the_sitewide_attachment(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/attachment-fallback.jpg' );
		$this->settings->shouldReceive( 'get_default_social_image_url' )->andReturn( 'https://cdn.example.com/site.jpg' );
		$this->settings->shouldReceive( 'get_default_social_image_id' )->andReturn( 55 );

		$html = $this->render_social( array() );

		$this->assertStringContainsString( 'https://cdn.example.com/site.jpg', $html );
		$this->assertStringNotContainsString( 'https://example.com/attachment-fallback.jpg', $html );
	}

	/**
	 * The filter is applied last, so add_filter is always the final word.
	 */
	public function test_the_og_image_filter_wins_over_every_stored_value(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->andReturn( 'https://filtered.example.com/og.jpg' );

		$html = $this->render_social( array( 'og_image_url' => 'https://cdn.example.com/override.jpg' ) );

		$this->assertStringContainsString( 'https://filtered.example.com/og.jpg', $html );
	}

	public function test_an_empty_filter_return_suppresses_the_og_tag(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->once()->andReturn( '' );

		$html = $this->render_social( array( 'og_image_url' => 'https://cdn.example.com/override.jpg' ) );

		$this->assertStringNotContainsString( 'og:image', $html );
	}

	/**
	 * Twitter falls back to the OG image, and the value it inherits has
	 * already been through taseo_og_image_url. So a plugin that rewrites the
	 * OG image moves the Twitter image with it unless Twitter is set
	 * separately — an ordering rule, pinned here rather than left to
	 * inference.
	 */
	public function test_twitter_inherits_the_filtered_og_url(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->andReturn( 'https://filtered.example.com/og.jpg' );

		$html = $this->render_social( array() );

		$this->assertStringContainsString(
			'<meta name="twitter:image" content="https://filtered.example.com/og.jpg" />',
			$html
		);
	}

	/**
	 * A filter returning null must not emit content="" or fatal; the (string)
	 * cast turns it into '', which suppresses the tag like any other empty
	 * result.
	 */
	public function test_a_filter_returning_null_suppresses_the_tag(): void {
		Filters\expectApplied( 'taseo_og_image_url' )->once()->andReturn( null );

		$html = $this->render_social( array( 'og_image_url' => 'https://cdn.example.com/override.jpg' ) );

		$this->assertStringNotContainsString( 'og:image', $html );
	}

	public function test_a_row_twitter_url_beats_the_og_fallback(): void {
		$html = $this->render_social(
			array(
				'og_image_url'      => 'https://cdn.example.com/og.jpg',
				'twitter_image_url' => 'https://cdn.example.com/tw.jpg',
			)
		);

		$this->assertStringContainsString(
			'<meta name="twitter:image" content="https://cdn.example.com/tw.jpg" />',
			$html
		);
	}
}
