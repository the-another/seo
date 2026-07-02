<?php
/**
 * Block Registration
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

/**
 * Class Blocks
 *
 * Registers the plugin's block types from their block.json metadata.
 */
class Blocks {

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register block types.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		register_block_type( THE_ANOTHER_SEO_PLUGIN_DIR . 'blocks/breadcrumbs' );
	}
}
