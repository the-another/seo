<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Analytics\Transport;

use Brain\Monkey\Functions;
use Mockery;
use TheAnother\Plugin\SEO\Analytics\ConsentMode;

/**
 * Script-tag stubs that render their attributes, so a suite can assert on the
 * blocked form as well as on the snippet bytes.
 */
trait RendersScriptTags {

	private function stub_script_tags(): void {
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $js, array $attributes = array() ): void {
				echo '<script' . self::render_attributes( $attributes ) . '>' . $js . '</script>';
			}
		);
		Functions\when( 'wp_print_script_tag' )->alias(
			static function ( array $attributes = array() ): void {
				echo '<script' . self::render_attributes( $attributes ) . '></script>';
			}
		);
	}

	/**
	 * @param array<string, string> $attributes Attributes.
	 */
	private static function render_attributes( array $attributes ): string {
		$out = '';

		foreach ( $attributes as $name => $value ) {
			$out .= ' ' . $name . '="' . $value . '"';
		}

		return $out;
	}

	/**
	 * A ConsentMode that emits live, as a site with consent off does.
	 */
	private function inactive_consent(): ConsentMode {
		$consent = Mockery::mock( ConsentMode::class );
		$consent->shouldReceive( 'is_active' )->andReturn( false );
		$consent->shouldReceive( 'attributes' )->andReturn( array() );

		return $consent;
	}

	/**
	 * A ConsentMode that blocks, answering exactly as the real one does.
	 */
	private function active_consent(): ConsentMode {
		$consent = Mockery::mock( ConsentMode::class );
		$consent->shouldReceive( 'is_active' )->andReturn( true );
		$consent->shouldReceive( 'attributes' )->andReturnUsing(
			static function ( array $categories, string $group = '' ): array {
				$slugs = array();

				foreach ( $categories as $category ) {
					$slugs[] = $category->slug();
				}

				$attributes = array(
					'type'               => 'text/plain',
					'data-taseo-consent' => implode( ' ', array_unique( $slugs ) ),
				);

				if ( '' !== $group ) {
					$attributes['data-taseo-consent-group'] = $group;
				}

				return $attributes;
			}
		);

		return $consent;
	}
}
