<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\ImageField;

#[CoversClass( ImageField::class )]
class ImageFieldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/thumb.jpg' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( int $id_value = 0, string $url_value = '' ): string {
		ob_start();
		ImageField::render(
			'taseo_settings[default_social_image_id]',
			$id_value,
			'taseo_settings[default_social_image_url]',
			$url_value,
			'taseo-default-social-image'
		);

		return (string) ob_get_clean();
	}

	/**
	 * The ID travels in a hidden input under its original field name, so the
	 * form submits exactly what it submitted before and every existing
	 * sanitizer keeps working untouched.
	 */
	public function test_the_attachment_id_is_submitted_under_its_original_name(): void {
		$html = $this->render( 42 );

		$this->assertStringContainsString( 'type="hidden"', $html );
		$this->assertStringContainsString( 'name="taseo_settings[default_social_image_id]"', $html );
		$this->assertStringContainsString( 'value="42"', $html );
	}

	public function test_it_renders_select_and_remove_buttons_using_core_classes(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-taseo-image-select', $html );
		$this->assertStringContainsString( 'data-taseo-image-remove', $html );
		$this->assertStringContainsString( 'class="button"', $html );
	}

	public function test_it_renders_a_labelled_url_override_input(): void {
		$html = $this->render( 0, 'https://cdn.example.com/social.jpg' );

		$this->assertStringContainsString( 'name="taseo_settings[default_social_image_url]"', $html );
		$this->assertStringContainsString( 'id="taseo-default-social-image-url"', $html );
		$this->assertStringContainsString( 'for="taseo-default-social-image-url"', $html );
		$this->assertStringContainsString( 'https://cdn.example.com/social.jpg', $html );
	}

	public function test_a_preview_renders_only_when_an_attachment_is_set(): void {
		$this->assertStringNotContainsString( 'data-taseo-image-preview', $this->render( 0 ) );
		$this->assertStringContainsString( 'data-taseo-image-preview', $this->render( 42 ) );
	}

	/**
	 * The wrapper is how the picker script finds its fields; without it the
	 * markup renders but nothing binds to it.
	 */
	public function test_the_wrapper_carries_the_hook_the_script_binds_to(): void {
		$this->assertStringContainsString( 'data-taseo-image-field', $this->render() );
	}

	/**
	 * No stylesheet ships with this plugin, so every class here must be one
	 * WordPress already defines. `data-taseo-image-*` attributes are the
	 * script's binding hooks and are fine; a class of our own is not, which
	 * is why this asserts on `class="taseo` rather than on the bare string
	 * `taseo-image` — the latter appears in every data attribute and would
	 * fail against correct markup.
	 */
	public function test_it_introduces_no_class_of_our_own(): void {
		$this->assertStringNotContainsString( 'class="taseo', $this->render( 42 ) );
	}
}
