<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\Metabox;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( Metabox::class )]
class MetaboxTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private Metabox $metabox;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->settings   = Mockery::mock( Settings::class );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$this->metabox = new Metabox( $this->repository, $this->settings );
	}

	protected function tearDown(): void {
		unset( $_POST['taseo_meta'], $_POST['taseo_meta_nonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_submission_maps_fields_with_correct_sanitizers(): void {
		$clean = $this->metabox->sanitize_submission(
			array(
				'title'            => 'Custom Title',
				'description'      => "Multi line\ndescription",
				'canonical_url'    => 'https://example.com/canonical/',
				'og_image_id'      => '77',
				'robots_noindex'   => '1',
				'schema_disabled'  => '1',
				'breadcrumb_title' => 'Short',
				'unknown_field'    => 'dropped',
			)
		);

		$this->assertSame( 'Custom Title', $clean['title'] );
		$this->assertSame( "Multi line\ndescription", $clean['description'] );
		$this->assertSame( 'https://example.com/canonical/', $clean['canonical_url'] );
		$this->assertSame( 77, $clean['og_image_id'] );
		$this->assertSame( '1', $clean['robots_noindex'] );
		$this->assertSame( '1', $clean['schema_disabled'] );
		$this->assertSame( 'Short', $clean['breadcrumb_title'] );
		$this->assertArrayNotHasKey( 'unknown_field', $clean );
	}

	public function test_sanitize_submission_unchecked_boxes_and_blanks_become_empty_string(): void {
		$clean = $this->metabox->sanitize_submission( array( 'title' => '' ) );

		$this->assertSame( '', $clean['title'] );
		$this->assertSame( '', $clean['robots_noindex'] ); // absent checkbox.
		$this->assertSame( '', $clean['og_image_id'] );    // absent ID.
	}

	public function test_handle_save_post_persists_overrides_on_valid_request(): void {
		$_POST['taseo_meta_nonce'] = 'nonce-value';
		$_POST['taseo_meta']       = array( 'title' => 'Custom' );

		Functions\expect( 'wp_verify_nonce' )->once()->with( 'nonce-value', 'taseo_save_meta' )->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 88123 )->andReturn( true );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();

		$post            = Mockery::mock( 'WP_Post' );
		$post->post_type = 'product';
		Functions\when( 'get_post' )->justReturn( $post );

		$this->repository->shouldReceive( 'save_overrides' )
			->once()
			->with( 'post', 'product', 88123, Mockery::on( fn( array $o ): bool => 'Custom' === $o['title'] ) );

		$this->metabox->handle_save_post( 88123 );
	}

	public function test_handle_save_post_bails_without_nonce(): void {
		$this->repository->shouldNotReceive( 'save_overrides' );

		$this->metabox->handle_save_post( 88123 );
	}

	public function test_handle_save_post_bails_when_nonce_invalid(): void {
		$_POST['taseo_meta_nonce'] = 'bad';
		$_POST['taseo_meta']       = array( 'title' => 'X' );

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( false );

		$this->repository->shouldNotReceive( 'save_overrides' );

		$this->metabox->handle_save_post( 88123 );
	}

	public function test_handle_save_term_persists_with_taxonomy_capability(): void {
		$_POST['taseo_meta_nonce'] = 'nonce-value';
		$_POST['taseo_meta']       = array( 'title' => 'Term Title' );

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_categories' )->andReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->repository->shouldReceive( 'save_overrides' )
			->once()
			->with( 'term', 'product_cat', 44, Mockery::type( 'array' ) );

		$this->metabox->handle_save_term( 44, 99, 'product_cat' );
	}

	public function test_sanitize_runs_image_url_overrides_through_esc_url_raw(): void {
		$seen = array();

		Functions\when( 'esc_url_raw' )->alias(
			static function ( $value ) use ( &$seen ): string {
				$seen[] = $value;

				return $value;
			}
		);

		$clean = $this->metabox->sanitize_submission(
			array(
				'og_image_url'      => 'https://cdn.example.com/og.jpg',
				'twitter_image_url' => 'https://cdn.example.com/tw.jpg',
			)
		);

		$this->assertSame( 'https://cdn.example.com/og.jpg', $clean['og_image_url'] );
		$this->assertSame( 'https://cdn.example.com/tw.jpg', $clean['twitter_image_url'] );
		$this->assertContains( 'https://cdn.example.com/og.jpg', $seen );
	}

	/**
	 * Both image slots previously fell through to the plain-text branch and
	 * rendered as bare boxes labelled "Og Image Id".
	 */
	public function test_metabox_renders_image_slots_as_pickers(): void {
		$html = $this->render_metabox_fields( array( 'og_image_id' => '42' ) );

		$this->assertStringContainsString( 'data-taseo-image-field', $html );
		$this->assertStringContainsString( 'name="taseo_meta[og_image_url]"', $html );
	}

	/**
	 * The URL override must appear exactly once — ImageField renders it, so
	 * the field loop must not render it a second time.
	 */
	public function test_the_url_override_is_not_rendered_twice(): void {
		$html = $this->render_metabox_fields( array() );

		$this->assertSame( 1, substr_count( $html, 'name="taseo_meta[og_image_url]"' ) );
	}

	/**
	 * Render Metabox's private render_fields() via reflection and return its
	 * markup.
	 *
	 * esc_html() is deliberately not stubbed here: it is a real function
	 * defined directly in tests/Unit/bootstrap.php rather than through a
	 * Patchwork-wrapped include, so Brain\Monkey cannot redefine it (see the
	 * same note on SettingsPageTest::stub_render_functions()). Its real
	 * htmlspecialchars() implementation is a no-op on the plain field labels
	 * these tests render, so none is needed.
	 *
	 * @param array<string, mixed> $row Indexable row.
	 * @return string Rendered markup.
	 */
	private function render_metabox_fields( array $row ): string {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/thumb.jpg' );

		$method = new \ReflectionMethod( Metabox::class, 'render_fields' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->metabox, $row );

		return (string) ob_get_clean();
	}
}
