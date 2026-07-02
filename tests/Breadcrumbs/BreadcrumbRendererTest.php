<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbRenderer;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( BreadcrumbRenderer::class )]
class BreadcrumbRendererTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $trail;
	private $settings;
	private BreadcrumbRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->trail    = Mockery::mock( BreadcrumbTrail::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'get_breadcrumb_separator' )->andReturn( '›' )->byDefault();
		$this->settings->shouldReceive( 'breadcrumb_link_current' )->andReturn( false )->byDefault();

		$this->renderer = new BreadcrumbRenderer( $this->trail, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_links_all_but_current_by_default(): void {
		$this->trail->shouldReceive( 'build' )->andReturn(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'Shop', 'url' => 'https://example.com/shop/' ),
				array( 'title' => 'Vintage Watch', 'url' => 'https://example.com/product/vintage-watch/' ),
			)
		);

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();

		$html = $this->renderer->render();

		$this->assertStringContainsString( '<nav class="taseo-breadcrumbs" aria-label="Breadcrumb">', $html );
		$this->assertStringContainsString( '<a href="https://example.com/">Home</a>', $html );
		$this->assertStringContainsString( '<a href="https://example.com/shop/">Shop</a>', $html );
		$this->assertStringContainsString( '<span aria-current="page">Vintage Watch</span>', $html );
		$this->assertStringNotContainsString( '<a href="https://example.com/product/vintage-watch/">', $html );
		$this->assertSame( 2, substr_count( $html, '›' ) );
	}

	public function test_render_links_current_when_setting_enabled(): void {
		$this->settings->shouldReceive( 'breadcrumb_link_current' )->andReturn( true );
		$this->trail->shouldReceive( 'build' )->andReturn(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'About', 'url' => 'https://example.com/about/' ),
			)
		);

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();

		$this->assertStringContainsString(
			'<a href="https://example.com/about/" aria-current="page">About</a>',
			$this->renderer->render()
		);
	}

	public function test_render_empty_trail_returns_empty_string(): void {
		$this->trail->shouldReceive( 'build' )->andReturn( array() );

		$this->assertSame( '', $this->renderer->render() );
	}
}
