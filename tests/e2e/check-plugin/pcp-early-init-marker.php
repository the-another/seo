<?php
/**
 * wp-cli --require marker: proves Plugin Check's CLI runner actually
 * early-initialized. Only an early-initialized runner (object-cache.php
 * drop-in present + canonical argv) allows runtime checks at all —
 * CLI_Runner::allow_runtime_checks() returns false otherwise, and PCP then
 * SILENTLY omits runtime checks from a full run. Checking after WordPress
 * loads, from wp-cli's own hook, is what lets run-plugin-check.mjs
 * distinguish "runtime checks ran and found nothing" from "runtime checks
 * silently never ran" (the failure mode the old AJAX-based suite had —
 * see the Plugin Check gotcha in CLAUDE.md).
 *
 * @package MultiBrandGlobalStyles
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_hook(
	'after_wp_load',
	static function () {
		$GLOBALS['pcp_early_init'] =
			class_exists( 'WordPress\\Plugin_Check\\Utilities\\Plugin_Request_Utility' )
			&& null !== \WordPress\Plugin_Check\Utilities\Plugin_Request_Utility::get_runner();
	}
);

register_shutdown_function(
	static function () {
		echo "\npcp_early_init=" . ( empty( $GLOBALS['pcp_early_init'] ) ? 'no' : 'yes' ) . "\n";
	}
);
