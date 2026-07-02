<?php
/**
 * Schema Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Schema;

use TheAnother\Plugin\SEO\HookManager;

/**
 * Class SchemaOutput
 *
 * Prints the single JSON-LD @graph script.
 */
class SchemaOutput {

	/**
	 * Constructor.
	 *
	 * @param SchemaGraph $graph Graph builder.
	 */
	public function __construct( private readonly SchemaGraph $graph ) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_json_ld' ), 3 );
	}

	/**
	 * Print the JSON-LD script when the graph is non-empty.
	 *
	 * @return void
	 */
	public function print_json_ld(): void {
		$nodes = $this->graph->build();

		if ( array() === $nodes ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		);

		echo '<script type="application/ld+json">'
			. wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside script tag.
	}
}
